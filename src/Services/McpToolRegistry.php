<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\Tag;
use Heisenberg\Support\BlockViewData;
use Illuminate\Support\Facades\DB;

/**
 * The tools Heisenberg exposes to external AIs over MCP — the inbound half of
 * this package's MCP support.
 *
 * The governing rule: **every write goes through the same pipeline the editor
 * uses**, never around it. `create_post` and `update_post` build the exact
 * envelope {@see BlocksPayloadService::validatePayload()} expects and refuse the
 * write if it fails, so an external agent gets the same contract validation,
 * the same sanitization and the same unknown-block dropping as a human clicking
 * Save. There is deliberately no fast path.
 *
 * Tools are tiered, and the tier is enforced twice: a read-only token never sees
 * the write tools in `tools/list`, and calling one by name anyway is refused in
 * {@see self::call()}. Hiding alone would be security by obscurity.
 */
class McpToolRegistry
{
    /** Read-only; available to any valid token. */
    public const TIER_READ = 'read';

    /** Create and edit content. */
    public const TIER_AUTHORS = 'authors';

    /** Publish, and anything that changes site-wide state. */
    public const TIER_ADMINS = 'admins';

    /** Ascending privilege — a token's tier satisfies every tier at or below it. */
    private const TIER_ORDER = [self::TIER_READ, self::TIER_AUTHORS, self::TIER_ADMINS];

    public function __construct(
        private BlockRegistryService $registry,
        private BlocksPayloadService $payload,
        private BlockRenderer $renderer,
        private ShortcodeParser $parser,
        private ShortcodeSerializer $serializer,
    ) {
    }

    public static function tierSatisfies(string $tokenTier, string $required): bool
    {
        $have = array_search($tokenTier, self::TIER_ORDER, true);
        $need = array_search($required, self::TIER_ORDER, true);

        return $have !== false && $need !== false && $have >= $need;
    }

    /**
     * Tool descriptors visible to a token of `$tier`, in MCP's `tools/list` shape.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listFor(string $tier): array
    {
        $out = [];
        foreach ($this->tools() as $name => $tool) {
            if (! self::tierSatisfies($tier, $tool['tier'])) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return $out;
    }

    /**
     * Every tool with the tier it needs — what the settings modal's Expose tab
     * renders. Generated from the same table `tools/list` answers from, so the
     * UI cannot advertise a tool the server does not have.
     *
     * @return list<array{name: string, description: string, tier: string}>
     */
    public function describeAll(): array
    {
        $out = [];
        foreach ($this->tools() as $name => $tool) {
            $out[] = ['name' => $name, 'description' => $tool['description'], 'tier' => $tool['tier']];
        }

        return $out;
    }

    /**
     * Execute a tool. Returns MCP's `tools/call` result shape — content blocks
     * plus an `isError` flag, because a tool that failed is a normal result to
     * the model, not a transport error.
     *
     * @param  array<string, mixed> $arguments
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function call(string $name, array $arguments, string $tier): array
    {
        $tools = $this->tools();
        if (! isset($tools[$name])) {
            return $this->error("Unknown tool '{$name}'");
        }
        if (! self::tierSatisfies($tier, $tools[$name]['tier'])) {
            return $this->error("Tool '{$name}' requires the '{$tools[$name]['tier']}' tier; this token has '{$tier}'.");
        }

        try {
            return $this->ok($tools[$name]['handler']($arguments));
        } catch (McpToolException $e) {
            return $this->error($e->getMessage());
        }
    }

    /** @return array<string, array{description: string, tier: string, inputSchema: array<string, mixed>, handler: callable}> */
    private function tools(): array
    {
        return [
            // ── discovery ────────────────────────────────────────────
            // The contract set IS the authoring schema: an agent reads these two
            // and knows exactly what it may emit.
            'list_blocks' => [
                'description' => 'List every block contract available in this Heisenberg install. Call this before authoring content — the returned slugs are the only valid shortcode tags.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([]),
                'handler' => fn (): array => array_map(
                    static fn (array $c): array => [
                        'name' => $c['name'],
                        'slug' => ShortcodeDialect::slugOf((string) $c['name']),
                        'title' => $c['title'],
                        'category' => $c['category'],
                        'acceptsChildren' => (bool) ($c['innerBlocks']['enabled'] ?? false),
                    ],
                    array_values(BlockViewData::clientBlocks($this->registry)),
                ),
            ],

            'describe_block' => [
                'description' => 'Full contract for one block: its attributes (with types, defaults and enums) and the style supports it accepts.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'name' => ['type' => 'string', 'description' => 'Contract name or bare slug, e.g. "heisenberg/heading" or "heading".'],
                ], ['name']),
                'handler' => function (array $args): array {
                    $blocks = BlockViewData::clientBlocks($this->registry);
                    $wanted = (string) ($args['name'] ?? '');
                    foreach ($blocks as $name => $contract) {
                        if ($name === $wanted || ShortcodeDialect::slugOf((string) $name) === $wanted) {
                            return [
                                'name' => $contract['name'],
                                'slug' => ShortcodeDialect::slugOf((string) $name),
                                'attributes' => $contract['attributeDefinitions'],
                                'supports' => $contract['supports'],
                                'innerBlocks' => $contract['innerBlocks'],
                            ];
                        }
                    }

                    throw new McpToolException("No block contract named '{$wanted}'. Call list_blocks first.");
                },
            ],

            // ── posts ────────────────────────────────────────────────
            'list_posts' => [
                'description' => 'List posts, newest first.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (1-100, default 20).'],
                    'status' => ['type' => 'string', 'description' => 'Filter by status, e.g. draft or published.'],
                ]),
                'handler' => function (array $args): array {
                    $query = $this->postClass()::query()->orderByDesc('id');
                    if (($status = trim((string) ($args['status'] ?? ''))) !== '') {
                        $query->where('status', $status);
                    }

                    return $query->limit($this->boundedLimit($args))->get()
                        ->map(static fn (Post $p): array => [
                            'id' => $p->getKey(),
                            'title' => (string) ($p->title_en ?? ''),
                            'slug' => (string) ($p->slug ?? ''),
                            'status' => (string) ($p->status ?? ''),
                            'content_version' => (int) $p->content_version,
                        ])->all();
                },
            ],

            'get_post' => [
                'description' => 'One post with its content as BOTH shortcode (edit this) and raw block JSON. Pass content_version back to update_post to avoid clobbering a concurrent edit.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                ], ['id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['id'] ?? null);
                    $blocks = $post->blocks()->orderBy('order')->get()
                        ->map(static fn ($b) => $b->content)->values()->all();

                    return [
                        'id' => $post->getKey(),
                        'title' => (string) ($post->title_en ?? ''),
                        'status' => (string) ($post->status ?? ''),
                        'content_version' => (int) $post->content_version,
                        'code' => $this->serializer->serialize($blocks),
                        'blocks' => $blocks,
                    ];
                },
            ],

            'create_post' => [
                'description' => 'Create a post. Supply content as `code` (Heisenberg shortcode — preferred) or `blocks` (raw block JSON). Content is validated against the live block contracts and sanitized exactly as the editor does.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'title' => ['type' => 'string', 'description' => 'Post title.'],
                    'code' => ['type' => 'string', 'description' => 'Content as shortcode.'],
                    'blocks' => ['type' => 'array', 'description' => 'Content as block JSON.', 'items' => ['type' => 'object']],
                    'status' => ['type' => 'string', 'description' => 'Defaults to draft. Anything else requires an admins-tier token.'],
                ], ['title']),
                'handler' => fn (array $args): array => $this->writePost(null, $args),
            ],

            'update_post' => [
                'description' => 'Replace an existing post\'s title and/or content. Pass the content_version from get_post to detect a concurrent edit.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                    'title' => ['type' => 'string'],
                    'code' => ['type' => 'string', 'description' => 'Content as shortcode.'],
                    'blocks' => ['type' => 'array', 'description' => 'Content as block JSON.', 'items' => ['type' => 'object']],
                    'content_version' => ['type' => 'integer', 'description' => 'The version get_post returned. Rejected if it has moved on.'],
                ], ['id']),
                'handler' => fn (array $args): array => $this->writePost($this->findPost($args['id'] ?? null), $args),
            ],

            'render_preview' => [
                'description' => 'Render shortcode or block JSON to HTML without saving anything. Use it to check output before writing a post.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'code' => ['type' => 'string'],
                    'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'locale' => ['type' => 'string', 'description' => 'Defaults to en.'],
                ]),
                'handler' => function (array $args): array {
                    $blocks = $this->contentBlocks($args);
                    $locale = (string) ($args['locale'] ?? 'en');
                    $locale = in_array($locale, ['en', 'fr'], true) ? $locale : 'en';

                    return ['html' => $this->renderer->renderBlocks($blocks, $locale)];
                },
            ],

            // ── taxonomy + media ─────────────────────────────────────
            'list_categories' => [
                'description' => 'Every category.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([]),
                'handler' => fn (): array => $this->taxonomy(Category::class, 'category'),
            ],

            'list_tags' => [
                'description' => 'Every tag.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([]),
                'handler' => fn (): array => $this->taxonomy(Tag::class, 'tag'),
            ],

            'attach_category' => [
                'description' => 'Attach a category to a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'category_id' => ['type' => 'integer'],
                ], ['post_id', 'category_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    $post->categories()->syncWithoutDetaching([(int) $args['category_id']]);

                    return ['attached' => true, 'post_id' => $post->getKey()];
                },
            ],

            'attach_tag' => [
                'description' => 'Attach a tag to a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'tag_id' => ['type' => 'integer'],
                ], ['post_id', 'tag_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    $post->tags()->syncWithoutDetaching([(int) $args['tag_id']]);

                    return ['attached' => true, 'post_id' => $post->getKey()];
                },
            ],

            // Read-only on purpose: an agent may reference existing media, but
            // writing bytes to the host's disk is a bigger grant than authoring
            // text and is not part of this surface.
            'list_media' => [
                'description' => 'List uploaded media files so their URLs can be referenced in blocks. Read-only — this surface cannot upload.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (1-100, default 20).'],
                ]),
                'handler' => function (array $args): array {
                    $class = (string) config('heisenberg.models.public_file', PublicFile::class);

                    return $class::query()->orderByDesc('id')->limit($this->boundedLimit($args))->get()
                        ->map(static fn ($f): array => [
                            'id' => $f->getKey(),
                            'name' => (string) ($f->original_name ?? ''),
                            'url' => method_exists($f, 'url') ? (string) $f->url() : '',
                        ])->all();
                },
            ],
        ];
    }

    /**
     * The one write path. Builds the editor's own save envelope, validates it,
     * and only then touches the database — inside a transaction, so a post is
     * never left with half its blocks.
     *
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function writePost(?Post $existing, array $args): array
    {
        $blocks = $this->contentBlocks($args, allowEmpty: $existing !== null);

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
            // Publishing is a lifecycle transition, not a content edit.
            throw new McpToolException("Setting status '{$status}' is not permitted over MCP; posts are created as drafts.");
        }

        if ($blocks !== null) {
            // The parser produces bare models, exactly as the JS parser does —
            // in the browser it is replaceDoc() that stamps each one with an id,
            // the contract's schemaVersion and the attribute defaults. There is
            // no replaceDoc here, so this is that step. Without it every write
            // fails validation on `missing key 'id'`.
            $blocks = $this->hydrateBlocks($blocks);

            $checked = $this->payload->validatePayload([
                'schemaVersion' => 1,
                // Computed live rather than quoted by the caller: an MCP client
                // holds no page snapshot that could be stale.
                'registryHash' => $this->registry->computeHash(),
                'blocks' => $blocks,
            ]);

            if (! ($checked['valid'] ?? false)) {
                throw new McpToolException('Content rejected: ' . implode('; ', (array) ($checked['errors'] ?? ['invalid payload'])));
            }

            $blocks = $checked['blocks'] ?? $blocks;
        }

        $title = array_key_exists('title', $args) ? trim((string) $args['title']) : null;
        if ($existing === null && ($title === null || $title === '')) {
            throw new McpToolException('title is required to create a post.');
        }

        return DB::transaction(function () use ($existing, $title, $blocks): array {
            $post = $existing ?? new ($this->postClass())();

            if ($title !== null && $title !== '') {
                $post->title_en = $title;
            }
            if ($existing === null) {
                $post->status = 'draft';
            }
            $post->save();

            if ($blocks !== null) {
                $post->blocks()->delete();
                foreach (array_values($blocks) as $index => $block) {
                    $name = (string) ($block['name'] ?? '');
                    $post->blocks()->create([
                        'type' => str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name,
                        'content' => $block,
                        'order' => $index,
                    ]);
                }
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
     * The server-side equivalent of the runtime's `newBlockModel()`: give every
     * model a unique id, the contract's own `schemaVersion`, and the contract's
     * attribute defaults beneath whatever the caller supplied.
     *
     * An unregistered block name is an ERROR here, not a silent drop. The editor
     * drops (a contract can vanish between page load and save, and losing one
     * block beats losing the document), but an agent that gets "saved" back for
     * content that was discarded has no way to notice — so this surface tells it
     * instead, and names the tool that would have prevented the mistake.
     *
     * @param  list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function hydrateBlocks(array $blocks, int $depth = 0): array
    {
        if ($depth > 20) {
            throw new McpToolException('Block nesting is too deep (max 20).');
        }

        $contracts = BlockViewData::clientBlocks($this->registry);
        $out = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                throw new McpToolException('Every entry in `blocks` must be an object.');
            }
            $name = (string) ($block['name'] ?? '');
            $contract = $contracts[$name] ?? null;
            if ($contract === null) {
                throw new McpToolException(
                    "'{$name}' is not a registered block contract. Call list_blocks to see what this install accepts."
                );
            }

            $inner = $block['innerBlocks'] ?? [];

            $out[] = [
                'name' => $name,
                'id' => (string) ($block['id'] ?? 'mcp-' . bin2hex(random_bytes(8))),
                'schemaVersion' => $block['schemaVersion'] ?? $contract['version'],
                'attributes' => array_merge(
                    (array) ($contract['attributes'] ?? []),
                    (array) ($block['attributes'] ?? []),
                ),
                'supports' => (array) ($block['supports'] ?? []),
                'innerBlocks' => $this->hydrateBlocks(is_array($inner) ? $inner : [], $depth + 1),
            ];
        }

        return $out;
    }

    /**
     * Content from either input shape. Shortcode is the ergonomic surface and
     * block JSON the canonical one; both end up as models validated the same way.
     *
     * @param  array<string, mixed> $args
     * @return list<array<string, mixed>>|null null means "not supplied"
     */
    private function contentBlocks(array $args, bool $allowEmpty = false): ?array
    {
        $hasCode = array_key_exists('code', $args) && is_string($args['code']);
        $hasBlocks = array_key_exists('blocks', $args) && is_array($args['blocks']);

        if ($hasCode && $hasBlocks) {
            throw new McpToolException('Supply either code or blocks, not both.');
        }

        if ($hasCode) {
            $parsed = $this->parser->parse((string) $args['code']);
            if ($parsed['errors'] !== []) {
                $lines = array_map(
                    static fn (array $e): string => "line {$e['line']}: {$e['message']}",
                    $parsed['errors'],
                );

                throw new McpToolException('Shortcode did not parse — ' . implode('; ', $lines));
            }

            return $parsed['blocks'];
        }

        if ($hasBlocks) {
            return array_values($args['blocks']);
        }

        if ($allowEmpty) {
            return null;
        }

        throw new McpToolException('Supply content as `code` (shortcode) or `blocks` (JSON).');
    }

    /** @return list<array<string, mixed>> */
    private function taxonomy(string $default, string $configKey): array
    {
        $class = (string) config("heisenberg.models.{$configKey}", $default);

        return $class::query()->orderBy('name_en')->get()
            ->map(static fn ($row): array => [
                'id' => $row->getKey(),
                'name' => (string) ($row->name_en ?? ''),
                'slug' => (string) ($row->slug ?? ''),
            ])->all();
    }

    private function findPost(mixed $id): Post
    {
        $post = $this->postClass()::query()->find((int) $id);
        if ($post === null) {
            throw new McpToolException("No post with id " . (int) $id . '.');
        }

        return $post;
    }

    /** @param array<string, mixed> $args */
    private function boundedLimit(array $args): int
    {
        return max(1, min(100, (int) ($args['limit'] ?? 20)));
    }

    /** @return class-string<Post> */
    private function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }

    /**
     * @param  array<string, array<string, mixed>> $properties
     * @param  list<string>                        $required
     * @return array<string, mixed>
     */
    private function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => $required,
        ];
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private function ok(mixed $result): array
    {
        return [
            'content' => [['type' => 'text', 'text' => (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'isError' => false,
        ];
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
