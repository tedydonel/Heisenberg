<?php

declare(strict_types=1);

namespace Heisenberg;

use Heisenberg\Adapters\ConfigRoleGate;
use Heisenberg\Adapters\PhosphorIconProvider;
use Heisenberg\Adapters\NullAuditSink;
use Heisenberg\Adapters\NullMediaResolver;
use Heisenberg\Adapters\NullVirusScanner;
use Heisenberg\Contracts\AuditSink;
use Heisenberg\Contracts\IconProvider;
use Heisenberg\Contracts\MediaResolver;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Contracts\VirusScanner;
use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\Tag;
use Heisenberg\Policies\CategoryPolicy;
use Heisenberg\Policies\PostPolicy;
use Heisenberg\Policies\PublicFilePolicy;
use Heisenberg\Policies\TagPolicy;
use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Services\HtmlSanitizationService;
use Heisenberg\Services\MediaLibraryService;
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
        $this->mergeConfigFrom(__DIR__ . '/../config/heisenberg.php', 'heisenberg');

        $this->registerContracts();
        $this->registerEngine();
        $this->registerUploadsDisk();
        $this->registerMedia();

        // Later: domain singletons (PostStateMachine, PostTransitionAction, …).
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
     * still needs to run `php artisan storage:link` once (or add the
     * public/uploads -> storage/uploads pair itself) so the disk's bytes are
     * reachable by URL with zero PHP in the read path.
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

        // Overhaul 2026-07-18 — user theme storage + public font catalog.
        $this->app->singleton(\Heisenberg\Services\ThemeRepository::class, fn ($app) => new \Heisenberg\Services\ThemeRepository(
            $app['config']->get('heisenberg.theme_path'),
        ));
        $this->app->singleton(\Heisenberg\Services\FontCatalogService::class, fn () => new \Heisenberg\Services\FontCatalogService());
    }

    public function boot(): void
        {
            $this->resolveDevSqlitePath();
            $this->registerPublishing();
            $this->registerResources();
            $this->registerRoutes();
            $this->registerMediaRoutes();
            $this->registerPolicies();
            $this->registerLivewireComponents();
            $this->registerLocaleMiddleware();
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            // Drift detection for the two on-disk contract systems. Both are console-only, and
            // an Artisan command is unreachable by name until it is registered here — without
            // this, `php artisan templates:verify` / `blocks:verify` simply don't exist.
            if ($this->app->runningInConsole()) {
                $this->commands([
                    \Heisenberg\Console\Commands\TemplatesVerifyCommand::class,
                    \Heisenberg\Console\Commands\BlocksVerifyCommand::class,
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
            // `web` already runs before any of our package routes via the route
            // group's middleware — that guarantees the session exists. We push
            // our middleware onto the global 'web' group's stack so it applies
            // to every page (not only /editor/*), letting host apps that share
            // a session see a consistent locale too.
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

        // Post-template capabilities that need storage this package does not own (post views,
        // comments, related posts, SEO meta). Same null-object seam as the four above: an
        // interface, a bundled no-op default, and a config key naming the bound class — so an
        // adopter plugs in their own system instead of inheriting ours.
        // See docs/post-template-schema.md for why these four are adapters and the other seven
        // template capabilities are rendered directly.
        foreach ([
            \Heisenberg\Contracts\PostViewsProvider::class    => ['post_views_provider', \Heisenberg\Adapters\NullPostViewsProvider::class],
            \Heisenberg\Contracts\PostCommentProvider::class  => ['comments_provider', \Heisenberg\Adapters\NullPostCommentProvider::class],
            \Heisenberg\Contracts\RelatedPostsProvider::class => ['related_posts_provider', \Heisenberg\Adapters\NullRelatedPostsProvider::class],
            \Heisenberg\Contracts\PostSeoMetaProvider::class  => ['seo_meta_provider', \Heisenberg\Adapters\NullPostSeoMetaProvider::class],
        ] as $contract => [$configKey, $default]) {
            $this->app->singleton($contract, fn ($app) => $app->make(
                (string) $app['config']->get("heisenberg.post_template.{$configKey}", $default)
            ));
        }
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
