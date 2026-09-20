<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\Post;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\ShortcodeSerializer;
use Heisenberg\Services\TranslationStatusService;

/**
 * Post CRUD: `list_posts`, `get_post`, `create_post`, `update_post`. Every content write
 * funnels through {@see PostAccess::writePost()} — the same pipeline
 * {@see RevisionTools}'s `restore_revision` uses, so a post created
 * or edited over MCP is validated and sanitized exactly as the editor's own Save does.
 */
final class PostTools implements McpToolProvider
{
    public function __construct(
        private PostAccess $posts,
        private ShortcodeSerializer $serializer,
        private TranslationStatusService $translationStatus,
    ) {
    }

    public function definitions(): array
    {
        return [
            'list_posts' => [
                'description' => 'List posts, newest first. Defaults to type "post" (blog/page documents) — pass type "email" to list email documents instead (docs/email-system.md §3).',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (1-100, default 20).'],
                    'status' => ['type' => 'string', 'description' => 'Filter by status, e.g. draft or published.'],
                    'type' => ['type' => 'string', 'description' => 'post or email. Defaults to post.'],
                ]),
            ],

            'get_post' => [
                'description' => 'One post with its content as BOTH shortcode (`code` — edit this) and raw block JSON. To change the content, edit the shortcode and pass it back to update_post; pass content_version back too, to avoid clobbering a concurrent edit. `translations` maps every configured locale to its translation COMPLETENESS on this SAME row (docs/content-translation.md §0 — a translation is locale-suffixed attribute variants on the one post, not a separate row): `{is_default, title, excerpt, blocks_translated, blocks_total, complete}` — `title`/`excerpt` are booleans (that locale\'s column has content), `blocks_translated`/`blocks_total` count translatable blocks, `complete` is overall per-locale readiness. Use create_translation to fill in a gap.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                ], ['id']),
            ],

            'create_post' => [
                'description' => 'Create a post. Supply content as `code` (Heisenberg shortcode — preferred) or `blocks` (raw block JSON). Content is validated against the live block contracts and sanitized exactly as the editor does. Pass `type: "email"` to author an email document instead of a blog/page post (docs/email-system.md §3) — same authoring path, draft-only posture unchanged; render it with the EmailRenderer service or the bundled HeisenbergMailable, this tool never sends anything. ' . ToolSchema::LAYOUT_GUIDANCE . '.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EXTERNAL,
                'inputSchema' => ToolSchema::schema([
                    'title' => ['type' => 'string', 'description' => 'Post title (English).'],
                    'title_fr' => ['type' => 'string', 'description' => 'Post title (French).'],
                    'code' => ['type' => 'string', 'description' => 'Content as shortcode.'],
                    'blocks' => ['type' => 'array', 'description' => 'Content as block JSON.', 'items' => ['type' => 'object']],
                    'slug' => ['type' => 'string', 'description' => 'Explicit slug. Auto-derived from the title when omitted (numeric-suffixed on collision).'],
                    'excerpt_en' => ['type' => 'string', 'description' => 'Excerpt (English).'],
                    'excerpt_fr' => ['type' => 'string', 'description' => 'Excerpt (French).'],
                    'locale' => ['type' => 'string', 'description' => 'en or fr. Defaults to the model default (en).'],
                    'status' => ['type' => 'string', 'description' => 'Defaults to draft. Any other value is rejected here — creating a post never publishes it. Changing status afterward is a separate, surface-gated action (see set_post_status), not always available.'],
                    'type' => ['type' => 'string', 'description' => 'post or email. Defaults to post.'],
                ], ['title']),
            ],

            'update_post' => [
                'description' => 'Replace an existing post\'s title, slug, excerpt, locale and/or content. This is the direct code path: get_post gives you the current content as shortcode, you edit it, and pass the FULL updated document back as `code` — the whole content tree is replaced. Pass the content_version from get_post to detect a concurrent edit. ' . ToolSchema::LAYOUT_GUIDANCE . '.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EXTERNAL,
                'inputSchema' => ToolSchema::schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                    'title' => ['type' => 'string', 'description' => 'Post title (English).'],
                    'title_fr' => ['type' => 'string', 'description' => 'Post title (French).'],
                    'code' => ['type' => 'string', 'description' => 'Content as shortcode.'],
                    'blocks' => ['type' => 'array', 'description' => 'Content as block JSON.', 'items' => ['type' => 'object']],
                    'slug' => ['type' => 'string', 'description' => 'Explicit slug.'],
                    'excerpt_en' => ['type' => 'string', 'description' => 'Excerpt (English).'],
                    'excerpt_fr' => ['type' => 'string', 'description' => 'Excerpt (French).'],
                    'locale' => ['type' => 'string', 'description' => 'en or fr.'],
                    'content_version' => ['type' => 'integer', 'description' => 'The version get_post returned. Rejected if it has moved on.'],
                ], ['id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['list_posts', 'get_post', 'create_post', 'update_post'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'list_posts' => $this->listPosts($arguments),
            'get_post' => $this->getPost($arguments),
            'create_post' => $this->posts->writePost(null, $arguments),
            'update_post' => $this->posts->writePost($this->posts->findPost($arguments['id'] ?? null), $arguments),
            default => throw new \LogicException("PostTools does not handle '{$tool}'."),
        };
    }

    /** @param array<string, mixed> $args */
    private function listPosts(array $args): array
    {
        $type = trim((string) ($args['type'] ?? '')) ?: 'post';
        if (! in_array($type, ['post', 'email'], true)) {
            throw new McpToolException("type must be 'post' or 'email' (got '{$type}').");
        }

        $query = $this->posts->postClass()::query()->where('type', $type)->orderByDesc('id');
        if (($status = trim((string) ($args['status'] ?? ''))) !== '') {
            $query->where('status', $status);
        }

        return $query->limit(ToolSchema::boundedLimit($args))->get()
            ->map(static fn (Post $p): array => [
                'id' => $p->getKey(),
                'title' => (string) ($p->title_en ?? ''),
                'slug' => (string) ($p->slug ?? ''),
                'status' => (string) ($p->status ?? ''),
                'content_version' => (int) $p->content_version,
            ])->all();
    }

    /** @param array<string, mixed> $args */
    private function getPost(array $args): array
    {
        $post = $this->posts->findPost($args['id'] ?? null);
        $blocks = $this->posts->currentBlocks($post);

        $translations = [];
        foreach ($this->translationStatus->statuses($post) as $row) {
            $translations[$row['locale']] = [
                'is_default' => $row['is_default'],
                'title' => $row['title'],
                'excerpt' => $row['excerpt'],
                'blocks_translated' => $row['blocks_translated'],
                'blocks_total' => $row['blocks_total'],
                'complete' => $row['complete'],
            ];
        }

        return [
            'id' => $post->getKey(),
            'title' => (string) ($post->title_en ?? ''),
            'status' => (string) ($post->status ?? ''),
            'content_version' => (int) $post->content_version,
            'code' => $this->serializer->serialize($blocks),
            'blocks' => $blocks,
            'translations' => $translations,
        ];
    }
}
