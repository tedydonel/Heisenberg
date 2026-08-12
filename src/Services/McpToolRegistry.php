<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\Revision;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Models\Tag;
use Heisenberg\Policies\PostPolicy;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        private TranslationStatusService $translationStatus,
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
            // $surface is passed as a second argument for the handful of handlers whose
            // behavior differs by surface (create_translation's draft-only posture on the
            // external server, see below) — every other handler simply ignores it, PHP
            // does not error when a closure is called with more arguments than it declares.
            return $this->ok($tools[$name]['handler']($arguments, $surface));
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
                'description' => 'List posts, newest first. Defaults to type "post" (blog/page documents) — pass type "email" to list email documents instead (docs/email-system.md §3).',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (1-100, default 20).'],
                    'status' => ['type' => 'string', 'description' => 'Filter by status, e.g. draft or published.'],
                    'type' => ['type' => 'string', 'description' => 'post or email. Defaults to post.'],
                ]),
                'handler' => function (array $args): array {
                    $type = trim((string) ($args['type'] ?? '')) ?: 'post';
                    if (! in_array($type, ['post', 'email'], true)) {
                        throw new McpToolException("type must be 'post' or 'email' (got '{$type}').");
                    }

                    $query = $this->postClass()::query()->where('type', $type)->orderByDesc('id');
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
                'description' => 'One post with its content as BOTH shortcode (`code` — edit this) and raw block JSON. To change the content, edit the shortcode and pass it back to update_post; pass content_version back too, to avoid clobbering a concurrent edit. `translations` maps every configured locale to that locale\'s row in this post\'s translation group (docs/content-translation.md) — the post\'s OWN locale reports status "source"; a locale with no sibling reports "missing" (post_id null); use create_translation to fill a gap.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                ], ['id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['id'] ?? null);
                    $blocks = $this->currentBlocks($post);

                    $translations = [];
                    foreach ($this->translationStatus->statuses($post) as $row) {
                        $translations[$row['locale']] = ['post_id' => $row['post_id'], 'status' => $row['status']];
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
                },
            ],

            'create_post' => [
                'description' => 'Create a post. Supply content as `code` (Heisenberg shortcode — preferred) or `blocks` (raw block JSON). Content is validated against the live block contracts and sanitized exactly as the editor does. Pass `type: "email"` to author an email document instead of a blog/page post (docs/email-system.md §3) — same authoring path, draft-only posture unchanged; render it with the EmailRenderer service or the bundled HeisenbergMailable, this tool never sends anything.',
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
                    'type' => ['type' => 'string', 'description' => 'post or email. Defaults to post.'],
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
                    $locale = (string) ($args['locale'] ?? LocaleConfig::default());
                    $locale = LocaleConfig::isValid($locale) ? $locale : LocaleConfig::default();

                    return ['html' => $this->renderer->renderBlocks($blocks, $locale)];
                },
            ],

            // ── translations (both surfaces) ────────────────────────
            // The split-row model (docs/content-translation.md §1): a post's translation is
            // its OWN heisenberg_posts row, sharing `translation_group_id` with its siblings.
            // This is the one tool that writes a sibling row: the caller reads the source
            // with get_post, translates the shortcode itself (structure/ids/attribute names/
            // media untouched — only human-readable text changes), and this tool writes the
            // sibling in one validated call, going through the exact same shortcode->blocks
            // validation create_post/update_post use (BlocksPayloadService, live contracts) —
            // there is no separate, looser path for translated content.
            'create_translation' => [
                'description' => 'Create or update a translation of a post — a sibling row in its translation group (docs/content-translation.md). '
                    . 'Reads the source with get_post, then pass the TRANSLATED shortcode as `code` (same block sequence and structure as the '
                    . 'source; only human-readable text is translated — never block names, attribute names, ids, URLs or media references). '
                    . 'If the target locale already has a sibling, its content is replaced (its lifecycle status is never touched); otherwise a '
                    . 'new draft sibling is created. Validated exactly like create_post/update_post. Translations SHARE ONE SLUG with their '
                    . 'source (locale comes from the host\'s URL prefix, not the slug) — a new sibling always gets the source post\'s exact '
                    . 'slug; the response\'s `slug` field reports it.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer', 'description' => 'Source post id to translate FROM.'],
                    'target_locale' => ['type' => 'string', 'description' => 'Locale to translate into (must differ from the source post\'s own locale), e.g. "fr".'],
                    'title' => ['type' => 'string', 'description' => 'Translated title.'],
                    'code' => ['type' => 'string', 'description' => 'The translated content as Heisenberg shortcode — same block sequence as the source, text translated.'],
                    'excerpt' => ['type' => 'string', 'description' => 'Translated excerpt, in the target locale.'],
                    'slug' => ['type' => 'string', 'description' => 'IGNORED — accepted only for backward wire-compatibility. Translations always share the source post\'s exact slug (docs/content-translation.md §1); the response\'s `slug` field always reports the real, shared value.'],
                ], ['post_id', 'target_locale', 'title', 'code']),
                'handler' => fn (array $args, string $surface): array => $this->createTranslation($args, $surface),
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

            // Bilingual taxonomy edit (docs/content-translation.md §6): category/tag names and
            // descriptions live on ONE row (unlike Post, which splits per locale — §1), so
            // "translating" a category is just filling in its name_fr/description_fr columns.
            'update_category' => [
                'description' => 'Update a category\'s bilingual name/description (e.g. fill in name_fr after create_category only set name_en). Supply at least one field; any field left out keeps its current value.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'category_id' => ['type' => 'integer'],
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                    'description_en' => ['type' => 'string'],
                    'description_fr' => ['type' => 'string'],
                ], ['category_id']),
                'handler' => function (array $args): array {
                    $class = (string) config('heisenberg.models.category', Category::class);
                    $category = $class::query()->find((int) ($args['category_id'] ?? 0));
                    if ($category === null) {
                        throw new McpToolException('No category with id ' . (int) ($args['category_id'] ?? 0) . '.');
                    }

                    $fields = $this->bilingualUpdateFields(
                        $args,
                        ['name_en', 'name_fr', 'description_en', 'description_fr'],
                        ['name_en' => 255, 'name_fr' => 255],
                    );
                    if (array_key_exists('name_en', $fields) && trim($fields['name_en']) === '') {
                        throw new McpToolException('name_en cannot be set to an empty string.');
                    }

                    foreach ($fields as $field => $value) {
                        $category->{$field} = $value;
                    }
                    $category->save();

                    return [
                        'id' => $category->getKey(),
                        'name_en' => $category->name_en,
                        'name_fr' => $category->name_fr,
                        'description_en' => $category->description_en,
                        'description_fr' => $category->description_fr,
                    ];
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

            'update_tag' => [
                'description' => 'Update a tag\'s bilingual name (e.g. fill in name_fr after create_tag only set name_en). Supply at least one field; any field left out keeps its current value.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'tag_id' => ['type' => 'integer'],
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                ], ['tag_id']),
                'handler' => function (array $args): array {
                    $class = (string) config('heisenberg.models.tag', Tag::class);
                    $tag = $class::query()->find((int) ($args['tag_id'] ?? 0));
                    if ($tag === null) {
                        throw new McpToolException('No tag with id ' . (int) ($args['tag_id'] ?? 0) . '.');
                    }

                    $fields = $this->bilingualUpdateFields($args, ['name_en', 'name_fr'], ['name_en' => 255, 'name_fr' => 255]);
                    if (array_key_exists('name_en', $fields) && trim($fields['name_en']) === '') {
                        throw new McpToolException('name_en cannot be set to an empty string.');
                    }

                    foreach ($fields as $field => $value) {
                        $tag->{$field} = $value;
                    }
                    $tag->save();

                    return ['id' => $tag->getKey(), 'name_en' => $tag->name_en, 'name_fr' => $tag->name_fr];
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

            // Metadata only — a much narrower grant than uploading bytes (list_media's docblock
            // above), so this is fine on the AUTHORS tier even though the surface stays
            // upload-free. `alt_text_en/_fr` and `caption_en/_fr` are the bilingual fields the
            // media library panel already draws; write REAL French, not a copy of the English
            // text (docs/content-translation.md's own posture, applied to media).
            'update_media' => [
                'description' => 'Update a media file\'s alt text, caption and/or credit line (both locales for alt/caption). Supply at least one field; any field left out keeps its current value. Does not touch the file bytes.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'file_id' => ['type' => 'integer', 'description' => 'Public file id (see list_media).'],
                    'alt_text_en' => ['type' => 'string', 'description' => 'Alt text, English. Max 255 characters.'],
                    'alt_text_fr' => ['type' => 'string', 'description' => 'Alt text, French. Max 255 characters.'],
                    'caption_en' => ['type' => 'string', 'description' => 'Caption, English. Max 500 characters.'],
                    'caption_fr' => ['type' => 'string', 'description' => 'Caption, French. Max 500 characters.'],
                    'credit' => ['type' => 'string', 'description' => 'Credit/attribution line. Max 255 characters.'],
                ], ['file_id']),
                'handler' => fn (array $args): array => $this->updateMedia($args),
            ],

            // ── SEO ──────────────────────────────────────────────────
            // docs/seo-system.md §6. get_seo/analyze_seo are read-only; update_seo is the one
            // write path, matching NativeSeoMetaProvider/PostController::applySeo's own
            // updateOrCreate-on-(able_type,able_id) shape so the DB never carries two rows for
            // one post. Available on both surfaces — SEO metadata is a post attribute, not the
            // live canvas, so it makes as much sense to an external agent as set_page_layout/
            // set_discussion/set_featured_image below (page-settings tools, same "no `surface`
            // entry" posture).
            'get_seo' => [
                'description' => 'Read a post\'s SEO/social metadata row (all fields, both locales) — the SeoMeta row create_post/update_post never touch. `has_seo` is false when the post has no row yet (nothing has been set). Does not run the SEO analyzer — call analyze_seo for that.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema(['post_id' => ['type' => 'integer']], ['post_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    $seo = $this->seoMetaClass()::query()
                        ->where('able_type', $post->getMorphClass())
                        ->where('able_id', $post->getKey())
                        ->first();

                    return [
                        'post_id' => $post->getKey(),
                        'has_seo' => $seo !== null,
                        'seo' => $seo === null ? null : $this->seoMetaPayload($seo),
                    ];
                },
            ],

            'update_seo' => [
                'description' => 'Set a post\'s SEO/social metadata. `locale` routes meta_title/meta_description/og_title/og_description/focus_keyphrase to that locale\'s column (defaults to the post\'s own locale); og_image/canonical_url/robots/schema_type/schema_data/in_sitemap are locale-neutral and apply directly. Supply at least one field. updateOrCreate\'s the row (no prior get_seo call required). robots is comma-separated tokens from index/noindex/follow/nofollow. schema_data is a JSON object merged under the computed JSON-LD defaults (see get_seo). Returns the updated row, same shape as get_seo.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'locale' => ['type' => 'string', 'description' => 'Which locale meta_title/meta_description/og_title/og_description/focus_keyphrase apply to. Defaults to the post\'s own locale.'],
                    'meta_title' => ['type' => 'string', 'description' => 'Localized. Ideal length 30-60 characters. Max 255.'],
                    'meta_description' => ['type' => 'string', 'description' => 'Localized. Ideal length 50-160 characters. Max 255.'],
                    'og_title' => ['type' => 'string', 'description' => 'Localized. Max 255.'],
                    'og_description' => ['type' => 'string', 'description' => 'Localized. Max 255.'],
                    'og_image' => ['type' => 'string', 'description' => 'Locale-neutral. An image URL. Max 255.'],
                    'canonical_url' => ['type' => 'string', 'description' => 'Locale-neutral. An absolute URL. Max 255.'],
                    'robots' => ['type' => 'string', 'description' => 'Locale-neutral. Comma-separated tokens from index/noindex/follow/nofollow, e.g. "index, follow". Max 255.'],
                    'focus_keyphrase' => ['type' => 'string', 'description' => 'Localized. The phrase analyze_seo scores this post against. Max 255.'],
                    'in_sitemap' => ['type' => 'boolean', 'description' => 'Locale-neutral. Include this post in /sitemap.xml.'],
                    'schema_type' => ['type' => 'string', 'description' => 'Locale-neutral. Schema.org @type, e.g. "Article". Max 255.'],
                    'schema_data' => ['type' => 'object', 'description' => 'Locale-neutral. Extra JSON-LD keys merged under the computed defaults.'],
                ], ['post_id']),
                'handler' => fn (array $args): array => $this->updateSeo($args),
            ],

            'analyze_seo' => [
                'description' => 'Run the SEO checklist/score against a post\'s SAVED SeoMeta + content (no draft overrides — this is the tool surface, not the editor panel\'s live re-scoring). Returns {score, rating, checks[]} — each check has id/group/status(pass|warn|fail)/weight/message. Workflow: analyze_seo, fix the worst-weighted fail/warn checks with update_seo (or by editing content), analyze_seo again.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'locale' => ['type' => 'string', 'description' => 'Defaults to the post\'s own locale.'],
                ], ['post_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    $locale = $this->resolveSeoLocale($args, $post);
                    $analyzer = app(\Heisenberg\Services\SeoAnalyzer::class);

                    return $analyzer->analyze($post, $locale);
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

            // Mirrors PostSettingsController::updateFeaturedImage's posture exactly (direct
            // property write on the guarded `featured_image_id` column — see Post::$fillable's
            // own docblock) — no `surface` entry, same as set_page_layout/set_discussion just
            // above: a featured image is a lightweight post setting, not a content write that
            // needs the draft-only external-surface posture create_post/update_post hold.
            'set_featured_image' => [
                'description' => 'Set (or clear) a post\'s featured image. Pass file_id to set it, or omit/null to clear it. Mirrors the editor\'s Featured image setting. Does not touch content or content_version. A featured image is language-neutral: this propagates the same file_id (or null, when clearing) to every sibling in the post\'s translation group, so a group never ends up with the image set on only one locale.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer'],
                    'file_id' => ['type' => 'integer', 'description' => 'Public file id (see list_media). Omit or pass null to clear the featured image.'],
                ], ['post_id']),
                'handler' => fn (array $args): array => $this->setFeaturedImage($args),
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
        $blocks = $this->validatedContentBlocks($args, allowEmpty: $existing !== null);

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
     * Shortcode/JSON -> validated, hydrated block models, ready to persist. The exact
     * pipeline `write_canvas` validates against (parse via {@see self::contentBlocks()},
     * against the SAME live contracts — {@see ShortcodeParser} reports the identical
     * line-numbered errors either way) PLUS the hydrate + {@see BlocksPayloadService}
     * step that stamps ids/schemaVersion/attribute defaults and re-checks the result —
     * the extra step every path that actually WRITES to the database needs (write_canvas
     * never persists, so it stops after the parse). {@see self::writePost()} and
     * {@see self::createTranslation()} — the two tools that materialize a content tree —
     * both funnel through here, so "valid content" cannot mean something different
     * between authoring a post and translating one.
     *
     * @param  array<string, mixed>            $args
     * @return list<array<string, mixed>>|null null means "no content supplied"
     */
    private function validatedContentBlocks(array $args, bool $allowEmpty = false): ?array
    {
        $blocks = $this->contentBlocks($args, allowEmpty: $allowEmpty);
        if ($blocks === null) {
            return null;
        }

        // The parser produces bare models, exactly as the JS parser does — in the
        // browser it is replaceDoc() that stamps each one with an id, the contract's
        // schemaVersion and the attribute defaults. There is no replaceDoc here, so
        // this is that step. Without it every write fails validation on `missing key 'id'`.
        $blocks = $this->hydrateBlocks($blocks);

        $checked = $this->payload->validatePayload([
            'schemaVersion' => 1,
            // Computed live rather than quoted by the caller: an MCP client holds no
            // page snapshot that could be stale.
            'registryHash' => $this->registry->computeHash(),
            'blocks' => $blocks,
        ]);

        if (! ($checked['valid'] ?? false)) {
            throw new McpToolException('Content rejected: ' . implode('; ', (array) ($checked['errors'] ?? ['invalid payload'])));
        }

        return $checked['blocks'] ?? $blocks;
    }

    /**
     * Materialize `$blocks` as `$post`'s ONLY block rows — replaces whatever was there.
     * Shared by {@see self::writePost()} and {@see self::createTranslation()}, the two
     * paths that persist a content tree, so there is exactly one place that knows how a
     * hydrated+validated block model becomes a `heisenberg_post_blocks` row. Does NOT
     * bump `content_version` or snapshot a revision — a caller replacing an EXISTING
     * post's tree must call {@see self::captureRevision()} first and bump the version
     * after, same as `writePost()` does around its own call to this method.
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
     * `create_translation` — writes a sibling row in `$args['post_id']`'s translation
     * group (docs/content-translation.md §1, §6). Creates the sibling as a draft when
     * the target locale has none yet; otherwise replaces its content tree in place
     * WITHOUT ever touching its lifecycle status (publishing/unpublishing a translation
     * stays a human decision via the normal Summary control / `set_post_status`).
     *
     * Surface posture: the EXTERNAL server refuses to update an already-published (or
     * otherwise non-draft) sibling at all — the same draft-only posture `create_post`/
     * `update_post` hold on that surface — because there is no human reviewing the
     * result before it goes live. The EDITOR surface may update a published sibling: a
     * human is driving and reviews the diff before saving, same reasoning
     * `set_post_status` already rests on.
     *
     * @param  array<string, mixed> $args
     * @return array{post_id: int|string, locale: string, status: string, slug: string, outdated: bool}
     */
    private function createTranslation(array $args, string $surface): array
    {
        $source = $this->findPost($args['post_id'] ?? null);

        $targetLocale = trim((string) ($args['target_locale'] ?? ''));
        if ($targetLocale === '' || ! LocaleConfig::isValid($targetLocale)) {
            $allowed = implode(', ', LocaleConfig::locales());
            throw new McpToolException("target_locale must be one of: {$allowed} (got '{$targetLocale}').");
        }

        $sourceLocale = (string) ($source->locale ?: LocaleConfig::default());
        if ($targetLocale === $sourceLocale) {
            throw new McpToolException("target_locale ('{$targetLocale}') must differ from the source post's own locale ('{$sourceLocale}').");
        }

        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw new McpToolException('title is required.');
        }

        // Same validation write_canvas/create_post/update_post use — see
        // validatedContentBlocks()'s own docblock. allowEmpty is always false here:
        // a translation with no content is never useful, whether creating or updating.
        $blocks = $this->validatedContentBlocks(['code' => (string) ($args['code'] ?? '')]);

        $hasExcerpt = array_key_exists('excerpt', $args) && is_string($args['excerpt']);
        $excerpt = $hasExcerpt ? $args['excerpt'] : null;

        // `slug` (if supplied) is accepted for wire-compat only and otherwise IGNORED — see the
        // shared-slug invariant, docs/content-translation.md §1: a translation group presents as
        // ONE post, so a sibling always carries its source's EXACT slug, never a caller-supplied
        // one that could diverge from it. The response's own `slug` key always reports the real,
        // shared value, so a caller that passed a different one learns the truth instead of
        // silently assuming its input won.

        return DB::transaction(function () use ($source, $targetLocale, $title, $blocks, $hasExcerpt, $excerpt, $surface): array {
            $sibling = $source->sibling($targetLocale);

            if ($sibling === null) {
                // Checked BEFORE any write (no partial state): an unrelated post already holding
                // the source's exact slug text in $targetLocale refuses the WHOLE translation
                // rather than silently drifting the sibling onto a different slug than its source.
                $slugTaken = $this->postClass()::query()->withTrashed()
                    ->where('locale', $targetLocale)
                    ->where('slug', $source->slug)
                    ->exists();
                if ($slugTaken) {
                    throw new McpToolException(
                        "Cannot create the {$targetLocale} translation: its shared slug \"{$source->slug}\" is already used by another post in that language."
                    );
                }

                $sibling = new ($this->postClass())();
                $sibling->translation_group_id = $source->translation_group_id ?: (string) Str::uuid();
                if (empty($source->translation_group_id)) {
                    $source->translation_group_id = $sibling->translation_group_id;
                    $source->save();
                }
                $sibling->locale = $targetLocale;
                // title_en is NOT NULL (migration 2026_01_01_000001) on every row regardless of
                // its own locale — see the existing en/fr fixtures across tests/Translation/*.
                // A new row therefore needs BOTH columns populated: the target locale's column
                // gets the translated title, the OTHER carries the source's own title as a
                // same-row fallback (Post::title() reads it when the primary column is empty).
                $sibling->title_en = $targetLocale === 'en' ? $title : (string) ($source->title_en ?: $title);
                $sibling->title_fr = $targetLocale === 'fr' ? $title : $source->title_fr;
                if ($hasExcerpt) {
                    $this->setLocaleField($sibling, $targetLocale, 'excerpt', $excerpt);
                }
                $sibling->slug = $source->slug;
                $sibling->featured_image_id = $source->featured_image_id;
                $sibling->page_padding_x = $source->page_padding_x;
                $sibling->page_padding_y = $source->page_padding_y;
                $sibling->allow_comments = $source->allow_comments;
                $sibling->status = 'draft';
                // A translation is the same DOCUMENT in another language — an email's sibling
                // stays an email. `type` is guarded with DB default 'post', so it must be
                // copied explicitly or it silently reverts.
                $sibling->type = $source->type;
                $sibling->save();

                $this->replaceBlocks($sibling, $blocks);
                $sibling->bumpContentVersion();
            } else {
                if ($surface === self::SURFACE_EXTERNAL && $sibling->status !== 'draft') {
                    throw new McpToolException(
                        "The {$targetLocale} translation is already \"{$sibling->status}\"; the external MCP surface only updates draft translations."
                    );
                }

                $this->captureRevision($sibling, 'manual');

                $this->setLocaleField($sibling, $targetLocale, 'title', $title);
                if ($hasExcerpt) {
                    $this->setLocaleField($sibling, $targetLocale, 'excerpt', $excerpt);
                }
                // slug is deliberately left untouched — the shared-slug invariant means it
                // already matches the source; there is no per-translation slug left to update.
                $sibling->save();

                $this->replaceBlocks($sibling, $blocks);
                $sibling->bumpContentVersion();
            }

            // translated_from_version is deliberately not fillable (see Post::$fillable's
            // docblock) — direct property write is the only path, same as content_version.
            $sibling->translated_from_version = (int) $source->content_version;
            $sibling->save();
            $sibling->refresh();

            return [
                'post_id' => $sibling->getKey(),
                'locale' => $targetLocale,
                'status' => (string) $sibling->status,
                // The real, shared slug — see the note above args['slug'] on why a caller-supplied
                // value never lands here even when one was sent.
                'slug' => (string) $sibling->slug,
                // Always false: translated_from_version was just set to the source's
                // CURRENT content_version, so isTranslationOutdated() cannot yet be true.
                'outdated' => false,
            ];
        });
    }

    /** Writes `$value` into `{$field}_en` or `{$field}_fr` depending on `$locale` — the bilingual-column shape `title_en`/`title_fr` and `excerpt_en`/`excerpt_fr` share (docs/content-translation.md §3 caps real support at en/fr). */
    private function setLocaleField(Post $post, string $locale, string $field, ?string $value): void
    {
        $column = $field . '_' . ($locale === 'fr' ? 'fr' : 'en');
        $post->{$column} = $value;
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

    /**
     * `update_seo` — validates and `updateOrCreate`s a post's {@see SeoMeta} row
     * (docs/seo-system.md §6). The localized fields (meta_title, meta_description, og_title,
     * og_description, focus_keyphrase) route to `{field}_{locale}`; the rest are locale-neutral
     * columns written as-is. At least one field must be present — an update with nothing to
     * change is a caller mistake, same posture as {@see self::bilingualUpdateFields()}.
     *
     * @param array<string, mixed> $args
     */
    private function updateSeo(array $args): array
    {
        $post = $this->findPost($args['post_id'] ?? null);
        $locale = $this->resolveSeoLocale($args, $post);

        $localizedFields = ['meta_title', 'meta_description', 'og_title', 'og_description', 'focus_keyphrase'];
        $neutralStringFields = ['og_image' => 255, 'canonical_url' => 255, 'robots' => 255, 'schema_type' => 255];
        $allFields = [...$localizedFields, ...array_keys($neutralStringFields), 'in_sitemap', 'schema_data'];

        if (array_intersect_key($args, array_flip($allFields)) === []) {
            throw new McpToolException('Supply at least one of: ' . implode(', ', $allFields) . '.');
        }

        $data = [];

        foreach ($localizedFields as $field) {
            if (! array_key_exists($field, $args) || ! is_string($args[$field])) {
                continue;
            }
            $value = trim($args[$field]);
            if (mb_strlen($value) > 255) {
                throw new McpToolException("{$field} must be 255 characters or fewer (got " . mb_strlen($value) . ').');
            }
            $data["{$field}_{$locale}"] = $value;
        }

        foreach ($neutralStringFields as $field => $cap) {
            if (! array_key_exists($field, $args) || ! is_string($args[$field])) {
                continue;
            }
            $value = trim($args[$field]);
            if (mb_strlen($value) > $cap) {
                throw new McpToolException("{$field} must be {$cap} characters or fewer (got " . mb_strlen($value) . ').');
            }
            if ($field === 'robots' && $value !== '') {
                $this->validateRobots($value);
            }
            $data[$field] = $value;
        }

        if (array_key_exists('in_sitemap', $args)) {
            if (! is_bool($args['in_sitemap'])) {
                throw new McpToolException('in_sitemap must be a boolean.');
            }
            $data['in_sitemap'] = $args['in_sitemap'];
        }

        if (array_key_exists('schema_data', $args)) {
            if (! is_array($args['schema_data'])) {
                throw new McpToolException('schema_data must be a JSON object.');
            }
            $data['schema_data'] = $args['schema_data'];
        }

        $seo = $this->seoMetaClass()::query()->updateOrCreate(
            ['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()],
            $data,
        );

        return [
            'post_id' => $post->getKey(),
            'has_seo' => true,
            'seo' => $this->seoMetaPayload($seo),
        ];
    }

    /** Comma-separated tokens, each one of index/noindex/follow/nofollow (case-insensitive). */
    private function validateRobots(string $robots): void
    {
        $allowed = ['index', 'noindex', 'follow', 'nofollow'];
        foreach (explode(',', $robots) as $token) {
            $token = strtolower(trim($token));
            if (! in_array($token, $allowed, true)) {
                throw new McpToolException(
                    "robots may only contain comma-separated tokens from index/noindex/follow/nofollow (got \"" . trim($token) . '").'
                );
            }
        }
    }

    /** @return array<string, mixed> same shape get_seo and update_seo both return under `seo`. */
    private function seoMetaPayload(SeoMeta $seo): array
    {
        return [
            'meta_title_en' => $seo->meta_title_en,
            'meta_title_fr' => $seo->meta_title_fr,
            'meta_description_en' => $seo->meta_description_en,
            'meta_description_fr' => $seo->meta_description_fr,
            'og_title_en' => $seo->og_title_en,
            'og_title_fr' => $seo->og_title_fr,
            'og_description_en' => $seo->og_description_en,
            'og_description_fr' => $seo->og_description_fr,
            'focus_keyphrase_en' => $seo->focus_keyphrase_en,
            'focus_keyphrase_fr' => $seo->focus_keyphrase_fr,
            'og_image' => $seo->og_image,
            'canonical_url' => $seo->canonical_url,
            'robots' => $seo->robots,
            'schema_type' => $seo->schema_type,
            'schema_data' => $seo->schema_data,
            'in_sitemap' => (bool) $seo->in_sitemap,
        ];
    }

    /** `locale` argument when valid, else the post's own locale, else the app default — used by every SEO tool. */
    private function resolveSeoLocale(array $args, Post $post): string
    {
        $locale = trim((string) ($args['locale'] ?? ''));
        if ($locale === '') {
            $locale = (string) ($post->locale ?: LocaleConfig::default());
        }
        if (! LocaleConfig::isValid($locale)) {
            $allowed = implode(', ', LocaleConfig::locales());
            throw new McpToolException("locale must be one of: {$allowed} (got '{$locale}').");
        }

        return $locale;
    }

    /** @return class-string<SeoMeta> */
    private function seoMetaClass(): string
    {
        return (string) config('heisenberg.models.seo_meta', SeoMeta::class);
    }

    /**
     * `update_media` — alt/caption (bilingual) + credit on a {@see PublicFile}. Reuses
     * {@see self::bilingualUpdateFields()} (the same "at least one field, each capped at its
     * column's length" rule `update_category`/`update_tag` already use) — the field/cap set is
     * just different here, the validation shape is identical.
     *
     * @param array<string, mixed> $args
     */
    private function updateMedia(array $args): array
    {
        $class = (string) config('heisenberg.models.public_file', PublicFile::class);
        $file = $class::query()->find((int) ($args['file_id'] ?? 0));
        if ($file === null) {
            throw new McpToolException('No media file with id ' . (int) ($args['file_id'] ?? 0) . '.');
        }

        $fields = $this->bilingualUpdateFields(
            $args,
            ['alt_text_en', 'alt_text_fr', 'caption_en', 'caption_fr', 'credit'],
            ['alt_text_en' => 255, 'alt_text_fr' => 255, 'caption_en' => 500, 'caption_fr' => 500, 'credit' => 255],
        );

        foreach ($fields as $field => $value) {
            $file->{$field} = $value;
        }
        $file->save();

        return [
            'id' => $file->getKey(),
            'url' => (string) $file->url,
            'alt_text_en' => $file->alt_text_en,
            'alt_text_fr' => $file->alt_text_fr,
            'caption_en' => $file->caption_en,
            'caption_fr' => $file->caption_fr,
            'credit' => $file->credit,
        ];
    }

    /**
     * `set_featured_image` — direct property write on `Post::$featured_image_id` (guarded, same
     * posture as {@see \Heisenberg\Http\Controllers\PostSettingsController::updateFeaturedImage()}).
     * `file_id` null/omitted clears it; a non-null id must point at a real, image-type
     * {@see PublicFile} — {@see PublicFile::isImageType()} makes that check cheap enough not to
     * skip: a featured image slot rendered as a PDF icon is a worse failure mode than refusing
     * the write here.
     *
     * PROPAGATES group-wide, same posture and same reasoning as
     * {@see \Heisenberg\Http\Controllers\PostSettingsController::updateFeaturedImage()}'s own
     * docblock: an agent must not create the "set it twice" inconsistency the UI now prevents.
     * The response shape is unchanged (still just this post's own id/featured_image_id) —
     * propagation is a side effect, not a new return value.
     *
     * @param array<string, mixed> $args
     */
    private function setFeaturedImage(array $args): array
    {
        $post = $this->findPost($args['post_id'] ?? null);
        $fileId = $args['file_id'] ?? null;

        if ($fileId === null) {
            return DB::transaction(function () use ($post): array {
                $post->featured_image_id = null;
                $post->save();
                $this->propagateFeaturedImageToSiblings($post, null);

                return ['post_id' => $post->getKey(), 'featured_image_id' => null];
            });
        }

        $class = (string) config('heisenberg.models.public_file', PublicFile::class);
        $file = $class::query()->find((int) $fileId);
        if ($file === null) {
            throw new McpToolException('No media file with id ' . (int) $fileId . '.');
        }
        if (! $file->isImageType()) {
            throw new McpToolException("File {$file->getKey()} is type \"{$file->type}\", not an image — the featured image must be an image file.");
        }

        return DB::transaction(function () use ($post, $file): array {
            $post->featured_image_id = $file->getKey();
            $post->save();
            $this->propagateFeaturedImageToSiblings($post, $post->featured_image_id);

            return ['post_id' => $post->getKey(), 'featured_image_id' => $post->featured_image_id];
        });
    }

    /**
     * Plain query UPDATE onto every sibling in `$post`'s translation group — shared by
     * `setFeaturedImage()` above. Same "no per-row ->save()" reasoning as
     * `PostSettingsController::updateFeaturedImage()`'s own docblock: a sibling's content/title
     * is untouched, only its featured_image_id column changes.
     */
    private function propagateFeaturedImageToSiblings(Post $post, ?int $featuredImageId): void
    {
        $siblings = $post->siblings();
        if ($siblings->isEmpty()) {
            return;
        }

        $post::query()
            ->whereKey($siblings->pluck($post->getKeyName())->all())
            ->update(['featured_image_id' => $featuredImageId]);
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

    /**
     * The provided-and-valid subset of `$args` for `update_category`/`update_tag` — every
     * field is optional, but at least one must be present (an update with nothing to change
     * is a caller mistake, not a no-op worth silently accepting), and each is capped at its
     * column's actual length (`string` columns are 255 in the categories/tags migrations;
     * `text` columns like a category's description are not listed in `$caps` and so stay
     * uncapped here — MySQL/SQLite's own TEXT limit is generous enough not to need a
     * second, arbitrary ceiling).
     *
     * @param  array<string, mixed> $args
     * @param  list<string>         $fields
     * @param  array<string, int>   $caps
     * @return array<string, string>
     */
    private function bilingualUpdateFields(array $args, array $fields, array $caps = []): array
    {
        $provided = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $args) && is_string($args[$field])) {
                $provided[$field] = $args[$field];
            }
        }

        if ($provided === []) {
            throw new McpToolException('Supply at least one of: ' . implode(', ', $fields) . '.');
        }

        foreach ($caps as $field => $cap) {
            if (array_key_exists($field, $provided) && mb_strlen($provided[$field]) > $cap) {
                throw new McpToolException("{$field} must be {$cap} characters or fewer (got " . mb_strlen($provided[$field]) . ').');
            }
        }

        return $provided;
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
