<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Public media metadata (docs/media-library-backend-blueprint.md §4, §7). The
 * DB row holds metadata + the variants map ONLY — the binary lives on the
 * configured disk. Every URL is rendered through {@see urlFor()} so the S3/CDN
 * swap in blueprint §3.3 stays a config change, never a model/controller edit.
 *
 * `$fillable` (not `$guarded = []`, platform rule) covers every column except
 * id/timestamps/deleted_at.
 */
class PublicFile extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
    ];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const MAX_KB = 10_240;

    /** @var array<string, array{width: int}> */
    public const VARIANTS = [
        'small' => ['width' => 320],
        'medium' => ['width' => 768],
    ];

    /** Responsive `sizes` attribute tuned per rendering context (§7 imagePayload). */
    private const CONTEXT_SIZES = [
        'thumbnail' => '80px',
        'card' => '(max-width: 768px) 100vw, 320px',
        'gallery' => '(max-width: 768px) 50vw, 33vw',
        'hero' => '100vw',
        'article' => '(max-width: 768px) 100vw, 768px',
    ];

    protected $fillable = [
        'uploaded_by',
        'type',
        'disk',
        'stored_path',
        'original_name',
        'stored_name',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'variants',
        'alt_text_en',
        'alt_text_fr',
        'caption_en',
        'caption_fr',
        'credit',
    ];

    protected $casts = [
        'uploaded_by' => 'integer',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'variants' => 'array',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.public_files', 'heisenberg_public_files');
    }

    
    public function getUrlAttribute(): string
    {
        return $this->urlFor(null);
    }

    public function getThumbnailUrlAttribute(): string
    {
        return $this->urlFor('small') ?: $this->urlFor(null);
    }

    public function getSmallUrlAttribute(): string
    {
        return $this->getThumbnailUrlAttribute();
    }

    public function getMediumUrlAttribute(): string
    {
        return $this->urlFor('medium') ?: $this->urlFor(null);
    }

    public function getLargeUrlAttribute(): string
    {
        return $this->urlFor(null);
    }

    /**
     * The full-size URL (null variant) or a named variant's URL. Disk-aware:
     * the `uploads` disk renders `/uploads/...`; any other disk is prefixed
     * with its configured `filesystems.disks.{disk}.url` (the S3/CDN case).
     */
    public function urlFor(?string $variant = null): string
    {
        $path = $this->pathForVariant($variant);

        if ($path === null || $path === '') {
            return '';
        }

        return static::urlForPath((string) $this->disk, $path);
    }

    protected function pathForVariant(?string $variant): ?string
    {
        if ($variant === null) {
            return $this->stored_path;
        }

        $variants = (array) ($this->variants ?? []);

        return $variants[$variant]['path'] ?? null;
    }

    public static function urlForPath(string $disk, string $path): string
    {
        if ($disk === 'uploads') {
            return static::uploadsUrl($path);
        }

        $base = rtrim((string) config("filesystems.disks.{$disk}.url", ''), '/');

        return $base . '/' . static::encodePath($path);
    }

    /** `/uploads/...` with every path segment rawurlencode()'d (§7). */
    public static function uploadsUrl(string $path): string
    {
        return '/uploads/' . static::encodePath($path);
    }

    private static function encodePath(string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));

        return implode('/', $segments);
    }

    /**
     * A `srcset` string for images (variant widths + the original), or null
     * for non-image types / files with no dimensions.
     *
     * @param string[] $variants
     */
    public function srcset(array $variants = ['small', 'medium']): ?string
    {
        if (! $this->isImageType()) {
            return null;
        }

        $entries = [];
        $variantMap = (array) ($this->variants ?? []);

        foreach ($variants as $key) {
            $data = (array) ($variantMap[$key] ?? []);
            if (isset($data['path'], $data['width'])) {
                $entries[] = static::urlForPath((string) $this->disk, (string) $data['path']) . ' ' . (int) $data['width'] . 'w';
            }
        }

        if ($this->width) {
            $entries[] = $this->urlFor(null) . ' ' . (int) $this->width . 'w';
        }

        return $entries === [] ? null : implode(', ', $entries);
    }

    /**
     * @return array{url: string, srcset: ?string, sizes: ?string}
     */
    public function imagePayload(string $context = 'article'): array
    {
        return [
            'url' => $this->url,
            'srcset' => $this->srcset(),
            'sizes' => $this->isImageType() ? (self::CONTEXT_SIZES[$context] ?? self::CONTEXT_SIZES['article']) : null,
        ];
    }

    public function isImageType(): bool
    {
        return in_array(strtolower((string) $this->type), self::IMAGE_EXTENSIONS, true);
    }

    
    /** Locale alt text with fallback to the other locale, never null. */
    public function getAlt(string $locale = 'en'): string
    {
        $locale = strtolower($locale) === 'fr' ? 'fr' : 'en';
        $primary = $locale === 'fr' ? $this->alt_text_fr : $this->alt_text_en;
        $fallback = $locale === 'fr' ? $this->alt_text_en : $this->alt_text_fr;

        return (string) ($primary ?: ($fallback ?: ''));
    }

    public function getHumanSizeAttribute(): string
    {
        return static::formatBytes((int) $this->size_bytes);
    }

    public static function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, 1) . ' ' . $units[$power];
    }

    
    /**
     * Resolve a `/uploads/...` URL back to its relative stored path (the
     * inverse of {@see uploadsUrl()}). Returns null for anything that isn't
     * shaped like an uploads-disk URL.
     */
    public static function storedPathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $prefix = '/uploads/';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $relative = substr($path, strlen($prefix));
        $segments = array_map('rawurldecode', explode('/', $relative));

        return implode('/', $segments);
    }

    /**
     * Resolve a URL back to its PublicFile record — used to re-link content
     * blocks to library records. Matches the original stored_path first, then
     * falls back to a variant path.
     */
    public static function forUrl(string $url): ?self
    {
        $path = static::storedPathFromUrl($url);
        if ($path === null) {
            return null;
        }

        $found = static::query()->where('stored_path', $path)->first();
        if ($found !== null) {
            return $found;
        }

        return static::query()->get()->first(function (self $file) use ($path): bool {
            foreach ((array) ($file->variants ?? []) as $variant) {
                if (($variant['path'] ?? null) === $path) {
                    return true;
                }
            }

            return false;
        });
    }
}
