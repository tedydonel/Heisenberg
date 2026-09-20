<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\Revision;
use Heisenberg\Services\McpToolException;

/**
 * `list_revisions`/`restore_revision`. Restoring funnels through
 * {@see PostAccess::writePost()} — the exact same write path `create_post`/`update_post`
 * use — so a restore is validated and sanitized identically to any other content write,
 * and (because `writePost()` snapshots the post's PRIOR state before replacing it) a
 * restore is itself undoable via another `restore_revision` call.
 */
final class RevisionTools implements McpToolProvider
{
    public function __construct(private PostAccess $posts)
    {
    }

    public function definitions(): array
    {
        return [
            'list_revisions' => [
                'description' => 'List a post\'s revision history, newest first (id, timestamp, type, title, block count). Pass a revision_id to restore_revision to roll back.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema(['post_id' => ['type' => 'integer']], ['post_id']),
            ],

            'restore_revision' => [
                'description' => 'Restore a post to a prior revision\'s content. The post\'s CURRENT content is snapshotted as a new revision first (revision_type "restore" for the resulting save), so a restore is itself undoable via another restore_revision call. Bumps content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'revision_id' => ['type' => 'integer'],
                ], ['post_id', 'revision_id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['list_revisions', 'restore_revision'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'list_revisions' => $this->listRevisions($arguments),
            'restore_revision' => $this->restoreRevision($arguments),
            default => throw new \LogicException("RevisionTools does not handle '{$tool}'."),
        };
    }

    /** @param array<string, mixed> $args */
    private function listRevisions(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $revisionClass = (string) config('heisenberg.models.revision', Revision::class);
        $rows = $revisionClass::query()->where('post_id', $post->getKey())->orderByDesc('id')->limit(50)->get();

        return [
            'post_id' => $post->getKey(),
            'revisions' => $rows->map(static fn (Revision $r): array => [
                'id' => $r->getKey(),
                'created_at' => $r->created_at?->toIso8601String(),
                'revision_type' => $r->revision_type,
                'title' => $r->title_en,
                'blocks_count' => is_array($r->content_blocks) ? count($r->content_blocks) : 0,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $args */
    private function restoreRevision(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $revisionClass = (string) config('heisenberg.models.revision', Revision::class);
        $row = $revisionClass::query()->where('post_id', $post->getKey())->find($args['revision_id'] ?? null);
        if ($row === null) {
            throw new McpToolException('No revision ' . (int) ($args['revision_id'] ?? 0) . " for post {$post->getKey()}.");
        }

        $blocks = collect(is_array($row->content_blocks) ? $row->content_blocks : [])
            ->sortBy(static fn (array $entry): int => (int) ($entry['order'] ?? 0))
            ->pluck('content')
            ->filter(static fn ($content): bool => is_array($content))
            ->values()
            ->all();

        $result = $this->posts->writePost($post, ['blocks' => $blocks], 'restore');

        return [
            'post_id' => $post->getKey(),
            'restored_from_revision' => (int) $row->getKey(),
            'blocks_total' => $result['blocks'],
            'content_version' => $result['content_version'],
        ];
    }
}
