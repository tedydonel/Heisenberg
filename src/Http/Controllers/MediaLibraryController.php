<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Http\Requests\UploadPublicFileRequest;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\MediaLibraryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin HTTP adapter over MediaLibraryService (blueprint §2, §12): no business
 * logic lives here — every action authorizes via the policy, then delegates.
 */
class MediaLibraryController
{
    use AuthorizesRequests;

    public function __construct(private MediaLibraryService $media)
    {
    }

    /** GET /media — paginated grid + filters, or JSON when requested. */
    public function index(Request $request): JsonResponse|View
    {
        $class = $this->modelClass();
        $this->authorize('viewAny', $class);

        $files = $this->media->paginate($this->filters($request));

        if ($request->expectsJson()) {
            return response()->json([
                'files' => collect($files->items())->map(fn (PublicFile $f) => $this->attachmentPayload($f))->all(),
                'next_page_url' => $files->nextPageUrl(),
                'types' => $class::TYPES,
            ]);
        }

        return view('heisenberg::media.index', [
            'files' => $files,
            'types' => $class::TYPES,
        ]);
    }

    /** POST /media/upload — stores 1..N files; JSON (201) or redirect. */
    public function upload(UploadPublicFileRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('create', $this->modelClass());

        $files = $request->file('files');
        if (! is_array($files) || $files === []) {
            $files = array_filter([$request->file('file')]);
        }

        $meta = $request->only(['alt_text_en', 'alt_text_fr', 'caption_en', 'caption_fr', 'credit']);
        $uploadedBy = $request->user()?->getAuthIdentifier();

        $created = $this->media->store($files, $meta, $uploadedBy === null ? null : (int) $uploadedBy);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => count($created) === 1 ? 'File uploaded.' : 'Files uploaded.',
                'files' => array_map(fn (PublicFile $f) => $this->attachmentPayload($f), $created),
            ], 201);
        }

        return redirect()->route('media.index')->with('status', 'Files uploaded.');
    }

    /** PUT/PATCH /media/{file} — metadata only (blueprint §8). */
    public function update(Request $request, string $file): JsonResponse|RedirectResponse
    {
        $model = $this->findOrFail($file);
        $this->authorize('update', $model);

        $updated = $this->media->updateMeta($model, $request->only([
            'alt_text_en', 'alt_text_fr', 'caption_en', 'caption_fr', 'credit',
        ]));

        if ($request->expectsJson()) {
            return response()->json(['file' => $this->attachmentPayload($updated)]);
        }

        return redirect()->route('media.index')->with('status', 'File updated.');
    }

    /** DELETE /media/{file} — physical delete + soft-delete row (blueprint §8). */
    public function destroy(Request $request, string $file): JsonResponse|RedirectResponse
    {
        $model = $this->findOrFail($file);
        $this->authorize('delete', $model);

        $this->media->delete($model);

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('media.index')->with('status', 'File deleted.');
    }

    /** GET /media/select — JSON picker payload for modal image pickers. */
    public function select(Request $request): JsonResponse
    {
        $this->authorize('viewAny', $this->modelClass());

        $perPage = (int) $request->integer('per_page', 24) ?: 24;
        $files = $this->media->paginate($this->filters($request), $perPage);

        return response()->json([
            'files' => collect($files->items())->map(fn (PublicFile $f) => $this->attachmentPayload($f))->all(),
            'next_page_url' => $files->nextPageUrl(),
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->only(['extension', 'search', 'uploader_id', 'date_from', 'date_to']);
    }

    private function findOrFail(string $id): PublicFile
    {
        $class = $this->modelClass();

        return $class::query()->findOrFail($id);
    }

    /**
     * The rich per-file payload used by both the media grid and the picker
     * (blueprint §10 `select` semantics).
     *
     * @return array<string, mixed>
     */
    private function attachmentPayload(PublicFile $file): array
    {
        return [
            'id' => $file->id,
            'url' => $file->url,
            'thumbnail_url' => $file->thumbnail_url,
            'medium_url' => $file->medium_url,
            'large_url' => $file->large_url,
            'srcset' => $file->srcset(),
            'sizes' => $file->imagePayload()['sizes'],
            'variants' => $file->variants,
            'original_name' => $file->original_name,
            'stored_name' => $file->stored_name,
            'alt_text_en' => $file->alt_text_en,
            'alt_text_fr' => $file->alt_text_fr,
            'caption_en' => $file->caption_en,
            'caption_fr' => $file->caption_fr,
            'credit' => $file->credit,
            'width' => $file->width,
            'height' => $file->height,
            'human_size' => $file->human_size,
        ];
    }

    /** @return class-string<PublicFile> */
    private function modelClass(): string
    {
        return (string) config('heisenberg.models.public_file', PublicFile::class);
    }
}
