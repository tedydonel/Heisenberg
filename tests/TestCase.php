<?php

declare(strict_types=1);

namespace Heisenberg\Tests;

use Heisenberg\HeisenbergServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Under paratest every worker boots the SAME Testbench skeleton and they all race
     * to rewrite its bootstrap/cache/services.php + packages.php (write-temp-then-rename,
     * which collides on Windows and occasionally hands a worker a half-written manifest).
     * paratest gives each worker a stable TEST_TOKEN, so each gets its own manifest pair.
     * Paths are relative on purpose: Laravel resolves them against the skeleton's base
     * path, which sidesteps its "is this absolute?" check not knowing Windows drive
     * letters. A plain `phpunit` run has no TEST_TOKEN and is left exactly as it was.
     */
    protected function setUp(): void
    {
        $token = getenv('TEST_TOKEN');

        if (is_string($token) && $token !== '') {
            foreach (['APP_SERVICES_CACHE' => 'services', 'APP_PACKAGES_CACHE' => 'packages'] as $key => $name) {
                $path = "bootstrap/cache/{$name}-paratest-{$token}.php";
                $_ENV[$key] = $_SERVER[$key] = $path;
                putenv("{$key}={$path}");
            }
        }

        $this->isolateFileBackedStores();

        parent::setUp();

        // Livewire records "did a component render during THIS request?" in a static
        // (SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest). A real host boots a
        // fresh process per request so it starts false, but one PHPUnit process simulates
        // hundreds of requests: once any test renders a Livewire component, the static stays
        // true and Livewire starts injecting its `<!-- Livewire Styles -->` block into every
        // later HTML response — including the standalone email documents EmailPreviewController
        // returns, whose byte-exact assertions then fail depending only on test ORDER (the full
        // suite failed serially while every file passed alone). Reset it between tests the same
        // way Livewire's own long-running-runtime integration does.
        Livewire::flushState();
    }

    /** Per-test scratch directory for the file-backed stores; removed in tearDown(). */
    private ?string $fileStoreDir = null;

    /**
     * The theme, the saved-theme library, the AI settings and the AI credentials are FILES,
     * and by default they live under the Testbench skeleton's storage/ — which is shared by
     * every test run AND by `testbench serve`. Anything a dev-server session saved there (a
     * custom theme, a configured provider) silently became an input to the suite: the email
     * golden fixture was captured with a developer's leftover theme in it and broke the moment
     * the skeleton was purged. Each test gets its own empty directory instead, so "no saved
     * theme" really means package defaults.
     *
     * Set as ENV VARS rather than via config()->set(), because config/heisenberg.php reads
     * exactly these four keys through env(). Writing the env means the package's own config
     * file evaluates to the same temp path the host config holds — so `heisenberg:config-diff`
     * still sees an untouched config (see ConfigDiffCommandTest). Overriding the config values
     * directly would make every test look like a host that had customised four keys.
     */
    private function isolateFileBackedStores(): void
    {
        $this->fileStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-test-stores-' . bin2hex(random_bytes(6));

        $files = [
            'HEISENBERG_THEME_PATH' => 'theme.json',
            'HEISENBERG_SAVED_THEMES_PATH' => 'saved-themes.json',
            'HEISENBERG_AI_CREDENTIALS_PATH' => 'ai-credentials.json',
            'HEISENBERG_AI_SETTINGS_PATH' => 'ai-settings.json',
        ];

        foreach ($files as $key => $name) {
            $value = $this->fileStoreDir . DIRECTORY_SEPARATOR . $name;
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    protected function tearDown(): void
    {
        $dir = $this->fileStoreDir;
        $this->fileStoreDir = null;

        parent::tearDown();

        if ($dir !== null && is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * Turns CSRF verification off for the current test, on every supported Laravel.
     *
     * Disabling it by ONE class name is a trap: Laravel 11/12 put `ValidateCsrfToken` in the
     * `web` group, Laravel 13 puts `PreventRequestForgery` there (the two older names now
     * merely extend it), and `withoutMiddleware()` only swaps out the exact class it is given.
     * Naming just `ValidateCsrfToken` therefore silently stops working on 13 and every POST
     * comes back 419. Disable all the names this framework version actually has.
     */
    protected function withoutCsrfProtection(): static
    {
        return $this->withoutMiddleware(array_values(array_filter([
            'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
            'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
            'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        ], 'class_exists')));
    }

    /**
     * Backport of `Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication
     * ::actingAsGuest()`, which only exists on Laravel 12+ (added after Laravel 11.56 —
     * confirmed absent from Laravel 11's copy of that trait). Tests use this instead of
     * the framework method so they run unmodified on every supported Laravel: it needs a
     * REAL guest (no acting-as user at all), not merely an ability-flag denial, and
     * `actingAs()`/`be()` have no built-in opposite before the framework grew one. The
     * body is copied verbatim from Laravel 12/13's implementation — both calls
     * (`GuardHelpers::forgetUser()`, `AuthManager::shouldUse()`) already existed on
     * Laravel 11, so this behaves identically to the native method where one exists and
     * merely fills the gap where it doesn't.
     *
     * Declared `public` (not `protected`, unlike `withoutCsrfProtection()` above):
     * where the native trait method already exists (Laravel 12+), PHP requires an
     * override to keep its exact or wider visibility — the framework declares it
     * `public`, so this must too, or every Laravel 12/13 lane fatals on class load.
     */
    public function actingAsGuest($guard = null): static
    {
        $this->app['auth']->guard($guard)->forgetUser();

        $this->app['auth']->shouldUse($guard);

        return $this;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Livewire is a hard `require` and every real host auto-discovers it, so the
            // suite runs with it too. Leaving it out is not just unrealistic: compiled
            // Blade views are cached in the shared Testbench skeleton, so a view compiled
            // by `testbench serve` (Livewire on) calls app('livewire') and then fatals in
            // a Livewire-less test — pass/fail would depend on who compiled it first.
            LivewireServiceProvider::class,
            HeisenbergServiceProvider::class,
        ];
    }

    /**
     * App key (cookie encryption) + the test DB connection.
     *
     * Defaults to an in-memory SQLite connection so `php vendor/bin/phpunit`
     * passes with zero external services on a fresh checkout — the posts/blocks
     * schema (uuid/enum/json/timestamps/softDeletes + a cascading FK) is fully
     * representable on SQLite via Laravel's grammar (enum -> CHECK constraint,
     * FKs via `foreign_key_constraints`). Blueprint Rule 11 (the *full* future
     * magazine schema — taxonomy, revisions, patterns — gets MySQL-only tests
     * once those tables exist) still applies for that later, richer schema; a
     * host CI that wants real MySQL coverage today can still get it by setting
     * DB_CONNECTION=mysql (see phpunit.xml.dist) — BlockPersistenceTest degrades
     * to markTestSkipped() rather than erroring when that's requested but the
     * server isn't reachable.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('h', 32)));

        $connection = env('DB_CONNECTION', 'sqlite');
        $app['config']->set('database.default', $connection);

        if ($connection === 'mysql') {
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'heisenberg_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);

            return;
        }

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
