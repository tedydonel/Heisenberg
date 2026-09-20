<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Http\Controllers\PostController;
use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Policies\PostPolicy;
use Heisenberg\Services\McpToolException;
use Illuminate\Support\Facades\DB;

/**
 * A post's lifecycle, gated a SECOND time beyond the tool's own tier by
 * {@see PostPolicy} against the CALLING actor ({@see PostAccess::currentActor()}) —
 * `set_post_status` re-checks {@see PostPolicy::transitionAllowed()}, `trash_post`/
 * `restore_post` re-check {@see PostPolicy::delete()}/{@see PostPolicy::restore()} — so
 * an AUTHORS-tier MCP token still can't, say, publish or trash a post unless the acting
 * user actually holds the tier PostPolicy requires (mirrors the HTTP endpoints' own
 * authorization exactly).
 */
final class PostLifecycleTools implements McpToolProvider
{
    public function __construct(
        private PostAccess $posts,
        private PostPolicy $postPolicy,
    ) {
    }

    public function definitions(): array
    {
        return [
            'set_post_status' => [
                'description' => 'Change a post\'s lifecycle status (draft / pending_review / published / scheduled / archived). '
                    . 'Editor-assistant surface only — the external MCP server never offers this tool and keeps '
                    . 'posts as drafts. Legal edges mirror the editor\'s own rules exactly: draft -> pending_review '
                    . 'or archived; pending_review -> published, scheduled or draft; scheduled -> published, '
                    . 'archived or draft; published -> archived; archived -> draft. Reaching published/scheduled/'
                    . 'archived additionally requires the acting user to hold the configured tier for that target '
                    . '(the same authorization check the editor\'s Publish button runs). Does not touch content or '
                    . 'content_version — the page content is written through write_canvas and the user\'s Save.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer', 'description' => 'Post id.'],
                    'status' => ['type' => 'string', 'description' => 'Target status: pending_review, published, scheduled, archived, or draft.'],
                    'scheduled_at' => ['type' => 'string', 'description' => 'Required (ISO 8601 date/time) when status is "scheduled".'],
                ], ['post_id', 'status']),
            ],

            // Both surfaces, no draft-only restriction: unlike create_post/update_post this
            // never puts unreviewed content live, and unlike a hard delete it is reversible —
            // trash_post soft-deletes (Post::delete()'s own cascade batches its blocks/
            // revisions together), restore_post undoes it exactly (Post::restore()'s matching
            // cascade). Same "reversible, so it's safe on the external server" reasoning
            // create_translation's own docblock gives for its surface posture. Gated a SECOND
            // time beyond the AUTHORS tool tier by PostPolicy::delete()/restore() against the
            // CALLING actor (Auth::user() or a GuestActor) — same double-gate set_post_status
            // already applies for its own lifecycle tier check, so an AUTHORS-tier MCP token
            // still can't trash/restore a post unless the acting user actually holds the
            // admins tier PostPolicy requires (mirrors the HTTP endpoint's own authorization
            // exactly — PostTrashController delegates to the SAME policy methods).
            'trash_post' => [
                'description' => 'Move a post to the trash (soft delete) — reversible via restore_post. A trashed post disappears from list_posts, the sitemap, and every other listing until restored; its blocks and revisions are trashed in the same batch and come back together on restore.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema(['post_id' => ['type' => 'integer']], ['post_id']),
            ],

            'restore_post' => [
                'description' => 'Restore a post previously moved to the trash by trash_post. Its blocks and revisions from that same trash batch are restored with it.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema(['post_id' => ['type' => 'integer']], ['post_id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['set_post_status', 'trash_post', 'restore_post'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'set_post_status' => $this->setPostStatus($arguments),
            'trash_post' => $this->trashPost($arguments),
            'restore_post' => $this->restorePost($arguments),
            default => throw new \LogicException("PostLifecycleTools does not handle '{$tool}'."),
        };
    }

    /**
     * Apply a lifecycle transition exactly as
     * {@see PostController::applyTransition()}
     * does: the edge itself must be legal from the post's current status
     * (config('heisenberg.lifecycle.transitions')), and the acting user must
     * hold the tier the TARGET status requires
     * (config('heisenberg.lifecycle.role_permissions'), via
     * {@see PostPolicy::transitionAllowed()}). Deliberately does not touch
     * blocks or content_version — this is a status-only change.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function setPostStatus(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $target = trim((string) ($args['status'] ?? ''));

        $transitions = (array) config('heisenberg.lifecycle.transitions', []);
        $current = (string) $post->status;
        $allowed = (array) ($transitions[$current] ?? []);

        if (! in_array($target, $allowed, true)) {
            throw new McpToolException(
                "Cannot move a post from \"{$current}\" to \"{$target}\". Legal targets from \"{$current}\": "
                . ($allowed === [] ? 'none' : implode(', ', $allowed)) . '.'
            );
        }

        if (! $this->postPolicy->transitionAllowed($this->posts->currentActor(), $target)) {
            throw new McpToolException("You are not authorized to move this post to \"{$target}\".");
        }

        if ($target === 'scheduled') {
            $scheduledAt = null;
            if (! empty($args['scheduled_at'])) {
                try {
                    $scheduledAt = new \DateTimeImmutable((string) $args['scheduled_at']);
                } catch (\Throwable) {
                    $scheduledAt = null;
                }
            }
            if ($scheduledAt === null) {
                throw new McpToolException('scheduled_at is required (and must be a valid date/time) when status is "scheduled".');
            }
        }

        return DB::transaction(function () use ($post, $target, $args): array {
            $post->status = $target;
            if ($target === 'published' && $post->published_at === null) {
                $post->published_at = now();
            }
            if ($target === 'scheduled') {
                $post->scheduled_at = new \DateTimeImmutable((string) $args['scheduled_at']);
            }
            $post->save();

            return [
                'post_id' => $post->getKey(),
                'status' => $post->status,
                'published_at' => $post->published_at?->toIso8601String(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            ];
        });
    }

    /** @param array<string, mixed> $args */
    private function trashPost(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        if (! $this->postPolicy->delete($this->posts->currentActor(), $post)) {
            throw new McpToolException('You are not authorized to trash this post.');
        }
        $post->delete();
        $post->refresh();

        return ['post_id' => $post->getKey(), 'trashed' => true, 'deleted_at' => $post->deleted_at?->toIso8601String()];
    }

    /** @param array<string, mixed> $args */
    private function restorePost(array $args): array
    {
        $id = (int) ($args['post_id'] ?? 0);
        $post = $this->posts->postClass()::withTrashed()->find($id);
        if ($post === null) {
            throw new McpToolException("No post with id {$id}.");
        }
        if (! $post->trashed()) {
            throw new McpToolException("Post {$id} is not trashed.");
        }
        if (! $this->postPolicy->restore($this->posts->currentActor(), $post)) {
            throw new McpToolException('You are not authorized to restore this post.');
        }
        $post->restore();

        return ['post_id' => $post->getKey(), 'trashed' => false];
    }
}
