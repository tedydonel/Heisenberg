<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Heisenberg configuration
|--------------------------------------------------------------------------
|
| Every value here externalizes a literal that lived hard-coded inside a GTC
| Blog service. See docs/BLUEPRINT.md §14 for the provenance of each entry.
| A GTC host migrating in place sets the *_prefix / table values back to the
| `gtc`/`blog_*` originals and binds the GTC adapters (see §15 M7).
|
*/

return [

    // ── Identity ──────────────────────────────────────────────
    'user_model'  => env('HEISENBERG_USER_MODEL', \App\Models\User::class),
    'users_table' => 'users',

    // ── Locales (docs/content-translation.md §3) ────────────────
    // Single source of truth for every locale-aware surface: the editor's footer switcher
    // (LocaleController/EditorLocaleMiddleware), the Translations section (TranslationStatusService),
    // and the MCP `locale` argument validation (McpToolRegistry). `editor.locales` below is now a
    // deprecated alias — still read as a FALLBACK by the three call sites above when this key is
    // absent (never the other way around: this key wins whenever both are set), kept only so a host
    // that already overrode `editor.locales` doesn't silently lose that override on upgrade.
    //
    // Honest limits: adding a locale here is a config change, but NOT yet full support — the posts
    // table only carries `title_<locale>`/`excerpt_en`/`rendered_html_<locale>` columns for `en`
    // and `fr` (migration 2026_01_01_000001). Listing a third locale here today would pass locale
    // validation but have nowhere to store that locale's title/excerpt/rendered HTML; the column
    // shape needs a follow-up migration before this list can safely grow past two entries.
    'locales' => ['en', 'fr'],
    'default_locale' => 'en',

    // ── Models (host may swap any) ────────────────────────────
    // post/block/public_file/category/tag/comment/seo_meta exist today; a Revision model +
    // migration also already ship (see src/Models/Revision.php) but that
    // config entry is a separate agent's concern and is left as it was. The
    // pattern domain model is still planned M3 work (docs/BLUEPRINT.md §2) and is
    // deliberately commented out below: resolving `config('heisenberg.models')` must never
    // throw a class-not-found fatal for a class that isn't built yet. Uncomment each entry
    // as its model ships.
    'models' => [
        'post'        => \Heisenberg\Models\Post::class,
        'block'       => \Heisenberg\Models\Block::class,
        'public_file' => \Heisenberg\Models\PublicFile::class,
        'category'    => \Heisenberg\Models\Category::class,
        'tag'         => \Heisenberg\Models\Tag::class,
        'comment'     => \Heisenberg\Models\Comment::class,
        'seo_meta'    => \Heisenberg\Models\SeoMeta::class,
        // 'revision' => \Heisenberg\Models\Revision::class, // M3 (already shipped; see note above)
        // 'pattern'  => \Heisenberg\Models\Pattern::class,  // M3
    ],

    // ── Tables (default heisenberg_ prefix; set to GTC names to migrate in place) ──
    'tables' => [
        'posts'           => 'heisenberg_posts',
        'blocks'          => 'heisenberg_blocks',
        'categories'      => 'heisenberg_categories',
        'tags'            => 'heisenberg_tags',
        'post_tag'        => 'heisenberg_post_tag',
        'category_post'   => 'heisenberg_category_post',
        'revisions'       => 'heisenberg_post_revisions',
        'comments'        => 'heisenberg_comments',
        'patterns'        => 'heisenberg_patterns',
        'review_notes'    => 'heisenberg_review_notes',
        'post_likes'      => 'heisenberg_post_likes',
        'toc_entries'     => 'heisenberg_post_toc_entries',
        'post_related'    => 'heisenberg_post_related',
        'seo_meta'        => 'seo_meta',
        'public_files'    => 'heisenberg_public_files',
        'ai_conversations' => 'heisenberg_ai_conversations',
        'ai_messages'      => 'heisenberg_ai_messages',
    ],

    // ── Block engine ──────────────────────────────────────────
    'block_prefix' => 'heisenberg',   // contract name namespace (gtc/… -> heisenberg/…)
    'block_root'   => null,           // null -> package resources/blocks

    // Post templates — the contract an adopter writes to describe how their posts/pages render
    // publicly. Same shape/scan/hash model as blocks above, deliberately, so there is one mental
    // model to learn. See docs/post-template-schema.md.
    'template_prefix' => 'heisenberg', // contract name namespace
    'template_root'   => null,         // null -> package resources/templates

    // Capabilities a template can declare that need storage this package does not own. Each is an
    // adapter contract with a bundled null default, exactly like media_resolver/role_gate below —
    // a host points these at their own implementation. The other seven capabilities (TOC, featured
    // image, reading time, author box, share buttons, breadcrumbs, pagination) are rendered from
    // data that already exists and need no binding.
    // comments_provider defaults to the NATIVE adapter (2026-08-11): native storage
    // (heisenberg_comments, Heisenberg\Models\Comment) now exists, so an adopter gets a
    // working comment thread out of the box instead of an always-empty null one. Bind
    // NullPostCommentProvider here to disable comments entirely, or your own class to
    // integrate an external system (Disqus, a hosted service, a different table) — see
    // PostCommentProvider's docblock. seo_meta_provider defaults to the NATIVE adapter too
    // (2026-08-11, docs/seo-system.md Wave S1): the polymorphic `seo_meta` table +
    // Heisenberg\Models\SeoMeta now exist, so a preview page gets real
    // title/description/canonical/OG/JSON-LD instead of always-empty. Bind
    // NullPostSeoMetaProvider here to opt out, or your own class to integrate an external
    // SEO system — see PostSeoMetaProvider's docblock.
    'post_template' => [
        'post_views_provider'    => \Heisenberg\Adapters\NullPostViewsProvider::class,
        'comments_provider'      => \Heisenberg\Adapters\NativeCommentProvider::class,
        'related_posts_provider' => \Heisenberg\Adapters\NullRelatedPostsProvider::class,
        'seo_meta_provider'      => \Heisenberg\Adapters\NativeSeoMetaProvider::class,
    ],

    // ── Comments (docs/post-template-schema.md "Comments/discussion") ─────────
    // Native comment storage config — read by NativeCommentProvider and (for
    // allow_guests) by a later HTTP-layer agent's submission endpoint.
    'comments' => [
        'routes'       => true,  // load routes/comments.php (public thread/submit + moderation)
        'allow_guests' => true,   // guests may submit on posts they can view (published)
        'auto_approve' => false, // new comments start 'pending'; moderators' own comments always approve
        'max_depth'    => 3,     // reply nesting cap; 1 = flat (no replies)
        'per_page'     => 50,    // moderation list page size
    ],
    // ── Public translations API (docs/content-translation.md §7) ──────────────
    // A translation group presents as ONE post with ONE shared slug (locale comes from the
    // host's URL prefix, never the slug) — this endpoint (routes/translations.php,
    // PostTranslationsApiController) lets a host build its own language-switcher buttons: it
    // lists the group's members (self included) that the requesting actor may view, with the
    // shared slug at the top level. Same opt-out posture as `comments` above.
    'translations' => [
        'routes' => true, // load routes/translations.php (GET /heisenberg/posts/{post}/translations)
    ],
    // ── SEO (docs/seo-system.md §4/§5, Wave S2b) ──────────────────────────
    // 'sitemap': load routes/seo.php (GET /sitemap.xml) — see
    // HeisenbergServiceProvider::registerSeoRoutes(). 'url_template': a post's PUBLIC address,
    // either a STRING, e.g. 'https://example.com/{locale}/blog/{slug}' ({locale}/{slug}
    // placeholders substituted for every locale alike), or a MAP keyed by locale, e.g.
    // ['en' => 'https://example.com/blog/{slug}', 'fr' => 'https://example.com/fr/blog/{slug}']
    // — lets a host give its default locale an unprefixed URL while other locales carry a
    // prefix (a '*' catch-all key is also honored). null (the default), or a map with no
    // matching entry, falls back to this package's own post-scoped preview route — a DEV
    // DEFAULT ONLY (see SeoUrlResolver's docblock). A host shipping a real sitemap/hreflang
    // should set this to its actual blog route shape. 'url_resolver': the full override seam
    // (Heisenberg\Contracts\PostUrlResolver) — a class name to bind INSTEAD of the
    // template-driven default below, for a URL shape templates can't express (per-locale
    // domains, id-based URLs, a host's own route helpers). Defaults to SeoUrlResolver, exactly
    // like media_resolver/role_gate above.
    'seo' => [
        'sitemap'      => true,
        'url_template' => null,
        'url_resolver' => \Heisenberg\Services\SeoUrlResolver::class,
    ],
    // ── Email documents (docs/email-system.md §6.1) ───────────────────────────
    // A built email is served at its OWN address — `/{prefix}/{slug}` — and nowhere else: the
    // post preview route 404s for `type = 'email'`, and the editor's id-scoped
    // `/editor/{post}/email-preview` only redirects here. 'routes' loads routes/email.php
    // (same opt-out posture as `comments`/`translations` above; a host that serves its own
    // "view in browser" page turns it off and calls EmailRenderer directly). 'route_prefix' is
    // the first URL segment — change it if `/emails` collides with a host's own routing.
    // `heisenberg.middleware.email` gates the group; PostPolicy `view` runs regardless, so a
    // DRAFT email is never readable by a visitor no matter how open that stack is.
    'email' => [
        'routes'       => true,
        'route_prefix' => 'emails',
    ],
    'css_prefix'   => 'hb',           // emitted CSS class/var prefix (gtc-block -> hb-block)
    'components'   => [
        // safe component allowlist (§3.8), e.g.:
        // 'article_card' => ['blade' => 'heisenberg::components.article-card', 'props' => ['title', 'excerpt', 'url', 'image', 'date']],
    ],

    // ── Design tokens (provisional defaults; a host overrides with its palette) ──
    // The option sets the supports panels (color/typography/spacing/border) offer in
    // the inspector. Values are design-token custom-property references; hosts may
    // replace these maps with their own token values and human-readable labels.
    'tokens' => [
        'color' => [
            '' => 'Default',
            'var(--ink)' => 'Ink',
            'var(--accent-1)' => 'Accent',
            'var(--faint)' => 'Faint',
            'var(--paper)' => 'Paper',
        ],
        'fontSize' => [
            '' => 'Default',
            'var(--fs-sm)' => 'Small',
            'var(--fs-md)' => 'Medium',
            'var(--fs-lg)' => 'Large',
            'var(--fs-xl)' => 'Extra large',
        ],
        'space' => [
            '' => 'None',
            'var(--sp-1)' => 'Small',
            'var(--sp-2)' => 'Medium',
            'var(--sp-3)' => 'Large',
            'var(--sp-4)' => 'Extra large',
        ],
        'fontFamily' => [
            '' => 'Default',
            'var(--font-sans)' => 'Sans',
            'var(--font-serif)' => 'Serif',
            'var(--font-mono)' => 'Mono',
        ],
        'fontWeight' => [
            '' => 'Default',
            'var(--fw-regular)' => 'Regular',
            'var(--fw-medium)' => 'Medium',
            'var(--fw-semibold)' => 'Semibold',
            'var(--fw-bold)' => 'Bold',
        ],
    ],

    // ── User theme (Style panel design tokens) ────────────────
    // JSON file path for the saved theme; null -> storage/app/heisenberg/theme.json
    'theme_path' => env('HEISENBERG_THEME_PATH'),
    // JSON file path for the user's named theme library (Themes tab "Save to Themes");
    // null -> storage/app/heisenberg/themes.json
    'saved_themes_path' => env('HEISENBERG_SAVED_THEMES_PATH'),

    'editor' => [
            'routes' => true,
            // DEPRECATED alias of top-level `heisenberg.locales` (docs/content-translation.md §3)
            // — kept only as the fallback LocaleController/EditorLocaleMiddleware/McpToolRegistry
            // read when `heisenberg.locales` is absent. Set `heisenberg.locales` instead; this key
            // has no effect once that one is present.
            'locales' => ['en', 'fr'],
        ],

    // The builder (a second, older editing surface at /builder) was removed 2026-08-02 —
    // see TODO.md. The block contract set was pruned to heading + paragraph in the same
    // pass; both were kept in step deliberately (the builder was the only reason a
    // separate curated allow-list ever existed — see git history for `builder.blocks`/
    // `editor.blocks` if that mechanism is ever needed again). The editor now ships
    // whatever BlockRegistryService discovers under resources/blocks, full stop.

    // ── Public media library (docs/media-library-backend-blueprint.md) ──
    // Package adaptation of the blueprint's app/-rooted subsystem: config-driven
    // table/model (like posts/blocks above), a guarded uploaded_by FK (only added
    // when the configured users_table already exists — a package must not
    // hard-assume the host's users table), and every extension point (disk, size/
    // type limits, variant sizes, scan behavior, routes, middleware, scanner
    // adapter) externalized here instead of a separate config/media.php.
    'media' => [
        // Dedicated disk (config('filesystems.disks.uploads')) registered by the
        // service provider IF the host hasn't already defined one — see
        // HeisenbergServiceProvider::registerUploadsDisk(). Point this at any
        // other configured disk (e.g. 's3') to move public media to a CDN; every
        // URL in PublicFile is disk-aware and follows automatically (blueprint §3.3).
        'disk' => env('HEISENBERG_MEDIA_DISK', 'uploads'),

        // Upload limits + allowed extensions — mirror PublicFile's own constants
        // by default so there is one source of truth; a host may override either
        // independently (e.g. to allow a narrower extension list).
        'max_kb'     => \Heisenberg\Models\PublicFile::MAX_KB,
        'extensions' => \Heisenberg\Models\PublicFile::TYPES,

        // Responsive derivative widths (blueprint §6); image uploads only.
        'variants' => \Heisenberg\Models\PublicFile::VARIANTS,

        // Decompression-bomb guard: a tiny file can declare an enormous pixel
        // grid (e.g. a 1 KB PNG claiming 40000x40000px) that is cheap to read
        // via getimagesize() (header-only, no decode) but catastrophically
        // expensive/memory-hungry to actually decode via GD. MediaLibraryService
        // checks width*height against this cap using ONLY the getimagesize()
        // header BEFORE Intervention ever calls read() — if the image exceeds
        // the cap, variant generation is skipped entirely (the original is
        // still stored; `variants` is simply empty). 40 megapixels comfortably
        // covers real photos (a 24MP DSLR shot is ~24,000,000px) while refusing
        // to decode a crafted bomb. Set to 0 to disable the cap.
        'max_megapixels' => env('HEISENBERG_MEDIA_MAX_MEGAPIXELS', 40),

        'scan' => [
            // Scanner outage behavior (blueprint §5.1): fail CLOSED by default
            // (reject the upload) unless a local/dev environment explicitly opts
            // into failing open via HEISENBERG_MEDIA_SCAN_FAIL_OPEN=true.
            'fail_open' => env('HEISENBERG_MEDIA_SCAN_FAIL_OPEN', false),
        ],

        // Load the opt-in media routes (routes/media.php: index/upload/update/
        // destroy/select under name `media.*`). A host that mounts its own
        // authenticated routes at MediaLibraryController's actions instead
        // should set this to false.
        'routes' => true,

        // Adapter for the VirusScanner contract — bind a real ClamAV/clamd
        // implementation in production; the bundled default always reports clean.
        'virus_scanner' => \Heisenberg\Adapters\NullVirusScanner::class,
    ],

    // ── AI assistant + MCP (docs/ai-mcp-plan.md) ──────────────
    // Powers the editor's Ai/Tools panel, and — separately — lets Heisenberg act
    // as an MCP server so other AIs can author pages here.
    //
    // A PROVIDER IS NOT A WIRE FORMAT. `formats` below lists the two API shapes
    // this package can speak; a *provider* (OpenAI, Anthropic, xAI, Gemini,
    // OpenRouter, Groq, MiniMax, a local Ollama) is a vendor that speaks one of
    // them at a given base URL with its own credential. Nearly every vendor
    // speaks the OpenAI `/chat/completions` shape, so conflating the two would
    // cap this package at exactly two providers.
    //
    // CREDENTIALS: an operator may either set the provider's env var (the safer
    // posture, and what wins when both exist) or paste a key into the settings
    // modal, which stores it ENCRYPTED via the AiCredentialStore binding. No
    // endpoint ever returns key material either way — the settings API reports
    // `has_key` booleans and the name of the env var, nothing more.
    'ai' => [
        // Load the opt-in AI routes (routes/ai.php). Separate from the editor's
        // own key so a host can mount the editor without the AI surface.
        'routes' => true,

        // The API shapes, and the adapter that speaks each one.
        'formats' => [
            \Heisenberg\Ai\AiProviderProfile::FORMAT_ANTHROPIC => [
                'label'   => 'Anthropic Messages API',
                'adapter' => \Heisenberg\Adapters\AnthropicProvider::class,
            ],
            \Heisenberg\Ai\AiProviderProfile::FORMAT_OPENAI => [
                'label'   => 'OpenAI Chat Completions',
                'adapter' => \Heisenberg\Adapters\OpenAiCompatibleProvider::class,
            ],
        ],

        // Vendors an operator can add in one click, with the models each is
        // known to serve. These are SEEDS for the settings modal, not a fixed
        // list: a provider not here is added by hand with a label, format, base
        // URL and key, and works identically.
        'provider_presets' => [
            [
                'id' => 'openai', 'label' => 'OpenAI', 'format' => 'openai', 'icon' => 'circles-four',
                'base_url' => 'https://api.openai.com/v1', 'key_env' => 'HEISENBERG_AI_OPENAI_KEY',
                'models' => ['gpt-5', 'gpt-5-mini'],
            ],
            [
                'id' => 'anthropic', 'label' => 'Anthropic', 'format' => 'anthropic', 'icon' => 'sparkle',
                'base_url' => 'https://api.anthropic.com', 'key_env' => 'HEISENBERG_AI_ANTHROPIC_KEY',
                'models' => ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
            ],
            [
                'id' => 'google', 'label' => 'Google Gemini', 'format' => 'openai', 'icon' => 'diamond',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'key_env' => 'HEISENBERG_AI_GOOGLE_KEY',
                'models' => ['gemini-2.5-pro', 'gemini-2.5-flash'],
            ],
            [
                'id' => 'xai', 'label' => 'xAI', 'format' => 'openai', 'icon' => 'x-square',
                'base_url' => 'https://api.x.ai/v1', 'key_env' => 'HEISENBERG_AI_XAI_KEY',
                'models' => ['grok-4', 'grok-3'],
            ],
            [
                'id' => 'openrouter', 'label' => 'OpenRouter', 'format' => 'openai', 'icon' => 'shuffle',
                'base_url' => 'https://openrouter.ai/api/v1', 'key_env' => 'HEISENBERG_AI_OPENROUTER_KEY',
                'models' => ['anthropic/claude-opus-5', 'openai/gpt-5'],
            ],
            [
                'id' => 'groq', 'label' => 'Groq', 'format' => 'openai', 'icon' => 'lightning',
                'base_url' => 'https://api.groq.com/openai/v1', 'key_env' => 'HEISENBERG_AI_GROQ_KEY',
                'models' => ['llama-3.3-70b-versatile'],
            ],
            [
                'id' => 'deepseek', 'label' => 'DeepSeek', 'format' => 'openai', 'icon' => 'binoculars',
                'base_url' => 'https://api.deepseek.com/v1', 'key_env' => 'HEISENBERG_AI_DEEPSEEK_KEY',
                'models' => ['deepseek-chat', 'deepseek-reasoner'],
            ],
            [
                'id' => 'mistral', 'label' => 'Mistral', 'format' => 'openai', 'icon' => 'wind',
                'base_url' => 'https://api.mistral.ai/v1', 'key_env' => 'HEISENBERG_AI_MISTRAL_KEY',
                'models' => ['mistral-large-latest'],
            ],
            // No key: a local runtime is reachable without one, and demanding a
            // fake credential to talk to 127.0.0.1 would be theatre.
            [
                'id' => 'ollama', 'label' => 'Ollama (local)', 'format' => 'openai', 'icon' => 'hard-drives',
                'base_url' => 'http://localhost:11434/v1', 'key_env' => null,
                'models' => ['llama3.1:70b'],
            ],
            [
                'id' => 'lmstudio', 'label' => 'LM Studio (local)', 'format' => 'openai', 'icon' => 'desktop',
                'base_url' => 'http://localhost:1234/v1', 'key_env' => null,
                'models' => [],
            ],
        ],

        // Where a UI-entered API key is kept. The bundled store encrypts with the
        // app key and always lets an environment variable win; a host running a
        // real secrets manager binds its own AiCredentialStore here.
        'credential_store' => \Heisenberg\Adapters\EncryptedFileCredentialStore::class,
        'credentials_path' => env('HEISENBERG_AI_CREDENTIALS_PATH'),

        // Fallback effort for a model that carries none of its own. Sampling
        // parameters (temperature/top_p/top_k) and `budget_tokens` are
        // deliberately absent and must never be added: current Anthropic models
        // reject all four with a 400.
        'effort'     => env('HEISENBERG_AI_EFFORT', 'high'), // low|medium|high|xhigh|max
        // Reasoning tokens count against this cap on every current API. A heavy
        // thinker (MiniMax-M3, DeepSeek-R1 class) can spend >16k on thinking
        // ALONE for a full-page build and hit the cap before writing anything —
        // the panel then shows "reply hit the model's length limit" with an
        // empty canvas. 32k leaves room to think AND build.
        'max_tokens' => (int) env('HEISENBERG_AI_MAX_TOKENS', 32000),
        'timeout'    => (int) env('HEISENBERG_AI_TIMEOUT', 120),

        // Laravel throttle spec ("requests,minutes") for the two model-calling
        // endpoints. Every call spends the operator's API budget, so this is a
        // bill guard, not just a load guard. Set to null to drop the throttle —
        // the only reason to: it is the one part of this package that needs a
        // working cache store, so a host on the `database` cache driver without
        // the `cache` table must either create it or disable this.
        'rate_limit' => env('HEISENBERG_AI_RATE_LIMIT', '30,1'),

        // JSON file for the AI panel's settings (active provider/model/effort,
        // enabled tools, MCP server list); null -> storage/app/heisenberg/ai.json.
        // Same file-backed pattern as theme_path / saved_themes_path above.
        'settings_path' => env('HEISENBERG_AI_SETTINGS_PATH'),

        'mcp' => [
            // Outbound — Heisenberg connects to other people's MCP servers and
            // offers their tools to the model. The server list lives in the
            // settings JSON; each entry names an env var for its token.
            'client' => [
                'enabled'        => (bool) env('HEISENBERG_MCP_CLIENT', false),
                'adapter'        => \Heisenberg\Adapters\HttpMcpClient::class,
                'timeout'        => (int) env('HEISENBERG_MCP_TIMEOUT', 30),
                // Hard stop on the request -> tool_use -> tool_result loop, so a
                // model that keeps calling tools cannot run forever. Raised from
                // 8: a from-scratch creative prompt routinely burns 6-8 rounds on
                // one-at-a-time discovery calls (list_blocks, then describe_block
                // per block) before it authors anything, so 8 was clipping normal
                // turns, not just runaway ones. The loop also no longer discards
                // work on exhaustion (see AiToolRunner's graceful final pass), so
                // raising this is a quality tradeoff, not a bill-risk one.
                'max_iterations' => (int) env('HEISENBERG_MCP_MAX_ITERATIONS', 16),
            ],

            // Inbound — other AIs connect to Heisenberg and author pages through
            // the same validation the editor uses. OFF by default, deliberately:
            // this is a write API, and enabling it is an explicit act.
            'server' => [
                'enabled'    => (bool) env('HEISENBERG_MCP_SERVER', false),
                // "token:tier,token:tier" — tier is a heisenberg.roles key
                // (authors/admins/super). Read at request time, never logged.
                'tokens_env' => 'HEISENBERG_MCP_TOKENS',
                'path'       => env('HEISENBERG_MCP_PATH', 'heisenberg/mcp'),
            ],
        ],
    ],

    // ── Contracts → adapters ──────────────────────────────────
    'media_resolver' => \Heisenberg\Adapters\NullMediaResolver::class,
    'role_gate'      => \Heisenberg\Adapters\ConfigRoleGate::class,
    'audit_sink'     => \Heisenberg\Adapters\NullAuditSink::class,
    'icon_provider'  => \Heisenberg\Adapters\PhosphorIconProvider::class,

    // ── Local-dev-only authorization bypass ────────────────────
    // See src/Adapters/LocalDevRoleGate.php. Consulted by the Livewire media
    // library (upload/delete, behind the unauthenticated-by-default
    // middleware.media) and by ThemeController::update() (currently
    // unrouted — see that class's docblock) — so a fresh local install
    // isn't 403ing on itself before a host has wired up real auth.
    //
    // When this is true AND app()->environment('local') is true (BOTH are
    // required, re-checked on every single authorization call, never cached),
    // those two surfaces treat every request as fully authorized, including a
    // genuinely anonymous one. Outside app()->environment('local') this flag
    // does NOTHING — there is no way to widen the bypass to any other
    // environment name via config alone. Set to false to disable the bypass
    // even in local (e.g. to exercise real authorization on your own machine).
    'allow_anonymous_in_local' => env('HEISENBERG_ALLOW_ANONYMOUS_IN_LOCAL', true),

    // ── Authorization role map (tiers, not literal roles) ─────
    // The map is keyed by TIER, not literal role — a tier resolves to a list
    // of the host's own role strings, and a policy asks the RoleGate for a
    // tier ('authors', 'admins', …), never a literal role. `admin`, `editor`,
    // `author` and `viewer` are Heisenberg's own canonical role vocabulary
    // (WordPress-familiar); a host with different role names remaps them
    // here without touching a single policy or controller. `super` is the
    // ceiling tier above `admins` — currently unused by any shipped policy
    // or controller, kept to document the ceiling for a host that wants a
    // super-admin-only surface later.
    //
    // The media.* entries are the abilities PublicFilePolicy asks the RoleGate
    // for. They were missing (2026-08-10), which made ConfigRoleGate resolve
    // them to an empty role set — the HTTP media API then denied EVERY user,
    // even admins, on any host using the bundled gate.
    'roles' => [
        'super'   => ['admin'],
        'admins'  => ['admin'],
        'editors' => ['admin', 'editor'],
        'authors' => ['admin', 'editor', 'author'],

        'media.viewAny'   => ['admin', 'editor', 'author', 'viewer'],
        'media.create'    => ['admin', 'editor', 'author'],
        'media.updateAny' => ['admin', 'editor'],
        'media.deleteAny' => ['admin', 'editor'],

        // Approve/spam/trash/reply on any comment (a later HTTP-layer agent's moderation
        // surface). Same admin+editor tier as the media.update/deleteAny abilities above —
        // an author may submit content but doesn't moderate other people's comments.
        'comments.moderate' => ['admin', 'editor'],
    ],

    // ── Publishing lifecycle ──────────────────────────────────
    // Graph shape decided 2026-08-12 (owner bug: an admin had no way to publish a post —
    // `draft` had no edge to `published`/`scheduled` at all, so the Status control's
    // options — built by walking THIS map, see EditorController::postMeta() — could never
    // offer them). `role_permissions` below stays the ONLY authority on WHO may land on a
    // given status; this map only decides which edges exist at all, WordPress-like:
    //  - draft -> published / scheduled (NEW): an editor/admin publishes (or schedules) a
    //    draft directly, same as WordPress's Publish button on a brand-new post — no
    //    detour through pending_review required. An author still can't reach either target:
    //    role_permissions gates 'published'/'scheduled' at the 'editors' tier, so the edge
    //    existing here never widens WHO may use it.
    //  - published -> draft (NEW): unpublish back to draft — the direct inverse of the edge
    //    above, and the only way to pull a live post down short of archiving it.
    //  - published -> scheduled: deliberately NOT added. "Reschedule a live post" isn't a
    //    real single step (there's no future date to move TO — it's already live); the
    //    honest path is published -> draft -> scheduled, or PostController's existing
    //    scheduled -> scheduled reschedule-in-place edge for an already-scheduled post.
    //  - archived -> published (NEW): a one-step restore-to-live, mirroring the existing
    //    archived -> draft edge — both are "bring an archived post back", just to a
    //    different landing status. Same 'editors' tier as every other archived/* and */
    //    published edge, so this doesn't loosen who can do it, only how many clicks it
    //    takes.
    //  - pending_review -> archived: deliberately NOT added — archiving is reserved for
    //    content that was actually live or was a live post being pulled down, not a
    //    review queue; a reviewer rejects back to draft (existing pending_review -> draft
    //    edge) instead of archiving outright.
    'lifecycle' => [
        'transitions' => [
            'draft'          => ['pending_review', 'published', 'scheduled', 'archived'],
            'pending_review' => ['published', 'scheduled', 'draft'],
            'published'      => ['archived', 'draft'],
            'scheduled'      => ['published', 'archived', 'draft'],
            'archived'       => ['draft', 'published'],
        ],
        'role_permissions' => [          // target status -> tier
            'pending_review' => 'authors',
            'published'      => 'editors', // ← resolved publish-authority decision (§7.4) — WordPress semantics: editors publish
            'scheduled'      => 'editors',
            'archived'       => 'editors',
            'draft'          => 'authors',
        ],
    ],

    // ── Queues / cache / sanitization ─────────────────────────
    'queues'              => ['render' => 'default', 'audit' => 'default'],
    'cache_prefix'        => 'heisenberg',
    'purifier_cache_path' => storage_path('framework/cache/heisenberg-purifier'),
    'revisions'           => ['keep' => null], // null = unbounded (as-built)

    // ── Host admin/staff HTTP surfaces (replaces route-name string-sniffing) ──
    // Unrelated to Heisenberg's own /editor route group below — this names a HOST
    // app's own content-management surfaces (their admin/staff route prefixes) for
    // RoleGate to key off, e.g. when the host wraps Heisenberg's controllers in
    // its own authenticated routes rather than using /editor directly.
    'surfaces' => [
        'admin' => ['name_prefix' => 'admin.content.', 'domain' => null, 'roles' => 'admins'],
        'staff' => ['name_prefix' => 'staff.content.', 'domain' => null, 'roles' => 'authors'],
    ],

    // `middleware.media` gates the opt-in media library routes (routes/media.php:
    // index/upload/update/destroy/select). Defaults to just `web` so the
    // dev/testbench harness and the package's own tests work unauthenticated;
    // a host mounting these for real widens it (e.g. `['web', 'auth']`).
    // Read/URL access to already-uploaded files is never gated here — public
    // media is served by the web server via the `uploads` disk symlink, with
    // zero PHP in the read path (blueprint §12).
    // `middleware.editor` gates the Editor surface (routes/editor.php: /editor,
    // /editor/components, /editor/media, plus its asset/uploads-serving routes).
    // Open-by-default: `['web']` so the dev/testbench harness and the package's
    // own editor route tests keep working unauthenticated; a host mounting the
    // Editor for real widens this (e.g. `['web', 'auth']`).
    // `middleware.ai` gates the AI panel's own endpoints (routes/ai.php) — same
    // open-by-default posture as the two above; writes carry their own
    // admins-tier RoleGate check regardless of what this stack is.
    // `middleware.mcp` gates the inbound MCP server (routes/mcp.php) and is
    // DELIBERATELY EMPTY: that endpoint authenticates with a bearer token, not a
    // session, so it must sit outside the `web` group's session/CSRF stack.
    // Putting `web` here would make every MCP call fail CSRF verification.
    'middleware' => [
        'api_admin' => ['auth:sanctum', 'verified', 'role:admin'],
        'media'     => ['web'],
        'editor'    => ['web'],
        'ai'        => ['web'],
        'comments'  => ['web'],
        // `middleware.seo` gates the sitemap (routes/seo.php: GET /sitemap.xml) — the lightest
        // stack a crawler's/visitor's unauthenticated GET needs, same posture as `comments`.
        'seo'       => ['web'],
        // `middleware.email` gates the served email routes (routes/email.php: the built email at
        // its own slug, plus the HTML/.eml export). Same lightest-stack posture: a recipient
        // following a "view in browser" link is not an authenticated editor. Draft emails stay
        // protected by PostPolicy `view` inside the controller, not by this stack.
        'email'     => ['web'],
        // `middleware.translations` gates the public translations API (routes/translations.php)
        // — same lightest-stack posture as `comments`/`seo`: a blog visitor's language-switcher
        // fetch must not require the editor stack.
        'translations' => ['web'],
        'mcp'       => [],
    ],

];
