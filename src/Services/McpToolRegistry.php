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
use Heisenberg\Support\LocalizedAttributes;
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
            // $surface is passed as a second argument for the (currently none) handlers whose
            // behavior differs by surface once past the tier/surface gate above — every handler
            // simply ignores it today, PHP does not error when a closure is called with more
            // arguments than it declares. Kept so a future handler can opt into it without a
            // signature change here.
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
                // docs/content-translation.md §0/Wave 2: the editor turn's `editing_locale`/
                // `home_locale` (see EditorPrompt::user()) tell the model when it is translating,
                // not this tool — write_canvas has no view of the editor's current document (it
                // lives in the browser, possibly never saved), so it cannot itself compare the
                // supplied code's structure against what is already on the canvas. That
                // position-matched comparison, and the actual non-replacing fold, happen
                // CLIENT-side (block-runtime.blade.php's foldTranslation, applied by panel-ai's
                // applyCanvasTool) the moment this call's arguments land on the stream — mirroring
                // McpToolRegistry::foldTranslatedBlocks(), the same rule create_translation
                // enforces server-side for a SAVED post. This description restates the rule so a
                // model that skims tool descriptions instead of the system prompt still gets it.
                'description' => 'Write Heisenberg shortcode directly into the editor the user is looking at. '
                    . 'The blocks land on the canvas immediately — this is THE way to build or edit the current '
                    . 'page. mode "append" (default) adds the blocks after what is already on the page; mode '
                    . '"replace" swaps the whole document for the supplied code (pass the full updated document '
                    . 'to rework or restructure existing content). If the editor is showing a locale other than '
                    . "the post's home locale (see the user turn's editing/home locale), you are TRANSLATING: "
                    . 'reproduce the SAME block sequence with only human-readable text changed — never add, '
                    . 'remove, or reorder blocks, and never change ids/urls/media refs; the editor applies this '
                    . 'as a position-matched fold and rejects (with no partial change) a mismatched structure. '
                    . 'mode="append" is refused while translating — tell the user to switch to the home locale '
                    . 'to add new blocks. Nothing is saved to the database — the user reviews and saves. The '
                    . 'code is validated against the live block contracts; on a parse error nothing is applied '
                    . 'and the error names the line to fix.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => $this->schema([
                    'code' => ['type' => 'string', 'description' => 'The content, as Heisenberg shortcode.'],
                    'mode' => ['type' => 'string', 'description' => '"append" (default) adds after the current page content; "replace" swaps the whole document. "append" is refused while translating a non-home locale.'],
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
                'description' => 'One post with its content as BOTH shortcode (`code` — edit this) and raw block JSON. To change the content, edit the shortcode and pass it back to update_post; pass content_version back too, to avoid clobbering a concurrent edit. `translations` maps every configured locale to its translation COMPLETENESS on this SAME row (docs/content-translation.md §0 — a translation is locale-suffixed attribute variants on the one post, not a separate row): `{is_default, title, excerpt, blocks_translated, blocks_total, complete}` — `title`/`excerpt` are booleans (that locale\'s column has content), `blocks_translated`/`blocks_total` count translatable blocks, `complete` is overall per-locale readiness. Use create_translation to fill in a gap.',
                'tier' => self::TIER_READ,
                'inputSchema' => $this->schema([
                    'id' => ['type' => 'integer', 'description' => 'Post id.'],
                ], ['id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['id'] ?? null);
                    $blocks = $this->currentBlocks($post);

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
            // The single-row model (docs/content-translation.md §0): a post's translation is
            // NOT a separate row — it is locale-suffixed attribute variants (`content_fr`, …) on
            // the SAME row. This tool keeps its Wave-1 name (existing agents already call it) but
            // its job changed: it translates THIS post's fields in place. `title`/`excerpt` write
            // straight to `title_<locale>`/`excerpt_<locale>`; `code` is parsed and validated
            // through the exact same pipeline create_post/update_post use (BlocksPayloadService,
            // live contracts), then folded into the EXISTING stored blocks' translatable
            // attributes as `_<locale>` variants, matched BY POSITION (top-level index, then
            // recursively through innerBlocks) — the block TREE itself is never replaced,
            // because structure is shared across every locale now. A translated document whose
            // shape (block count, or a block name at any position/depth) doesn't match what's
            // stored is refused outright, naming the mismatch, rather than silently corrupting
            // the post's structure.
            'create_translation' => [
                'description' => 'Translate an existing post\'s fields into another locale — writes locale-suffixed variants on the SAME row '
                    . '(docs/content-translation.md §0), it does not create a new post. Read the source with get_post first (its `code` is the '
                    . 'structure to translate). Supply at least one of `title`/`excerpt`/`code`. `title`/`excerpt` are translated text written to '
                    . '`title_<locale>`/`excerpt_<locale>`. `code` must be the SAME block sequence and structure as the post\'s current content '
                    . '(only human-readable text translated — never block names, attribute names, ids, URLs or media references); it is validated '
                    . 'like update_post, then folded into the existing blocks by position — a structural mismatch (different block count, or a '
                    . 'different block at some position) is refused with an error naming where, not silently applied. Available on both surfaces '
                    . 'without a draft-only restriction: this edits fields of an existing post, it never changes or creates its publish status. '
                    . 'Returns the target locale\'s translation completeness from get_post\'s own `translations` shape.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema([
                    'post_id' => ['type' => 'integer', 'description' => 'Post id to translate.'],
                    'target_locale' => ['type' => 'string', 'description' => 'Locale to translate into (must differ from the post\'s own home locale), e.g. "fr".'],
                    'title' => ['type' => 'string', 'description' => 'Translated title, written to title_<target_locale>.'],
                    'excerpt' => ['type' => 'string', 'description' => 'Translated excerpt, written to excerpt_<target_locale>.'],
                    'code' => ['type' => 'string', 'description' => 'The translated document as Heisenberg shortcode — same block sequence and structure as the post\'s stored content, text translated. Folded into the existing blocks by position, never replaces the tree.'],
                ], ['post_id', 'target_locale']),
                'handler' => fn (array $args): array => $this->createTranslation($args),
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
                'description' => 'Set (or clear) a post\'s featured image. Pass file_id to set it, or omit/null to clear it. Mirrors the editor\'s Featured image setting. Does not touch content or content_version.',
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

            // ── trash ────────────────────────────────────────────────
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
                'inputSchema' => $this->schema(['post_id' => ['type' => 'integer']], ['post_id']),
                'handler' => function (array $args): array {
                    $post = $this->findPost($args['post_id'] ?? null);
                    if (! $this->postPolicy->delete($this->currentActor(), $post)) {
                        throw new McpToolException('You are not authorized to trash this post.');
                    }
                    $post->delete();
                    $post->refresh();

                    return ['post_id' => $post->getKey(), 'trashed' => true, 'deleted_at' => $post->deleted_at?->toIso8601String()];
                },
            ],

            'restore_post' => [
                'description' => 'Restore a post previously moved to the trash by trash_post. Its blocks and revisions from that same trash batch are restored with it.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => $this->schema(['post_id' => ['type' => 'integer']], ['post_id']),
                'handler' => function (array $args): array {
                    $id = (int) ($args['post_id'] ?? 0);
                    $post = $this->postClass()::withTrashed()->find($id);
                    if ($post === null) {
                        throw new McpToolException("No post with id {$id}.");
                    }
                    if (! $post->trashed()) {
                        throw new McpToolException("Post {$id} is not trashed.");
                    }
                    if (! $this->postPolicy->restore($this->currentActor(), $post)) {
                        throw new McpToolException('You are not authorized to restore this post.');
                    }
                    $post->restore();

                    return ['post_id' => $post->getKey(), 'trashed' => false];
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
     * `create_translation` — translates `$args['post_id']`'s OWN fields into `target_locale`
     * (docs/content-translation.md §0, §6, rewritten for the single-row model: this tool keeps
     * its Wave-1 name but no longer creates a sibling row — there is nothing left to sibling).
     * `title`/`excerpt` write straight to `title_<locale>`/`excerpt_<locale>` on the SAME row.
     * `code`, if supplied, is validated through the exact same pipeline `update_post` uses (see
     * {@see self::validatedContentBlocks()}), then folded into the post's EXISTING stored blocks'
     * translatable attributes as `_<locale>` variants, matched BY POSITION
     * ({@see self::foldTranslatedBlocks()}) — the stored block TREE itself is never replaced,
     * because structure is shared across every locale now.
     *
     * Never touches lifecycle status, slug, or any other post setting — it edits translated TEXT
     * fields only, on a post that already exists.
     *
     * Surface posture (owner decision, this wave): available on BOTH surfaces with no draft-only
     * restriction, unlike `create_post`/`update_post`. Those hold a draft-only posture on the
     * external surface because an unattended agent could otherwise ship unreviewed content live.
     * This tool cannot do that — it never creates a post and never changes a post's `status`; the
     * worst it can do is add/replace translated text on a post whose PUBLISH state a human (or
     * `set_post_status`, editor-surface only) already decided independently. Restricting it to
     * drafts on the external surface would only block the exact workflow it exists for — "loop
     * list_posts -> create_translation over an already-published catalog" — for no safety gained.
     *
     * @param  array<string, mixed> $args
     * @return array{post_id: int|string, locale: string, complete: bool, blocks_translated: int, blocks_total: int}
     */
    private function createTranslation(array $args): array
    {
        $post = $this->findPost($args['post_id'] ?? null);

        $targetLocale = trim((string) ($args['target_locale'] ?? ''));
        if ($targetLocale === '' || ! LocaleConfig::isValid($targetLocale)) {
            $allowed = implode(', ', LocaleConfig::locales());
            throw new McpToolException("target_locale must be one of: {$allowed} (got '{$targetLocale}').");
        }

        $homeLocale = (string) ($post->locale ?: LocaleConfig::default());
        if ($targetLocale === $homeLocale) {
            throw new McpToolException(
                "target_locale ('{$targetLocale}') must differ from the post's own home locale ('{$homeLocale}') — there is nothing to translate into its own language."
            );
        }

        $hasTitle = array_key_exists('title', $args) && is_string($args['title']);
        $hasExcerpt = array_key_exists('excerpt', $args) && is_string($args['excerpt']);
        $hasCode = array_key_exists('code', $args) && is_string($args['code']) && trim($args['code']) !== '';

        if (! $hasTitle && ! $hasExcerpt && ! $hasCode) {
            throw new McpToolException('Supply at least one of: title, excerpt, code — there is nothing to translate.');
        }

        // Parsed + validated through the SAME pipeline update_post uses
        // (validatedContentBlocks()'s own docblock) BEFORE any write, so a bad translation never
        // lands half-applied. The position-matched fold against the STORED tree happens inside
        // the transaction below, once we know nothing else about the call will fail first.
        $translatedBlocks = $hasCode ? $this->validatedContentBlocks(['code' => (string) $args['code']]) : null;

        return DB::transaction(function () use ($post, $targetLocale, $args, $hasTitle, $hasExcerpt, $translatedBlocks): array {
            if ($hasTitle) {
                $this->setLocaleField($post, $targetLocale, 'title', trim((string) $args['title']));
            }
            if ($hasExcerpt) {
                $this->setLocaleField($post, $targetLocale, 'excerpt', (string) $args['excerpt']);
            }
            if ($hasTitle || $hasExcerpt) {
                $post->save();
            }

            if ($translatedBlocks !== null) {
                $blockModels = $post->blocks()->orderBy('order')->get();
                $storedBlocks = $blockModels->map(static fn ($b) => $b->content)->values()->all();

                // Refuses (throws) on any shape mismatch BEFORE anything below writes — see the
                // method's own docblock for the exact rule.
                $folded = $this->foldTranslatedBlocks($storedBlocks, $translatedBlocks, $targetLocale);

                if ($storedBlocks !== []) {
                    $this->captureRevision($post, 'manual');
                }

                $blocksChanged = false;
                foreach ($folded as $index => $content) {
                    $block = $blockModels->get($index);
                    if ($block !== null && $block->content !== $content) {
                        $block->content = $content;
                        $block->save();
                        $blocksChanged = true;
                    }
                }
                if ($blocksChanged) {
                    $post->bumpContentVersion();
                }
            }

            $post->refresh();

            return $this->translationCompleteness($post, $targetLocale);
        });
    }

    /**
     * Fold `$translated` (a freshly parsed+validated block tree, target `$locale`) into
     * `$stored` (the post's CURRENT block tree) by POSITION — top-level index, then recursively
     * through `innerBlocks` at the same index. Returns the (possibly modified) `$stored` tree
     * with `_<locale>` attribute variants written in; throws {@see McpToolException} naming every
     * shape mismatch found (a different block count, or a different block `name`, at any
     * position/depth) rather than writing anything when the shapes disagree — see
     * {@see \Heisenberg\Console\Commands\MergeTranslationsCommand::mergeNode()} for the sibling
     * precedent this mirrors (position-matched fold, refuse on shape mismatch); this version has
     * no "already different content" conflict to check, because overwriting a locale's existing
     * translation IS what re-running create_translation for it means.
     *
     * @param  list<array<string, mixed>> $stored
     * @param  list<array<string, mixed>> $translated
     * @return list<array<string, mixed>>
     */
    private function foldTranslatedBlocks(array $stored, array $translated, string $locale): array
    {
        $mismatches = [];
        $folded = $this->foldNodes($stored, $translated, $locale, $mismatches, 'blocks');

        if ($mismatches !== []) {
            throw new McpToolException(
                "The translated code's structure does not match this post's stored blocks: " . implode('; ', $mismatches)
                . '. Translate the SAME block sequence and structure as the source (get_post\'s `code`) — only human-readable text may change.'
            );
        }

        return $folded;
    }

    /**
     * @param  list<array<string, mixed>> $storedNodes
     * @param  list<array<string, mixed>> $translatedNodes
     * @param  string[]                   $mismatches
     * @return list<array<string, mixed>>
     */
    private function foldNodes(array $storedNodes, array $translatedNodes, string $locale, array &$mismatches, string $path): array
    {
        if (count($storedNodes) !== count($translatedNodes)) {
            $mismatches[] = "{$path}: block count differs (post has " . count($storedNodes) . ', translated code has ' . count($translatedNodes) . ')';

            return $storedNodes;
        }

        foreach ($storedNodes as $index => $storedNode) {
            $storedNodes[$index] = $this->foldNode(
                is_array($storedNode) ? $storedNode : [],
                is_array($translatedNodes[$index]) ? $translatedNodes[$index] : [],
                $locale,
                $mismatches,
                "{$path}[{$index}]",
            );
        }

        return $storedNodes;
    }

    /**
     * @param  array<string, mixed> $storedNode
     * @param  array<string, mixed> $translatedNode
     * @param  string[]             $mismatches
     * @return array<string, mixed>
     */
    private function foldNode(array $storedNode, array $translatedNode, string $locale, array &$mismatches, string $path): array
    {
        $storedName = $storedNode['name'] ?? null;
        $translatedName = $translatedNode['name'] ?? null;

        if (! is_string($storedName) || $storedName !== $translatedName) {
            $mismatches[] = "{$path}: block name mismatch ('" . (is_string($storedName) ? $storedName : 'null')
                . "' vs '" . (is_string($translatedName) ? $translatedName : 'null') . "')";

            return $storedNode;
        }

        $keys = $this->registry->translatableAttributes($storedName);
        $storedAttrs = is_array($storedNode['attributes'] ?? null) ? $storedNode['attributes'] : [];
        $translatedAttrs = is_array($translatedNode['attributes'] ?? null) ? $translatedNode['attributes'] : [];

        foreach ($keys as $key) {
            // The translated node's BARE value is the translator's actual text for this call —
            // an agent authors plain shortcode, never a suffixed variant, so read()'s
            // fallback-to-bare is exactly right here (same posture MergeTranslationsCommand's
            // mergeNode() takes reading a split-row sibling's own bare content).
            $value = LocalizedAttributes::read($translatedAttrs, $key, $locale);
            if (! LocalizedAttributes::hasContent($value)) {
                continue;
            }
            $storedAttrs = LocalizedAttributes::write($storedAttrs, $key, $locale, $value);
        }
        $storedNode['attributes'] = $storedAttrs;

        $storedInner = is_array($storedNode['innerBlocks'] ?? null) ? $storedNode['innerBlocks'] : [];
        $translatedInner = is_array($translatedNode['innerBlocks'] ?? null) ? $translatedNode['innerBlocks'] : [];

        if (count($storedInner) !== count($translatedInner)) {
            $mismatches[] = "{$path}: innerBlocks count differs (post has " . count($storedInner) . ', translated code has ' . count($translatedInner) . ')';

            return $storedNode;
        }

        foreach ($storedInner as $index => $child) {
            $storedInner[$index] = $this->foldNode(
                is_array($child) ? $child : [],
                is_array($translatedInner[$index]) ? $translatedInner[$index] : [],
                $locale,
                $mismatches,
                "{$path}>{$index}",
            );
        }
        $storedNode['innerBlocks'] = $storedInner;

        return $storedNode;
    }

    /**
     * `$locale`'s row from {@see TranslationStatusService::statuses()}, reshaped to
     * `create_translation`'s return contract — the same completeness signal `get_post`'s
     * `translations` map reports for this locale, so a caller sees a consistent number either
     * way it asks.
     *
     * @return array{post_id: int|string, locale: string, complete: bool, blocks_translated: int, blocks_total: int}
     */
    private function translationCompleteness(Post $post, string $locale): array
    {
        foreach ($this->translationStatus->statuses($post) as $row) {
            if ($row['locale'] === $locale) {
                return [
                    'post_id' => $post->getKey(),
                    'locale' => $locale,
                    'complete' => $row['complete'],
                    'blocks_translated' => $row['blocks_translated'],
                    'blocks_total' => $row['blocks_total'],
                ];
            }
        }

        // Unreachable in practice ($locale was already validated against LocaleConfig, and
        // statuses() returns one row per configured locale) — kept as a safe default rather than
        // an assertion, so a future config change degrades gracefully instead of fataling here.
        return ['post_id' => $post->getKey(), 'locale' => $locale, 'complete' => false, 'blocks_translated' => 0, 'blocks_total' => 0];
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
     * No propagation: the single-row translation model (docs/content-translation.md §0) means
     * one post row owns one featured image — there is no sibling row left to keep in sync. This
     * previously propagated the same file_id to every row in the post's translation group
     * (`Post::siblings()`), which no longer exists; that call unconditionally fataled every
     * invocation until this fix.
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

            return ['post_id' => $post->getKey(), 'featured_image_id' => $post->featured_image_id];
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
