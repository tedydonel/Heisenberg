<?php

declare(strict_types=1);

namespace Heisenberg;

use Heisenberg\Adapters\ConfigRoleGate;
use Heisenberg\Adapters\NullAiProvider;
use Heisenberg\Adapters\PhosphorIconProvider;
use Heisenberg\Adapters\NullAuditSink;
use Heisenberg\Adapters\NullMediaResolver;
use Heisenberg\Adapters\NullVirusScanner;
use Heisenberg\Contracts\AiProvider;
use Heisenberg\Contracts\AuditSink;
use Heisenberg\Contracts\IconProvider;
use Heisenberg\Contracts\McpClient;
use Heisenberg\Contracts\MediaResolver;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Contracts\VirusScanner;
use Heisenberg\Services\AiProviderRegistry;
use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Models\Category;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\Tag;
use Heisenberg\Policies\CategoryPolicy;
use Heisenberg\Policies\CommentPolicy;
use Heisenberg\Policies\PostPolicy;
use Heisenberg\Policies\PublicFilePolicy;
use Heisenberg\Policies\TagPolicy;
use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Services\HtmlSanitizationService;
use Heisenberg\Services\MediaLibraryService;
use Heisenberg\Support\ConfigMerge;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Heisenberg's single auto-discovered service provider.
 *
 * M0 wires the configuration and the five decoupling contracts (§13) to their
 * default adapters. The engine/domain singleton graph (§1.4), migrations,
 * policies, commands and the schedule land in later milestones (M1–M6); the
 * registration hooks below are deliberately structured so each milestone slots
 * in without reshaping this class.
 */
class HeisenbergServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeHeisenbergConfig();

        $this->registerContracts();
        $this->registerEngine();
        $this->registerUploadsDisk();
        $this->registerMedia();
        $this->registerAi();

        // Later: domain singletons (PostStateMachine, PostTransitionAction, …).
    }

    /**
     * The path to the package's own shipped config/heisenberg.php — the single
     * source of truth for "package defaults" used both here and by
     * `heisenberg:config-diff`.
     */
    public static function configPath(): string
    {
        return __DIR__ . '/../config/heisenberg.php';
    }

    /**
     * Replaces the framework's `mergeConfigFrom()` (a SHALLOW `array_merge`)
     * with a recursive merge via {@see ConfigMerge}: any key the host's
     * published config is missing gets the package's default, at any depth;
     * any key the host DOES define — scalar, null, list, or associative — is
     * never touched. See ConfigMerge's docblock for the full rule and its one
     * honest limitation (a list's CONTENTS, not just its presence, can still go
     * stale in a published host config).
     */
    protected function mergeHeisenbergConfig(): void
    {
        $config = $this->app['config'];
        $defaults = require self::configPath();
        $host = (array) $config->get('heisenberg', []);

        $config->set('heisenberg', ConfigMerge::merge($defaults, $host));
    }

    /**
     * Dev-only DB wiring for `testbench serve`. Testbench's default connection
     * is `testing`/`:memory:`, which cannot persist across the dev server's
     * per-request boots — and it ignores the testbench.yaml `DB_DATABASE`. So
     * when that sentinel env value is present (our testbench.yaml, never a real
     * host) and we're not running the test suite, point the default connection
     * at a persistent sqlite file at the package root — an absolute path, so
     * serve (`php -S`, CWD = docroot) and `testbench migrate` (CWD = project)
     * agree on the same file.
     */
    protected function resolveDevSqlitePath(): void
    {
        if ($this->app->runningUnitTests() || env('DB_DATABASE') !== 'database/testbench.sqlite') {
            return;
        }

        $config = $this->app['config'];
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__) . '/database/testbench.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * Register a default `uploads` disk (docs/media-library-backend-blueprint.md
     * §3.1) IF the host hasn't already defined one — a package must not clobber
     * a host's own filesystems config. Root is storage_path('uploads'); a host
     * still runs `php artisan storage:link` once so the disk's bytes are
     * reachable by URL with zero PHP in the read path.
     *
     * The public/uploads -> storage/uploads entry is ALSO merged into
     * `filesystems.links` here (2026-08-10) — storage:link only creates links
     * that map declares, so registering the disk without the link entry left
     * the documented "just run storage:link" advice doing nothing (observed on
     * a real host install, which had to add the entry by hand). Skipped when the
     * host defined its own uploads disk: their disk, their link strategy.
     */
    protected function registerUploadsDisk(): void
    {
        $config = $this->app['config'];

        if ($config->get('filesystems.disks.uploads') !== null) {
            return;
        }

        $config->set('filesystems.disks.uploads', [
            'driver' => 'local',
            'root' => storage_path('uploads'),
            'url' => '/uploads',
            'visibility' => 'public',
            'throw' => false,
        ]);

        $links = (array) $config->get('filesystems.links', []);
        $publicUploads = public_path('uploads');
        if (! array_key_exists($publicUploads, $links)) {
            $links[$publicUploads] = storage_path('uploads');
            $config->set('filesystems.links', $links);
        }
    }

    /**
     * Bind the VirusScanner contract and the MediaLibraryService singleton
     * (docs/media-library-backend-blueprint.md §5.1, §11 step 4).
     */
    protected function registerMedia(): void
    {
        $this->app->singleton(VirusScanner::class, function ($app) {
            return $app->make(
                (string) $app['config']->get('heisenberg.media.virus_scanner', NullVirusScanner::class)
            );
        });

        $this->app->singleton(MediaLibraryService::class, fn ($app) => new MediaLibraryService(
            $app->make(VirusScanner::class),
        ));
    }

    /**
     * The AI assistant's singleton graph (docs/ai-mcp-plan.md): settings
     * repository, provider registry, the active provider, and the outbound MCP
     * client.
     *
     * Every binding is a closure, so nothing here resolves at boot. That matters
     * because `config('heisenberg.ai')` deliberately names adapters that ship in
     * a later phase — `::class` in a config array is a compile-time string, not
     * an autoload, and a binding that is never resolved never tries to load one.
     * The registry reports such a provider as `available: false` instead.
     */
    protected function registerAi(): void
    {
        $this->app->singleton(AiSettingsRepository::class, fn ($app) => new AiSettingsRepository(
            $app['config']->get('heisenberg.ai.settings_path'),
        ));

        // Where a UI-entered API key lives. Swappable so a host with a real
        // secrets manager can bind its own; the bundled store encrypts with the
        // app key and always lets an environment variable win.
        $this->app->singleton(\Heisenberg\Contracts\AiCredentialStore::class, function ($app) {
            $class = (string) $app['config']->get(
                'heisenberg.ai.credential_store',
                \Heisenberg\Adapters\EncryptedFileCredentialStore::class,
            );

            return $app->make($class);
        });

        $this->app->singleton(AiProviderRegistry::class, fn ($app) => new AiProviderRegistry(
            $app->make(AiSettingsRepository::class),
            $app->make(\Heisenberg\Contracts\AiCredentialStore::class),
        ));

        // Resolves to the adapter for whichever MODEL is in use — the model
        // names its provider, and the provider names the API format. Degrades to
        // the null object rather than throwing when nothing is configured.
        $this->app->singleton(AiProvider::class, fn ($app) => $app->make(AiProviderRegistry::class)->active());

        $this->app->singleton(\Heisenberg\Services\HeisenbergToolSource::class, fn ($app) => new \Heisenberg\Services\HeisenbergToolSource(
            $app->make(\Heisenberg\Services\McpToolRegistry::class),
        ));

        $this->app->singleton(\Heisenberg\Services\AiToolRunner::class, fn ($app) => new \Heisenberg\Services\AiToolRunner(
            // Resolved lazily: the MCP client binding throws when no adapter is
            // configured, and the runner is useful without one.
            $app->make(McpClient::class),
            $app->make(AiSettingsRepository::class),
            $app->make(\Heisenberg\Services\HeisenbergToolSource::class),
        ));

        $this->app->singleton(McpClient::class, function ($app) {
            $class = (string) $app['config']->get('heisenberg.ai.mcp.client.adapter', '');

            if ($class === '' || ! class_exists($class)) {
                throw new \RuntimeException(
                    'No MCP client adapter is available. Set heisenberg.ai.mcp.client.adapter to a class implementing '
                    . McpClient::class . '.'
                );
            }

            return $app->make($class);
        });
    }

    /**
     * The block-engine singleton graph (blueprint §1.4): validator -> registry,
     * sanitizer, renderer (sanitizer + registry).
     */
    protected function registerEngine(): void
    {
        $this->app->singleton(HtmlSanitizationService::class, fn () => new HtmlSanitizationService());

        $this->app->singleton(BlockContractValidator::class, fn ($app) => new BlockContractValidator(
            (string) $app['config']->get('heisenberg.block_prefix', 'heisenberg')
        ));

        $this->app->singleton(BlockRegistryService::class, fn ($app) => new BlockRegistryService(
            $app->make(BlockContractValidator::class),
            $app['config']->get('heisenberg.block_root'),
        ));

        $this->app->singleton(BlockRenderer::class, fn ($app) => new BlockRenderer(
            $app->make(BlockRegistryService::class),
        ));

        $this->app->singleton(BlocksPayloadService::class, fn ($app) => new BlocksPayloadService(
            $app->make(BlockRegistryService::class),
        ));

        $this->app->singleton(\Heisenberg\Services\ThemeRepository::class, fn ($app) => new \Heisenberg\Services\ThemeRepository(
            $app['config']->get('heisenberg.theme_path'),
        ));
        $this->app->singleton(\Heisenberg\Services\FontCatalogService::class, fn () => new \Heisenberg\Services\FontCatalogService());

        // docs/email-system.md §5 — beside BlockRenderer, never replacing it; same singleton
        // posture as the rest of this graph (both its own dependencies are already singletons).
        $this->app->singleton(\Heisenberg\Services\EmailRenderer::class, fn ($app) => new \Heisenberg\Services\EmailRenderer(
            $app->make(BlockRenderer::class),
            $app->make(\Heisenberg\Services\ThemeRepository::class),
        ));
    }

    public function boot(): void
        {
            $this->resolveDevSqlitePath();
            $this->registerPublishing();
            $this->registerResources();
            $this->registerRoutes();
            $this->registerMediaRoutes();
            $this->registerCommentRoutes();
            $this->registerTranslationRoutes();
            $this->registerSeoRoutes();
            $this->registerEmailRoutes();
            $this->registerPublicRoutes();
            $this->registerAiRoutes();
            $this->registerMcpRoutes();
            $this->registerPolicies();
            $this->registerLivewireComponents();
            $this->registerLocaleMiddleware();
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            if ($this->app->runningInConsole()) {
                $this->commands([
                    \Heisenberg\Console\Commands\TemplatesVerifyCommand::class,
                    \Heisenberg\Console\Commands\BlocksVerifyCommand::class,
                    \Heisenberg\Console\Commands\ConfigDiffCommand::class,
                    \Heisenberg\Console\Commands\MergeTranslationsCommand::class,
                    \Heisenberg\Console\Commands\WarmViewsCommand::class,
                ]);
            }

            // Later: scheduled commands (M4).
        }

        /**
         * Register the EditorLocaleMiddleware globally so every web request reads
         * `session('heisenberg.locale')` BEFORE the view renders, and calls
         * App::setLocale() against it. Hosts that mount the editor with their own
         * middleware stack should append this to their web group instead — see
         * the middleware docblock for the contract.
         */
        protected function registerLocaleMiddleware(): void
        {
            // Pushed onto the global 'web' group (not a package group) so host apps sharing
            // the session see a consistent locale on every page, not only /editor/*.
            $this->app['router']->pushMiddlewareToGroup('web', \Heisenberg\Http\Middleware\EditorLocaleMiddleware::class);
        }

    /**
     * Register the Editor's Livewire components. Guarded on the `livewire`
     * binding so the package still boots (and the test suite still runs) in a
     * host — or a test harness — that hasn't loaded Livewire's provider.
     */
    protected function registerLivewireComponents(): void
    {
        if (! $this->app->bound('livewire')) {
            return;
        }

        \Livewire\Livewire::component('heisenberg.media-library', \Heisenberg\Livewire\MediaLibrary::class);
    }

    /**
     * Load the opt-in editor routes (a host typically replaces these with its
     * own admin/staff routes). Enabled by default so the editor runs in dev.
     */
    protected function registerRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.editor.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/editor.php');
        }
    }

    /**
     * Load the opt-in media library routes (routes/media.php: index/upload/
     * update/destroy/select). Separate config key + middleware from the
     * editor group so a host can enable/protect each independently. Enabled
     * by default so the media library works in dev.
     */
    protected function registerMediaRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.media.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/media.php');
        }
    }

    /**
     * Load the native comments routes (routes/comments.php: the public
     * thread/submit pair plus the moderation surface). Same opt-out posture
     * as the media group — a host that binds its own PostCommentProvider
     * (or NullPostCommentProvider) sets heisenberg.comments.routes false and
     * these endpoints disappear entirely.
     */
    protected function registerCommentRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.comments.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/comments.php');
        }
    }

    /**
     * Load the public translations API route (routes/translations.php: GET
     * /heisenberg/posts/{post}/translations, docs/content-translation.md §7) — lets a host build
     * its own language-switcher buttons off the group's shared slug. Same opt-out posture as the
     * comments group above.
     */
    protected function registerTranslationRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.translations.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/translations.php');
        }
    }

    /**
     * Load the sitemap route (routes/seo.php: GET /sitemap.xml, docs/seo-system.md §5). Same
     * opt-out posture as the comments group — a host that publishes its own sitemap (or maps
     * `heisenberg.seo.url_template` at a real blog and wants a different generator entirely)
     * sets `heisenberg.seo.sitemap` false and this endpoint disappears.
     */
    protected function registerSeoRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.seo.sitemap', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/seo.php');
        }
    }

    /**
     * Load the served-email routes (routes/email.php: a built email at its own slug, plus the
     * HTML/.eml export, docs/email-system.md §6.1). Same opt-out posture as the comments group
     * — a host that serves its own "view in browser" page sets `heisenberg.email.routes` false
     * and calls {@see \Heisenberg\Services\EmailRenderer} directly. Turning it off also removes
     * the redirect target of the editor's own preview/export buttons, which is why they 404
     * rather than render anything themselves: an email has ONE public address or none.
     */
    protected function registerEmailRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.email.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/email.php');
        }
    }

    /**
     * Load the opt-in public show routes (routes/public.php: GET /posts/{locale}/{slug}).
     * Off by default — the package's stated design is "the host owns the page around it";
     * turning this on ships a turnkey public show route through BlockRenderer + the same
     * SEO/head/alternates/featured/comments pipeline the editor preview produces. A host
     * that wants a different URL shape (or their own template wrapper) keeps this off and
     * binds their own route + view through the {@see \Heisenberg\Contracts\PostUrlResolver}
     * seam + the published-template contract (docs/post-template-schema.md).
     */
    protected function registerPublicRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.public.routes', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public.php');
        }
    }

    /**
     * Load the opt-in AI routes (routes/ai.php: the assistant panel's own
     * settings API). Separate config key + middleware from the editor and media
     * groups, so a host can mount the editor without the AI surface. Enabled by
     * default; the bundled provider is a no-op, so this exposes no third party.
     */
    protected function registerAiRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.ai.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/ai.php');
        }
    }

    /**
     * Load the inbound MCP server routes (routes/mcp.php) — the surface other
     * AIs connect to in order to author pages here.
     *
     * OFF by default, unlike every other route group in this package: it is a
     * write API reachable with a bearer token and no session, so enabling it is
     * a deliberate config change. The route file is not even loaded until then,
     * and McpTokenMiddleware 404s as a second line of defence.
     */
    protected function registerMcpRoutes(): void
    {
        if ($this->app['config']->get('heisenberg.ai.mcp.server.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/mcp.php');
        }
    }

    /**
     * Register the media-library policy (blueprint §9). Bound against the
     * default PublicFile class — a host that swaps
     * config('heisenberg.models.public_file') for a subclass registers its own
     * Gate::policy() for that class (Laravel resolves policies from the exact
     * class of the model instance passed to authorize()/can()).
     */
    protected function registerPolicies(): void
    {
        Gate::policy(PublicFile::class, PublicFilePolicy::class);

        // Bound against the default Post class — a host that swaps
        // config('heisenberg.models.post') for a subclass registers its own
        // Gate::policy() for that class (same caveat as PublicFilePolicy above).
        Gate::policy(Post::class, PostPolicy::class);

        // Taxonomy policies (blueprint §10.1 BlogCategoryPolicy/BlogTagPolicy) —
        // same swap-the-model-class caveat as above.
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);

        // Comments moderation policy (routes/comments.php's moderation group) — bound against the
        // default Comment class, same swap-the-model-class caveat as above (a host that swaps
        // config('heisenberg.models.comment') for a subclass registers its own Gate::policy() for
        // that class).
        Gate::policy(Comment::class, CommentPolicy::class);
    }

    /**
     * Bind the decoupling contracts to the configured adapter classes, falling
     * back to the bundled defaults. Every binding is a shared singleton.
     */
    protected function registerContracts(): void
    {
        $this->app->singleton(MediaResolver::class, function ($app) {
            return $app->make(
                (string) $app['config']->get('heisenberg.media_resolver', NullMediaResolver::class)
            );
        });

        $this->app->singleton(RoleGate::class, function ($app) {
            $class = (string) $app['config']->get('heisenberg.role_gate', ConfigRoleGate::class);

            // The bundled default needs the role-tier map; arbitrary host gates
            // are resolved straight from the container.
            if ($class === ConfigRoleGate::class) {
                return new ConfigRoleGate((array) $app['config']->get('heisenberg.roles', []));
            }

            return $app->make($class);
        });

        $this->app->singleton(AuditSink::class, function ($app) {
            return $app->make(
                (string) $app['config']->get('heisenberg.audit_sink', NullAuditSink::class)
            );
        });

        $this->app->singleton(IconProvider::class, function ($app) {
            return $app->make(
                (string) $app['config']->get('heisenberg.icon_provider', PhosphorIconProvider::class)
            );
        });

        // A post's public URL — the sitemap and PreviewController's hreflang alternates both
        // resolve THIS contract, never SeoUrlResolver by concrete class, so a host binding
        // `heisenberg.seo.url_resolver` controls every public URL Heisenberg emits (docs/seo-
        // system.md §5). SeoUrlResolver (the config('heisenberg.seo.url_template') string/map
        // logic) is the bundled default, same seam shape as media_resolver/role_gate above.
        $this->app->singleton(\Heisenberg\Contracts\PostUrlResolver::class, function ($app) {
            return $app->make(
                (string) $app['config']->get('heisenberg.seo.url_resolver', \Heisenberg\Services\SeoUrlResolver::class)
            );
        });

        // Post-template capabilities that need storage this package does not own (post views,
        // comments, related posts, SEO meta). Same null-object seam as the four above: an
        // interface, a bundled default, and a config key naming the bound class — so an
        // adopter plugs in their own system instead of inheriting ours. Two of the four still
        // fall back to a null-object default; comments_provider and seo_meta_provider are the
        // exceptions — native storage now exists for both, so their fallback here is
        // NativeCommentProvider (2026-08-11) / NativeSeoMetaProvider (2026-08-11,
        // docs/seo-system.md Wave S1), not the null adapter (a host still binds the null
        // adapter explicitly in config/heisenberg.php to disable either).
        // See docs/post-template-schema.md for why these four are adapters and the other seven
        // template capabilities are rendered directly.
        foreach ([
            \Heisenberg\Contracts\PostViewsProvider::class    => ['post_views_provider', \Heisenberg\Adapters\NullPostViewsProvider::class],
            \Heisenberg\Contracts\PostCommentProvider::class  => ['comments_provider', \Heisenberg\Adapters\NativeCommentProvider::class],
            \Heisenberg\Contracts\RelatedPostsProvider::class => ['related_posts_provider', \Heisenberg\Adapters\NullRelatedPostsProvider::class],
            \Heisenberg\Contracts\PostSeoMetaProvider::class  => ['seo_meta_provider', \Heisenberg\Adapters\NativeSeoMetaProvider::class],
        ] as $contract => [$configKey, $default]) {
            $this->app->singleton($contract, fn ($app) => $app->make(
                (string) $app['config']->get("heisenberg.post_template.{$configKey}", $default)
            ));
        }

        // The template registry itself, config-wired (template_prefix +
        // template_root), so a host rendering posts through its own template
        // contract resolves it from the container instead of hand-constructing
        // the validator/registry pair the way TemplatesVerifyCommand does —
        // which is exactly what a host integration bench had to duplicate before
        // this binding existed (2026-08-10).
        $this->app->singleton(\Heisenberg\Services\PostTemplateContractValidator::class, fn ($app) => new \Heisenberg\Services\PostTemplateContractValidator(
            (string) $app['config']->get('heisenberg.template_prefix', 'heisenberg'),
        ));
        $this->app->singleton(\Heisenberg\Services\PostTemplateRegistryService::class, function ($app) {
            $root = $app['config']->get('heisenberg.template_root');

            return new \Heisenberg\Services\PostTemplateRegistryService(
                $app->make(\Heisenberg\Services\PostTemplateContractValidator::class),
                is_string($root) && $root !== '' ? $root : null,
            );
        });
    }

    /**
     * Expose the config (and, in later milestones, migrations/views/lang/blocks)
     * for `php artisan vendor:publish`.
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/heisenberg.php' => $this->app->configPath('heisenberg.php'),
        ], 'heisenberg-config');

        $this->publishes([
            __DIR__ . '/../resources/css' => $this->app->publicPath('vendor/heisenberg/css'),
            __DIR__ . '/../resources/js' => $this->app->publicPath('vendor/heisenberg/js'),
        ], 'heisenberg-assets');
    }

    /**
     * Register the package view + translation namespaces. The directories may be
     * empty during M0 — they are populated from M1 (blocks) and M7 (lang/views).
     */
    protected function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'heisenberg');

        $componentsDir = __DIR__ . '/../resources/views/components';
        if (is_dir($componentsDir)) {
            \Illuminate\Support\Facades\Blade::anonymousComponentPath($componentsDir);
        }

        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'heisenberg');
    }
}
