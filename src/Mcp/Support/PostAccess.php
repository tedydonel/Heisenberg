<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Support;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Http\Controllers\PostController;
use Heisenberg\Mcp\Tools\PostTools;
use Heisenberg\Mcp\Tools\RevisionTools;
use Heisenberg\Mcp\Tools\TranslationTools;
use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Heisenberg\Services\McpToolException;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Post lookup, the acting-user resolution every policy check needs, and the ONE write
 * path a content-replacing tool uses to persist a block tree — {@see self::writePost()}.
 * `create_post`/`update_post` ({@see PostTools}) and
 * `restore_revision` ({@see RevisionTools}) all funnel through the
 * same method here, so there is exactly one place that turns MCP arguments into a saved
 * post — mirroring {@see PostController::captureRevision()}
 * for revisioning and {@see ContentBlockPipeline::validatedContentBlocks()} for content
 * validation.
 */
class PostAccess
{
    public function __construct(private ContentBlockPipeline $blocks)
    {
    }

    public function findPost(mixed $id): Post
    {
        $post = $this->postClass()::query()->find((int) $id);
        if ($post === null) {
            throw new McpToolException('No post with id ' . (int) $id . '.');
        }

        return $post;
    }

    /** @return class-string<Post> */
    public function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }

    /** The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every /editor controller uses. */
    public function currentActor(): Authenticatable
    {
        return Auth::user() ?? new GuestActor();
    }

    /** @return list<array<string, mixed>> */
    public function currentBlocks(Post $post): array
    {
        return $post->blocks()->orderBy('order')->get()->map(static fn ($b) => $b->content)->values()->all();
    }

    /**
     * The one write path. Builds the editor's own save envelope, validates it,
     * and only then touches the database — inside a transaction, so a post is
     * never left with half its blocks. Every tool that edits a post's content
     * or replaces its whole tree (create_post, update_post, restore_revision)
     * funnels through here.
     *
     * @param array<string, mixed> $args
     * @param 'manual'|'auto_save'|'restore' $revisionType tag for the PRIOR-state
     *                                                     snapshot captured before an existing post's content is replaced.
     * @return array<string, mixed>
     */
    public function writePost(?Post $existing, array $args, string $revisionType = 'manual'): array
    {
        $blocks = $this->blocks->validatedContentBlocks($args, allowEmpty: $existing !== null);

        // Optimistic concurrency, same rule as PostController::save(): if the
        // caller quotes a version, it must still be current.
        if ($existing !== null && array_key_exists('content_version', $args) && $args['content_version'] !== null) {
            if ((int) $args['content_version'] !== (int) $existing->content_version) {
                throw new McpToolException(
                    'content_version is stale (post is at ' . (int) $existing->content_version . '). Re-read with get_post and retry.'
                );
            }
        }

        $status = trim((string) ($args['status'] ?? '')) ?: null;
        if ($status !== null && $status !== 'draft') {
            // Publishing is a lifecycle transition, not a content edit — see set_post_status
            // (editor-assistant surface only) for the real, config-gated transition path.
            throw new McpToolException("Setting status '{$status}' is not permitted over MCP; posts are created as drafts.");
        }

        $locale = array_key_exists('locale', $args) ? trim((string) $args['locale']) : null;
        if ($locale !== null && $locale !== '' && ! LocaleConfig::isValid($locale)) {
            $allowed = implode(', ', LocaleConfig::locales());
            throw new McpToolException("locale must be one of: {$allowed} (got '{$locale}').");
        }

        $title = array_key_exists('title', $args) ? trim((string) $args['title']) : null;
        if ($existing === null && ($title === null || $title === '')) {
            throw new McpToolException('title is required to create a post.');
        }

        // docs/email-system.md §3: `type` is create-only (an existing document's type never
        // changes via this generic write path — same "not a content edit" posture `status`
        // transitions have) and validated against the two known values.
        $type = null;
        if ($existing === null && array_key_exists('type', $args)) {
            $type = trim((string) $args['type']) ?: 'post';
            if (! in_array($type, ['post', 'email'], true)) {
                throw new McpToolException("type must be 'post' or 'email' (got '{$type}').");
            }
        }

        return DB::transaction(function () use ($existing, $args, $title, $locale, $blocks, $revisionType, $type): array {
            $post = $existing ?? new ($this->postClass())();
            if ($type !== null) {
                $post->type = $type;
            }

            if ($title !== null && $title !== '') {
                $post->title_en = $title;
            }
            if (array_key_exists('title_fr', $args) && is_string($args['title_fr'])) {
                $post->title_fr = $args['title_fr'];
            }
            if (array_key_exists('slug', $args) && is_string($args['slug']) && trim($args['slug']) !== '') {
                $post->slug = trim($args['slug']);
            }
            if ($locale !== null && $locale !== '') {
                $post->locale = $locale;
            }
            if (array_key_exists('excerpt_en', $args) && is_string($args['excerpt_en'])) {
                $post->excerpt_en = $args['excerpt_en'];
            }
            if (array_key_exists('excerpt_fr', $args) && is_string($args['excerpt_fr'])) {
                $post->excerpt_fr = $args['excerpt_fr'];
            }
            if ($existing === null) {
                $post->status = 'draft';
            }
            $post->save();

            if ($blocks !== null) {
                // Snapshot the OLD tree before it is discarded below — the revision
                // history. Creates are skipped (there is no old tree yet), mirroring
                // PostController::captureRevision().
                if ($existing !== null) {
                    $this->captureRevision($post, $revisionType);
                }

                $this->replaceBlocks($post, $blocks);
                $post->bumpContentVersion();
            }

            $post->refresh();

            return [
                'id' => $post->getKey(),
                'title' => (string) ($post->title_en ?? ''),
                'status' => (string) ($post->status ?? ''),
                'content_version' => (int) $post->content_version,
                'blocks' => $blocks === null ? null : count($blocks),
            ];
        });
    }

    /**
     * Materialize `$blocks` as `$post`'s ONLY block rows — replaces whatever was there.
     * Shared by {@see self::writePost()} and `create_translation`
     * ({@see TranslationTools}), the two paths that persist a
     * content tree, so there is exactly one place that knows how a hydrated+validated
     * block model becomes a `heisenberg_post_blocks` row. Does NOT bump
     * `content_version` or snapshot a revision — a caller replacing an EXISTING post's
     * tree must call {@see self::captureRevision()} first and bump the version after,
     * same as `writePost()` does around its own call to this method.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function replaceBlocks(Post $post, array $blocks): void
    {
        $post->blocks()->delete();
        foreach (array_values($blocks) as $index => $block) {
            $name = (string) ($block['name'] ?? '');
            $post->blocks()->create([
                'type' => str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name,
                'content' => $block,
                'order' => $index,
            ]);
        }
    }

    /**
     * Snapshot `$post`'s CURRENT (about-to-be-replaced) block tree into the
     * revisions table — the MCP-write equivalent of
     * {@see PostController::captureRevision()}.
     * Every content-replacing tool funnels through {@see self::writePost()} (or, for
     * `create_translation`, calls this directly before folding), so this is the ONE
     * place an MCP-originated edit becomes reversible.
     *
     * @param 'manual'|'auto_save'|'restore' $type
     */
    public function captureRevision(Post $post, string $type): void
    {
        $post->loadMissing('blocks');
        if ($post->blocks->isEmpty()) {
            return; // an empty tree is not a version worth restoring
        }

        $revisionClass = (string) config('heisenberg.models.revision', Revision::class);

        Revision::snapshotOf($post, $type, $this->currentActor()->getAuthIdentifier());

        $keep = config('heisenberg.revisions.keep');
        if ($keep === null) {
            return; // unbounded history — the config's as-built default
        }
        $stale = $revisionClass::query()
            ->where('post_id', $post->getKey())
            ->where('revision_type', '!=', 'auto_save')
            ->orderByDesc('id')
            ->skip(max(1, (int) $keep))->take(100)
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            $revisionClass::query()->whereKey($stale->all())->forceDelete();
        }
    }
}
