<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\Revision;
use Heisenberg\Models\Tag;
use Heisenberg\Policies\PostPolicy;
use Heisenberg\Support\BlockViewData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The tools Heisenberg exposes over MCP — to external AIs via the inbound
 * server (`routes/mcp.php`) AND to the in-editor assistant (via
 * {@see HeisenbergToolSource}), from one shared table.
 *
 * The governing rule: **every write goes through the same pipeline the editor
 * uses**, never around it. `create_post` and `update_post` (and everything
 * built on top of them — `restore_revision`) build
 * the exact envelope {@see BlocksPayloadService::validatePayload()} expects
 * and refuse the write if it fails, so an agent gets the same contract
 * validation, the same sanitization and the same unknown-block dropping as a
 * human clicking Save. Every content write that touches an EXISTING post also
 * snapshots the post's prior state into the revisions table first (mirroring
 * {@see \Heisenberg\Http\Controllers\PostController::captureRevision()}), so
 * nothing here is a one-way door — see {@see self::captureRevision()}.
 *
 * Tools are tiered (read/authors/admins) AND surfaced (editor/external). The
 * tier is the MCP-token vocabulary (who may call read-only vs. write tools at
 * all); the surface is a SEPARATE axis that decides whether a tool exists for
 * the in-editor assistant, the inbound MCP server, or both — see
 * {@see self::SURFACE_EDITOR}. Both are enforced twice: hidden from
 * `tools/list` for a caller that doesn't qualify, AND refused again if called
 * by name anyway in {@see self::call()}. Hiding alone would be security by
 * obscurity.
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

    /**
     * The in-editor assistant ({@see HeisenbergToolSource}) — a human is
     * driving, so this surface may reach further than an unattended external
     * caller (e.g. it may change a post's lifecycle status).
     */
    public const SURFACE_EDITOR = 'editor';

    /**
     * The inbound MCP server (`routes/mcp.php`) — any agent holding a valid
     * bearer token, with no human necessarily reviewing what it does. This is
     * the default surface: a tool with no `surface` entry is offered here too.
     */
    public const SURFACE_EXTERNAL = 'external';

    public function __construct(
        private BlockRegistryService $registry,
        private BlocksPayloadService $payload,
        private BlockRenderer $renderer,
        private ShortcodeParser $parser,
        private ShortcodeSerializer $serializer,
        private ThemeRepository $themes,
        private PostPolicy $postPolicy,
    ) {
    }

    public static function tierSatisfies(string $tokenTier, string $required): bool
    {
        $have = array_search($tokenTier, self::TIER_ORDER, true);
        $need = array_search($required, self::TIER_ORDER, true);

        return $have !== false && $need !== false && $have >= $need;
    }

    /**
     * Tool descriptors visible to a token of `$tier` on `$surface`, in MCP's
     * `tools/list` shape.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listFor(string $tier, string $surface = self::SURFACE_EXTERNAL): array
    {
        $out = [];
        foreach ($this->tools() as $name => $tool) {
            if (! self::tierSatisfies($tier, $tool['tier']) || ! $this->surfaceAllows($tool, $surface)) {
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
     * renders for the EXTERNAL surface (that tab configures the inbound MCP
     * server; an editor-only tool like `set_post_status` is never a candidate
     * to expose there, so it is left out by default). Generated from the same
     * table `tools/list` answers from, so the UI cannot advertise a tool the
     * server does not have.
     *
     * @return list<array{name: string, description: string, tier: string}>
     */
    public function describeAll(string $surface = self::SURFACE_EXTERNAL): array
    {
        $out = [];
        foreach ($this->tools() as $name => $tool) {
            if (! $this->surfaceAllows($tool, $surface)) {
                continue;
            }
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
    public function call(string $name, array $arguments, string $tier, string $surface = self::SURFACE_EXTERNAL): array
    {
        $tools = $this->tools();
        if (! isset($tools[$name])) {
            return $this->error("Unknown tool '{$name}'");
        }
        if (! self::tierSatisfies($tier, $tools[$name]['tier'])) {
            return $this->error("Tool '{$name}' requires the '{$tools[$name]['tier']}' tier; this token has '{$tier}'.");
        }
        if (! $this->surfaceAllows($tools[$name], $surface)) {
            return $this->error("Tool '{$name}' is not available on this surface.");
        }

        try {
            return $this->ok($tools[$name]['handler']($arguments));
        } catch (McpToolException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            // A tool handler that throws something other than a McpToolException is an
            // infrastructure fault (a DB error, a null-pointer bug, …), not a domain
            // refusal — but the model must never lose the result channel over it: without
            // this it would see nothing at all for the call it made. report() keeps the
            // fault visible to us; the model gets an actionable-enough message to move on.
            report($e);
            $class = $e::class;

            return $this->error(
                "The tool failed unexpectedly ({$class}): {$e->getMessage()}. Proceed without it or try a different approach."
            );
        }
    }

    /** @param array{surface?: string} $tool */
    private function surfaceAllows(array $tool, string $surface): bool
    {
        $required = $tool['surface'] ?? null;

        return $required === null || $required === $surface;
    }

    /** @return array<string, array{description: string, tier: string, inputSchema: array<string, mixed>, handler: callable, surface?: string}> */
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
                'description' => 'Full contract for one or more blocks: attributes (with types, defaults and enums) and the style supports each accepts. Pass `names` (a list) to batch several contracts in one call instead of one describe_block round trip per block — cheaper than calling this once per block when authoring something with a handful of different types.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'name' => ['type' => 'string', 'description' => 'Contract name or bare slug, e.g. "heisenberg/heading" or "heading".'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Batch form of `name` — describe several contracts in one call. When non-empty, `name` is ignored and the result is `{results: [...]}`, one entry per requested name (unknown names come back as `{name, error}` instead of failing the whole call).'],
                ]),
                'handler' => function (array $args): array {
                    $blocks = BlockViewData::clientBlocks($this->registry);
                    $describe = static function (string $wanted) use ($blocks): ?array {
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

                        return null;
                    };

                    $names = $args['names'] ?? null;
                    if (is_array($names) && $names !== []) {
                        return ['results' => array_map(
                            static fn (mixed $wanted): array => $describe((string) $wanted)
                                ?? ['name' => (string) $wanted, 'error' => "No block contract named '{$wanted}'. Call list_blocks first."],
                            $names,
                        )];
                    }

                    $wanted = (string) ($args['name'] ?? '');
                    $found = $describe($wanted);
                    if ($found === null) {
                        throw new McpToolException("No block contract named '{$wanted}'. Call list_blocks first.");
                    }

                    return $found;
                },
            ],

            // ── the live canvas (editor surface only) ────────────────
            // The assistant's ONE write path to the page open in front of the
            // user. Execution is split: this handler validates the shortcode
            // against the live contracts (so the model gets line-numbered
            // errors back through the tool channel), and the PANEL applies the
            // validated code to the editor when the call's arguments arrive on
            // the stream (AiToolRunner ships them, panel-ai applies them).
            // Nothing is persisted — the document stays unsaved client state
            // until the user saves, same as hand-drawn blocks.
            'write_canvas' => [
                'description' => 'Write Heisenberg shortcode directly into the editor the user is looking at. '
                    . 'The blocks land on the canvas immediately — this is THE way to build or edit the current '
                    . 'page. mode "append" (default) adds the blocks after what is already on the page; mode '
                    . '"replace" swaps the whole document for the supplied code (pass the full updated document '
                    . 'to rework or restructure existing content). Nothing is saved to the database — the user '
                    . 'reviews and saves. The code is validated against the live block contracts; on a parse '
                    . 'error nothing is applied and the error names the line to fix.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => $this->schema([
                    'code' => ['type' => 'string', 'description' => 'The content, as Heisenberg shortcode.'],
                    'mode' => ['type' => 'string', 'description' => '"append" (default) adds after the current page content; "replace" swaps the whole document.'],
                ], ['code']),
                'handler' => function (array $args): array {
                    $mode = (string) ($args['mode'] ?? 'append');
                    if (! in_array($mode, ['append', 'replace'], true)) {
                        throw new McpToolException('mode must be "append" or "replace".');
                    }

                    $blocks = $this->contentBlocks(['code' => (string) ($args['code'] ?? '')]);
                    if ($blocks === []) {
                        throw new McpToolException('The code contained no blocks — supply shortcode with at least one block tag.');
                    }

                    return ['applied' => true, 'mode' => $mode, 'blocks' => count($blocks)];
                },
            ],

            // Same client-applied split as write_canvas: validated here, landed
            // in the editor's title field by the panel when the frame arrives.
            'set_page_title' => [
                'description' => 'Set the title of the page open in the editor. It fills the editor\'s title '
                    . 'field immediately (the user still saves), so use it whenever you write a page that '
                    . 'deserves a headline — do not leave a built page untitled or ask the user to type the '
                    . 'title themselves.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => $this->schema([
                    'title' => ['type' => 'string', 'description' => 'The page title, plain text.'],
                ], ['title']),
                'handler' => function (array $args): array {
                    $title = trim((string) ($args['title'] ?? ''));
                    if ($title === '') {
                        throw new McpToolException('title must be a non-empty string.');
                    }
                    if (mb_strlen($title) > 200) {
                        throw new McpToolException('title must be 200 characters or fewer.');
                    }

                    return ['applied' => true, 'title' => $title];
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
                'description' => 'One post with its content as BOTH shortcode (`code` — edit this) and raw block JSON. To change the content, edit the shortcode and pass it back to update_post; pass content_version back too, to avoid clobbering a concurrent edit.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                ], ['id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['id'] ?? null);
                    $blocks = $this->currentBlocks($post);

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
                'surface' => self::SURFACE_EXTERNAL,
                'inputSchema' => $this->schema([
                    'title' => ['type' => 'string', 'description' => 'Post title (English).'],
                    'title_fr' => ['type' => 'string', 'description' => 'Post title (French).'],
                    'code' => ['type' => 'string', 'description' => 'Content as shortcode.'],
                    'blocks' => ['type' => 'array', 'description' => 'Content as block JSON.', 'items' => ['type' => 'object']],
                    'slug' => ['type' => 'string', 'description' => 'Explicit slug. Auto-derived from the title when omitted (numeric-suffixed on collision).'],
                    'excerpt_en' => ['type' => 'string', 'description' => 'Excerpt (English).'],
                    'excerpt_fr' => ['type' => 'string', 'description' => 'Excerpt (French).'],
                    'locale' => ['type' => 'string', 'description' => 'en or fr. Defaults to the model default (en).'],
                    'status' => ['type' => 'string', 'description' => 'Defaults to draft. Any other value is rejected here — creating a post never publishes it. Changing status afterward is a separate, surface-gated action (see set_post_status), not always available.'],
                ], ['title']),
                'handler' => fn (array $args): array => $this->writePost(null, $args),
            ],

            'update_post' => [
                'description' => 'Replace an existing post\'s title, slug, excerpt, locale and/or content. This is the direct code path: get_post gives you the current content as shortcode, you edit it, and pass the FULL updated document back as `code` — the whole content tree is replaced. Pass the content_version from get_post to detect a concurrent edit.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EXTERNAL,
                'inputSchema' => $this->schema([
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

            // ── lifecycle (editor surface only) ─────────────────────
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
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer', 'description' => 'Post id.'],
                    'status' => ['type' => 'string', 'description' => 'Target status: pending_review, published, scheduled, archived, or draft.'],
                    'scheduled_at' => ['type' => 'string', 'description' => 'Required (ISO 8601 date/time) when status is "scheduled".'],
                ], ['post_id', 'status']),
                'handler' => fn (array $args): array => $this->setPostStatus($args),
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

            'create_category' => [
                'description' => 'Create a category. The slug is derived from name_en automatically (numeric-suffixed on collision) unless an explicit `slug` is supplied.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                    'slug' => ['type' => 'string', 'description' => 'Optional explicit slug; auto-derived from name_en when omitted.'],
                    'parent_id' => ['type' => 'integer'],
                    'description_en' => ['type' => 'string'],
                    'description_fr' => ['type' => 'string'],
                ], ['name_en']),
                'handler' => function (array $args): array {
                    $name = trim((string) ($args['name_en'] ?? ''));
                    if ($name === '') {
                        throw new McpToolException('name_en is required.');
                    }
                    $class = (string) config('heisenberg.models.category', Category::class);
                    $category = $class::create(array_filter([
                        'name_en' => $name,
                        'name_fr' => $args['name_fr'] ?? null,
                        'slug' => $args['slug'] ?? null,
                        'parent_id' => $args['parent_id'] ?? null,
                        'description_en' => $args['description_en'] ?? null,
                        'description_fr' => $args['description_fr'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null));

                    return ['id' => $category->getKey(), 'name_en' => $category->name_en, 'slug' => $category->slug];
                },
            ],

            'create_tag' => [
                'description' => 'Create a tag. The slug is derived from name_en automatically (numeric-suffixed on collision) unless an explicit `slug` is supplied.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                    'slug' => ['type' => 'string', 'description' => 'Optional explicit slug; auto-derived from name_en when omitted.'],
                ], ['name_en']),
                'handler' => function (array $args): array {
                    $name = trim((string) ($args['name_en'] ?? ''));
                    if ($name === '') {
                        throw new McpToolException('name_en is required.');
                    }
                    $class = (string) config('heisenberg.models.tag', Tag::class);
                    $tag = $class::create(array_filter([
                        'name_en' => $name,
                        'name_fr' => $args['name_fr'] ?? null,
                        'slug' => $args['slug'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null));

                    return ['id' => $tag->getKey(), 'name_en' => $tag->name_en, 'slug' => $tag->slug];
                },
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

            'detach_category' => [
                'description' => 'Detach a category from a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'category_id' => ['type' => 'integer'],
                ], ['post_id', 'category_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    $post->categories()->detach((int) $args['category_id']);

                    return ['detached' => true, 'post_id' => $post->getKey()];
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

            'detach_tag' => [
                'description' => 'Detach a tag from a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'tag_id' => ['type' => 'integer'],
                ], ['post_id', 'tag_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    $post->tags()->detach((int) $args['tag_id']);

                    return ['detached' => true, 'post_id' => $post->getKey()];
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

            // ── page settings ────────────────────────────────────────
            'set_page_layout' => [
                'description' => 'Set a post\'s page padding, in pixels (0-400). Mirrors the editor\'s Page Layout panel. Does not touch content or content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'page_padding_x' => ['type' => 'integer', 'description' => '0-400.'],
                    'page_padding_y' => ['type' => 'integer', 'description' => '0-400.'],
                ], ['post_id', 'page_padding_x', 'page_padding_y']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    if (! array_key_exists('page_padding_x', $args) || ! array_key_exists('page_padding_y', $args)) {
                        throw new McpToolException('page_padding_x and page_padding_y are both required.');
                    }
                    $x = (int) $args['page_padding_x'];
                    $y = (int) $args['page_padding_y'];
                    foreach (['page_padding_x' => $x, 'page_padding_y' => $y] as $label => $value) {
                        if ($value < 0 || $value > 400) {
                            throw new McpToolException("{$label} must be between 0 and 400 (got {$value}).");
                        }
                    }
                    $post->page_padding_x = $x;
                    $post->page_padding_y = $y;
                    $post->save();

                    return ['post_id' => $post->getKey(), 'page_padding_x' => $post->page_padding_x, 'page_padding_y' => $post->page_padding_y];
                },
            ],

            'set_discussion' => [
                'description' => 'Set whether comments are allowed on a post. Mirrors the editor\'s Discussion panel. Does not touch content or content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'allow_comments' => ['type' => 'boolean'],
                ], ['post_id', 'allow_comments']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    if (! array_key_exists('allow_comments', $args) || ! is_bool($args['allow_comments'])) {
                        throw new McpToolException('allow_comments must be a boolean.');
                    }
                    $post->allow_comments = $args['allow_comments'];
                    $post->save();

                    return ['post_id' => $post->getKey(), 'allow_comments' => $post->allow_comments];
                },
            ],

            // ── revisions ────────────────────────────────────────────
            'list_revisions' => [
                'description' => 'List a post\'s revision history, newest first (id, timestamp, type, title, block count). Pass a revision_id to restore_revision to roll back.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema(['post_id' => ['type' => 'integer']], ['post_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
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
                },
            ],

            'restore_revision' => [
                'description' => 'Restore a post to a prior revision\'s content. The post\'s CURRENT content is snapshotted as a new revision first (revision_type "restore" for the resulting save), so a restore is itself undoable via another restore_revision call. Bumps content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'revision_id' => ['type' => 'integer'],
                ], ['post_id', 'revision_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
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

                    $result = $this->writePost($post, ['blocks' => $blocks], 'restore');

                    return [
                        'post_id' => $post->getKey(),
                        'restored_from_revision' => (int) $row->getKey(),
                        'blocks_total' => $result['blocks'],
                        'content_version' => $result['content_version'],
                    ];
                },
            ],

            // ── theme ────────────────────────────────────────────────
            'get_theme' => [
                'description' => 'Read the active theme\'s design tokens as CSS custom properties (colors, font sizes, spacing, radii, fonts) under the --hb-t- namespace, with their current values. Use these variable names in authored content (e.g. var(--hb-t-accent-1) as a color/style value) instead of hardcoded values, so content honors the site theme.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([]),
                'handler' => function (): array {
                    $theme = $this->themes->load();
                    $prefix = ThemeRepository::CSS_PREFIX;
                    $variables = [];

                    foreach (['colors', 'fontSizes', 'spaces', 'radii'] as $group) {
                        foreach ($theme[$group] ?? [] as $token) {
                            $variables["--{$prefix}{$token['name']}"] = (string) $token['value'];
                        }
                    }
                    foreach ($theme['fonts'] ?? [] as $token) {
                        $family = (string) $token['family'];
                        $quoted = str_contains($family, ' ') ? "'{$family}'" : $family;
                        $variables["--{$prefix}{$token['name']}"] = "{$quoted}, sans-serif";
                    }

                    return ['variables' => $variables, 'groups' => $theme];
                },
            ],
        ];
    }

    /**
     * The one write path. Builds the editor's own save envelope, validates it,
     * and only then touches the database — inside a transaction, so a post is
     * never left with half its blocks. Every tool that edits a post's content
     * or replaces its whole tree (create_post, update_post, restore_revision)
     * funnels through here.
     *
     * @param  array<string, mixed>          $args
     * @param  'manual'|'auto_save'|'restore' $revisionType tag for the PRIOR-state
     *         snapshot captured before an existing post's content is replaced.
     * @return array<string, mixed>
     */
    private function writePost(?Post $existing, array $args, string $revisionType = 'manual'): array
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
            // Publishing is a lifecycle transition, not a content edit — see set_post_status
            // (editor-assistant surface only) for the real, config-gated transition path.
            throw new McpToolException("Setting status '{$status}' is not permitted over MCP; posts are created as drafts.");
        }

        $locale = array_key_exists('locale', $args) ? trim((string) $args['locale']) : null;
        if ($locale !== null && $locale !== '' && ! in_array($locale, ['en', 'fr'], true)) {
            throw new McpToolException("locale must be 'en' or 'fr' (got '{$locale}').");
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

        return DB::transaction(function () use ($existing, $args, $title, $locale, $blocks, $revisionType): array {
            $post = $existing ?? new ($this->postClass())();

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
     * Snapshot `$post`'s CURRENT (about-to-be-replaced) block tree into the
     * revisions table — the MCP-write equivalent of
     * {@see \Heisenberg\Http\Controllers\PostController::captureRevision()}.
     * Every content-replacing tool funnels through {@see self::writePost()},
     * so this is the ONE place an MCP-originated edit becomes reversible.
     *
     * @param 'manual'|'auto_save'|'restore' $type
     */
    private function captureRevision(Post $post, string $type): void
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

    /**
     * Apply a lifecycle transition exactly as
     * {@see \Heisenberg\Http\Controllers\PostController::applyTransition()}
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
        $post = $this->findPost($args['post_id'] ?? null);
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

        if (! $this->postPolicy->transitionAllowed($this->currentActor(), $target)) {
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

    /** The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every /editor controller uses. */
    private function currentActor(): Authenticatable
    {
        return Auth::user() ?? new GuestActor();
    }

    /** @return list<array<string, mixed>> */
    private function currentBlocks(Post $post): array
    {
        return $post->blocks()->orderBy('order')->get()->map(static fn ($b) => $b->content)->values()->all();
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
            throw new McpToolException('No post with id ' . (int) $id . '.');
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
