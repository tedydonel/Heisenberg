<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read side of the revision history the save flow captures
 * ({@see PostController::captureRevision()}). Restore is deliberately
 * client-driven: the editor fetches a revision's block tree, applies it via
 * `hbEditor.replaceDoc()` (which also makes the restore UNDOABLE), and the
 * next ordinary save persists it — no server-side restore path to secure.
 */
class PostRevisionsController
{
    /** Newest first: id, timestamps, type, title, and block count per row. */
    public function index(Request $request, string $post): JsonResponse
    {
        $model = $this->findOrFail($post);

        Gate::forUser($this->actor($request))->authorize('view', $model);

        $rows = $this->revisionClass()::query()
            ->where('post_id', $model->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'revisions' => $rows->map(static fn (Revision $revision): array => [
                'id' => $revision->getKey(),
                'created_at' => $revision->created_at?->toIso8601String(),
                'revision_type' => $revision->revision_type,
                'title' => $revision->title_en,
                'blocks_count' => is_array($revision->content_blocks) ? count($revision->content_blocks) : 0,
            ])->values()->all(),
        ]);
    }

    /** One revision's block tree, in the exact client shape replaceDoc() consumes. */
    public function show(Request $request, string $post, string $revision): JsonResponse
    {
        $model = $this->findOrFail($post);

        Gate::forUser($this->actor($request))->authorize('view', $model);

        // Scoped to the post — a revision id belonging to another post is a 404,
        // never a leak (same IDOR posture as the rest of the JSON API).
        $row = $this->revisionClass()::query()
            ->where('post_id', $model->getKey())
            ->findOrFail($revision);

        $blocks = collect(is_array($row->content_blocks) ? $row->content_blocks : [])
            ->sortBy(static fn (array $entry): int => (int) ($entry['order'] ?? 0))
            ->pluck('content')
            ->filter(static fn ($content): bool => is_array($content))
            ->values()
            ->all();

        return response()->json([
            'id' => $row->getKey(),
            'created_at' => $row->created_at?->toIso8601String(),
            'blocks' => $blocks,
        ]);
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Revision> */
    private function revisionClass(): string
    {
        return (string) config('heisenberg.models.revision', Revision::class);
    }

    private function findOrFail(string $id): Post
    {
        $class = (string) config('heisenberg.models.post', Post::class);

        return $class::query()->findOrFail($id);
    }
}
