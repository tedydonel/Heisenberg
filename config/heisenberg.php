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

    // ── Models (host may swap any) ────────────────────────────
    // post/block/public_file/category/tag exist today; a Revision model +
    // migration also already ship (see src/Models/Revision.php) but that
    // config entry is a separate agent's concern and is left as it was. The
    // comment/pattern/seo_meta domain models are still planned M3 work
    // (docs/BLUEPRINT.md §2) and are deliberately commented out below:
    // resolving `config('heisenberg.models')` must never throw a
    // class-not-found fatal for a class that isn't built yet. Uncomment each
    // entry as its model ships.
    'models' => [
        'post'        => \Heisenberg\Models\Post::class,
        'block'       => \Heisenberg\Models\Block::class,
        'public_file' => \Heisenberg\Models\PublicFile::class,
        'category'    => \Heisenberg\Models\Category::class,
        'tag'         => \Heisenberg\Models\Tag::class,
        // 'comment'  => \Heisenberg\Models\Comment::class,  // M3
        // 'revision' => \Heisenberg\Models\Revision::class, // M3 (already shipped; see note above)
        // 'pattern'  => \Heisenberg\Models\Pattern::class,  // M3
        // 'seo_meta' => \Heisenberg\Models\SeoMeta::class,  // M3
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
    'post_template' => [
        'post_views_provider'    => \Heisenberg\Adapters\NullPostViewsProvider::class,
        'comments_provider'      => \Heisenberg\Adapters\NullPostCommentProvider::class,
        'related_posts_provider' => \Heisenberg\Adapters\NullRelatedPostsProvider::class,
        'seo_meta_provider'      => \Heisenberg\Adapters\NullPostSeoMetaProvider::class,
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
            // Locales the editor exposes via the footer switcher. Must match
            // LocaleController::LOCALES and EditorLocaleMiddleware::LOCALES —
            // changing any of the three is a coordinated change.
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
    'roles' => [
        'super'   => ['super_admin'],
        'admins'  => ['super_admin', 'admin'],
        'authors' => ['super_admin', 'admin', 'employee_l1', 'employee_l2', 'employee_l3'],
    ],

    // ── Publishing lifecycle ──────────────────────────────────
    'lifecycle' => [
        'transitions' => [
            'draft'          => ['pending_review', 'archived'],
            'pending_review' => ['published', 'scheduled', 'draft'],
            'published'      => ['archived'],
            'scheduled'      => ['published', 'archived', 'draft'],
            'archived'       => ['draft'],
        ],
        'role_permissions' => [          // target status -> tier
            'pending_review' => 'authors',
            'published'      => 'admins', // ← resolved publish-authority decision (§7.4)
            'scheduled'      => 'admins',
            'archived'       => 'admins',
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
    'middleware' => [
        'api_admin' => ['auth:sanctum', 'verified', 'role:admin'],
        'media'     => ['web'],
        'editor'    => ['web'],
    ],

];
