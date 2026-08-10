<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Contracts\VirusScanner;
use Heisenberg\Models\PublicFile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * All media-library business logic (docs/media-library-backend-blueprint.md §2,
 * §5, §6, §8): store / paginate / updateMeta / delete, plus the internal upload
 * pipeline helpers (dimensions, collision-free naming, variants, virus scan
 * enforcement). The controller stays a thin HTTP adapter; nothing here knows
 * about requests or responses (blueprint §12 layering invariant).
 */
class MediaLibraryService
{
    /** Sane upper bound for a sanitized base name (blueprint §3.2). */
    private const MAX_BASE_NAME_LENGTH = 200;

    public function __construct(private VirusScanner $scanner)
    {
    }

    /**
     * Store one or more uploaded files. Every file in the batch shares the same
     * bilingual meta payload (alt/caption/credit), matching the single-or-multi
     * upload request shape (blueprint §5, §8).
     *
     * @param UploadedFile[] $files
     * @param array<string, mixed> $meta
     * @return PublicFile[]
     */
    public function store(array $files, array $meta = [], ?int $uploadedBy = null): array
    {
        $created = [];

        foreach ($files as $file) {
            $created[] = $this->storeOne($file, $meta, $uploadedBy);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function storeOne(UploadedFile $file, array $meta = [], ?int $uploadedBy = null): PublicFile
    {
        $disk = (string) config('heisenberg.media.disk', 'uploads');
        $sourcePath = $file->getRealPath() ?: $file->getPathname();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        // Architectural fix: the extension allow-list used to live ONLY in
        // UploadPublicFileRequest's `mimes:` rule, so any caller that doesn't
        // go through that FormRequest (e.g. a Livewire upload handler with no
        // FormRequest at all) could store an arbitrary file type — including
        // an .svg carrying an inline <script>, later served back from this
        // app's own origin with its real `image/svg+xml` mime type and
        // executed. Enforcing it HERE means no future caller can bypass it
        // again, regardless of environment or authorization outcome.
        $this->enforceAllowedExtension($extension, $file, $uploadedBy);

        // Virus scan happens on the TEMP path, BEFORE anything is written to
        // the public disk (blueprint §5 step 3 / §5.1 / §12 invariant).
        $this->enforceScan($this->scanner->scan($sourcePath), $file, $uploadedBy);

        $dir = 'media/' . now()->format('Y/m');
        $originalName = $file->getClientOriginalName();
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        $writtenPaths = [];

        try {
            [$storedName, $collisionSuffix] = $this->availableFilename($disk, $dir, $baseName, $extension);
            $stored = Storage::disk($disk)->putFileAs($dir, $file, $storedName);

            if ($stored === false) {
                throw new \RuntimeException('Failed to store the uploaded file on disk.');
            }

            $writtenPaths[] = $stored;

            [$width, $height] = $this->dimensions($sourcePath);

            $variants = [];
            if ($width !== null && $this->isImageExtension($extension) && ! $this->exceedsMegapixelCap($width, $height)) {
                $variants = $this->variants($disk, $dir, $storedName, $sourcePath);
                foreach ($variants as $variant) {
                    $writtenPaths[] = $variant['path'];
                }
            }

            $class = $this->modelClass();

            /** @var PublicFile $publicFile */
            $publicFile = $class::create(array_merge([
                'uploaded_by' => $uploadedBy,
                'type' => $extension,
                'disk' => $disk,
                'stored_path' => $stored,
                // Duplicates get the collision marker in their DISPLAY name too
                // — see suffixedDisplayName(). The un-suffixed first upload
                // keeps the client's name verbatim.
                'original_name' => $collisionSuffix === 0 ? $originalName : $this->suffixedDisplayName($originalName, $collisionSuffix),
                'stored_name' => $storedName,
                'mime_type' => $file->getMimeType() ?: (string) $file->getClientMimeType(),
                'size_bytes' => $file->getSize() ?: 0,
                'width' => $width,
                'height' => $height,
                'variants' => $variants,
            ], $this->metaFields($meta)));

            return $publicFile;
        } catch (Throwable $e) {
            // Failure cleanup (blueprint §5 step 9): no orphaned bytes on disk.
            foreach ($writtenPaths as $path) {
                Storage::disk($disk)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $filters extension, search, uploader_id, date_from, date_to
     */
    public function paginate(array $filters = [], int $perPage = 24): LengthAwarePaginator
    {
        $class = $this->modelClass();
        $query = $class::query()->orderByDesc('created_at');

        if (! empty($filters['extension'])) {
            $query->where('type', $filters['extension']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('original_name', 'like', "%{$search}%")
                    ->orWhere('alt_text_en', 'like', "%{$search}%")
                    ->orWhere('alt_text_fr', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['uploader_id'])) {
            $query->where('uploaded_by', $filters['uploader_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Only the whitelisted meta fields are ever mutated — binary/path columns
     * (stored_path, disk, mime_type, variants, ...) are never touched here
     * (blueprint §8, array_intersect_key guarantee).
     *
     * @param array<string, mixed> $data
     */
    public function updateMeta(PublicFile $file, array $data): PublicFile
    {
        $file->fill($this->metaFields($data));
        $file->save();

        return $file;
    }

    /** Physical delete (original + every variant) + soft-delete the row (§8). */
    public function delete(PublicFile $file): void
    {
        $paths = [$file->stored_path];

        foreach ((array) ($file->variants ?? []) as $variant) {
            if (! empty($variant['path'])) {
                $paths[] = $variant['path'];
            }
        }

        Storage::disk((string) $file->disk)->delete($paths);

        $file->delete();
    }

    // ── Upload pipeline internals (§5, §6) ────────────────────────────────

    /**
     * The single source of truth for "which file types are allowed", enforced
     * regardless of which caller reached storeOne() (HTTP FormRequest,
     * Livewire, or anything future). Mirrors UploadPublicFileRequest's
     * `mimes:` rule's source config so both stay in lockstep.
     *
     * A blank extension (no '.' in the client-supplied filename at all — e.g.
     * a filename of "..", see the dedicated sanitizeBaseName() test coverage)
     * is intentionally NOT rejected here: there is no extension for a web
     * server to ever dispatch to a script handler, so it isn't the attack
     * this allow-list defends against, and rejecting it would regress an
     * existing, deliberately-tested edge case. Every non-blank extension is
     * checked strictly.
     */
    private function enforceAllowedExtension(string $extension, UploadedFile $file, ?int $uploadedBy): void
    {
        if ($extension === '') {
            return;
        }

        $allowed = array_map(
            static fn ($ext) => strtolower((string) $ext),
            (array) config('heisenberg.media.extensions', PublicFile::TYPES),
        );

        if (in_array($extension, $allowed, true)) {
            return;
        }

        Log::warning('heisenberg: media upload rejected — disallowed file extension', [
            'extension' => $extension,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'uploaded_by' => $uploadedBy,
        ]);

        throw ValidationException::withMessages([
            'file' => ['That file type is not allowed.'],
        ]);
    }

    private function enforceScan(string $result, UploadedFile $file, ?int $uploadedBy): void
    {
        if ($result === 'clean') {
            return;
        }

        if ($result === 'unavailable') {
            $failOpen = (bool) config('heisenberg.media.scan.fail_open', false);

            $this->logSecurityEvent($failOpen ? 'unavailable-fail-open' : 'unavailable', $file, $uploadedBy);

            if ($failOpen) {
                return;
            }

            throw ValidationException::withMessages([
                'file' => ['The virus scanner is unavailable; the upload was rejected.'],
            ]);
        }

        // infected / suspicious / anything else that isn't 'clean'.
        $this->logSecurityEvent($result, $file, $uploadedBy);

        throw ValidationException::withMessages([
            'file' => ['The uploaded file failed a security scan.'],
        ]);
    }

    private function logSecurityEvent(string $result, UploadedFile $file, ?int $uploadedBy): void
    {
        Log::warning('heisenberg: media upload rejected by virus scan', [
            'result' => $result,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path): array
    {
        if (! is_file($path)) {
            return [null, null];
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return [null, null];
        }

        return [$info[0] ?? null, $info[1] ?? null];
    }

    /**
     * Decompression-bomb guard (blueprint §6): decides — using ONLY the cheap
     * getimagesize() header dimensions already read in dimensions(), never a
     * GD decode — whether an image is too large to safely run through
     * Intervention's read(). A hostile or malformed image can declare a
     * pixel grid that is trivially small on disk but enormous once decoded
     * (e.g. a "PNG bomb"), exhausting memory/CPU in the variant pipeline.
     * When the cap is exceeded, the caller skips variant generation entirely
     * — the original file is still stored and served, just without
     * small/medium derivatives.
     */
    private function exceedsMegapixelCap(?int $width, ?int $height): bool
    {
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return false;
        }

        $maxMegapixels = (float) config('heisenberg.media.max_megapixels', 40);
        if ($maxMegapixels <= 0) {
            return false; // 0 (or negative) disables the cap.
        }

        return ($width * $height) > ($maxMegapixels * 1_000_000);
    }

    /**
     * Collision-free stored filename: `photo.jpg` -> `photo(1).jpg` -> ... The
     * conflict check considers the base file AND every variant path so an
     * original and its -small/-medium derivatives never clash (blueprint §3.2).
     *
     * Returns the applied collision suffix alongside the name (0 = no
     * collision) so storeOne() can mirror the SAME `(n)` into the DISPLAY name
     * — two uploads of photo.jpg used to both show "photo.jpg" in every
     * library UI, indistinguishable (owner decision, 2026-08-10: duplicates
     * read photo(1).jpg, WordPress-style).
     *
     * @return array{0: string, 1: int} [stored filename, collision suffix]
     */
    private function availableFilename(string $disk, string $dir, string $baseName, string $extension): array
    {
        $extension = strtolower($extension);
        $safeBase = $this->sanitizeBaseName($baseName);

        $counter = 0;
        do {
            $candidateBase = $counter === 0 ? $safeBase : "{$safeBase}({$counter})";
            $counter++;
        } while ($this->filenameConflicts($disk, $dir, $candidateBase, $extension));

        $name = $extension === '' ? $candidateBase : "{$candidateBase}.{$extension}";

        return [$name, $counter - 1];
    }

    /**
     * The client's own filename with the collision marker spliced in before the
     * extension — display-side twin of availableFilename()'s stored rename.
     * Works off the RAW client name (original casing, original extension) so
     * "Photo.JPG" duplicates read "Photo(1).JPG", not a sanitized variant.
     */
    private function suffixedDisplayName(string $originalName, int $suffix): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        return $extension === '' ? "{$base}({$suffix})" : "{$base}({$suffix}).{$extension}";
    }

    private function filenameConflicts(string $disk, string $dir, string $base, string $extension): bool
    {
        $storageDisk = Storage::disk($disk);
        $mainName = $extension === '' ? $base : "{$base}.{$extension}";

        if ($storageDisk->exists($dir . '/' . $mainName)) {
            return true;
        }

        if (! $this->isImageExtension($extension)) {
            return false;
        }

        $variantExtension = $this->normalizeVariantExtension($extension);
        foreach (array_keys($this->variantConfig()) as $variantKey) {
            $variantName = "{$base}-{$variantKey}.{$variantExtension}";
            if ($storageDisk->exists($dir . '/' . $variantName)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeBaseName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '', $name);

        // Windows-illegal filename characters (: * ? " < > |) plus every
        // other ASCII control character (\x00-\x1F — \0 is already gone
        // above). Left alone, these either break outright on a
        // Windows-hosted disk, or a Flysystem adapter throws a raw
        // filesystem exception instead of a clean validation error (a hard
        // 500 for what should have been a normal upload). Replaced with '-'
        // rather than stripped, so e.g. "report: q1 * final?.pdf" doesn't
        // silently collapse into a shorter name that could collide with an
        // unrelated upload.
        $name = (string) preg_replace('/[:*?"<>|\x00-\x1F]/', '-', $name);

        $name = trim($name);

        // A base name that is empty, or consists ONLY of dots (".", "..",
        // "...", ...), must never reach the disk as-is. When the client
        // supplies an extension-less name such as "..", PHP's pathinfo()
        // reduces the base name to ".": with no extension appended, the
        // stored filename becomes exactly "." or "..". Storage::putFileAs()
        // joins that onto the destination directory (e.g. "media/2026/07/.")
        // and Flysystem's path normalizer collapses "." (and pops a
        // directory level for "..") — so the write lands on the
        // *directory's own path* instead of a new file inside it, clobbering
        // "media/2026/07" with a regular file and breaking every future
        // upload into that month for every user until an operator manually
        // removes it (blueprint §3.2 filename policy).
        if ($name === '' || preg_match('/^\.+$/', $name) === 1) {
            return 'file';
        }

        // Absurdly long filenames (some clients send 255+ byte names; a
        // hostile client can send far more) can push the combined
        // media/{Y}/{m}/{base}-medium.{ext} path past common filesystem
        // path-length limits, again risking a Flysystem 500 instead of a
        // clean failure. Truncate to a sane length, well under typical
        // 255-byte filename limits, leaving headroom for the "(N)"
        // collision suffix and the "-small"/"-medium" variant suffixes
        // appended later.
        if (mb_strlen($name) > self::MAX_BASE_NAME_LENGTH) {
            $name = rtrim(mb_substr($name, 0, self::MAX_BASE_NAME_LENGTH));
        }

        return $name === '' ? 'file' : $name;
    }

    /**
     * Responsive derivatives for image uploads (blueprint §6), via Intervention
     * Image (GD driver). Guarded with class_exists so a host without the
     * (optional) library still gets a working upload — just no variants.
     *
     * @return array<string, array{path: string, width: int, height: int, size_bytes: int}>
     */
    private function variants(string $disk, string $dir, string $storedName, string $sourcePath): array
    {
        if (! class_exists(\Intervention\Image\ImageManager::class)) {
            return [];
        }

        try {
            $pathInfo = pathinfo($storedName);
            $base = (string) ($pathInfo['filename'] ?? $storedName);
            $extension = $this->normalizeVariantExtension(strtolower((string) ($pathInfo['extension'] ?? '')));

            $manager = \Intervention\Image\ImageManager::gd();
            $result = [];

            foreach ($this->variantConfig() as $key => $spec) {
                $width = (int) ($spec['width'] ?? 0);
                if ($width <= 0) {
                    continue;
                }

                $image = $manager->read($sourcePath);
                $image->scaleDown(width: $width); // never upscales
                $encoded = $image->encodeByExtension($extension, quality: 82);

                $variantName = "{$base}-{$key}.{$extension}";
                $variantPath = $dir . '/' . $variantName;
                $bytes = (string) $encoded;

                Storage::disk($disk)->put($variantPath, $bytes);

                $result[$key] = [
                    'path' => $variantPath,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'size_bytes' => strlen($bytes),
                ];
            }

            return $result;
        } catch (Throwable) {
            // Variant generation failing must never fail the upload — the
            // original is still usable (blueprint §6). But if an earlier
            // variant in this loop already wrote bytes to disk before a
            // later one threw, those bytes must not survive as untracked
            // orphans (blueprint §5 step 9 "no orphans" invariant) — the
            // caller only learns about paths in the returned array, so any
            // partial $result built before the exception must be cleaned up
            // here, not just discarded.
            foreach ($result ?? [] as $variant) {
                Storage::disk($disk)->delete($variant['path']);
            }

            return [];
        }
    }

    /** @return array<string, array{width: int}> */
    private function variantConfig(): array
    {
        return (array) config('heisenberg.media.variants', PublicFile::VARIANTS);
    }

    private function isImageExtension(string $extension): bool
    {
        return in_array(strtolower($extension), PublicFile::IMAGE_EXTENSIONS, true);
    }

    /** jpeg normalizes to jpg for variant filenames (blueprint §6). */
    private function normalizeVariantExtension(string $extension): string
    {
        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function metaFields(array $meta): array
    {
        $allowed = ['alt_text_en', 'alt_text_fr', 'caption_en', 'caption_fr', 'credit'];

        return array_intersect_key($meta, array_flip($allowed));
    }

    private function modelClass(): string
    {
        return (string) config('heisenberg.models.public_file', PublicFile::class);
    }
}
