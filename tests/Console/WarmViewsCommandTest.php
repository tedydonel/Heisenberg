<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Console;

use Heisenberg\Tests\TestCase;

/**
 * `heisenberg:warm` (WarmViewsCommand) — a thin wrapper around the framework's own
 * `view:cache` that precompiles every Blade view (host + the `heisenberg::` namespace
 * registered via HeisenbergServiceProvider::loadViewsFrom()) so the first `/editor`
 * request never pays a cold-compile cost.
 *
 * Because the command's handle() calls `$this->call('view:cache')` (which needs a
 * real Symfony Application context to resolve that command by name), it must be run
 * through the full console kernel — `$this->artisan()` — rather than instantiated and
 * `run()` directly the way the simpler, self-contained commands in tests/M0 and
 * tests/Templates are (see those classes' own docblocks for why THEY can skip
 * Artisan::call()).
 *
 * `view.compiled` is pointed at a fresh, isolated temp directory (created and torn
 * down by this test) rather than the shared testbench skeleton's compiled-views
 * path — another concurrent test run hit stale/shared compiled views there, so this
 * suite deliberately never touches that shared directory. It must be set BEFORE the
 * application boots (a `getEnvironmentSetUp()` override), since the Blade compiler
 * instance captures the configured path once, at boot.
 */
class WarmViewsCommandTest extends TestCase
{
    private static string $compiledPath;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        self::$compiledPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-warm-views-compiled-' . uniqid('', true);
        @mkdir(self::$compiledPath, 0775, true);

        $app['config']->set('view.compiled', self::$compiledPath);
    }

    protected function tearDown(): void
    {
        $this->deleteTree(self::$compiledPath);
        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return string[] */
    private function compiledFiles(): array
    {
        return glob(self::$compiledPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
    }

    public function test_it_exits_successfully(): void
    {
        $this->artisan('heisenberg:warm')->assertExitCode(0);
    }

    public function test_it_compiles_blade_views_into_the_configured_compiled_path(): void
    {
        $this->assertSame([], $this->compiledFiles(), 'the isolated temp dir must start empty');

        $this->artisan('heisenberg:warm')->assertExitCode(0);

        $files = $this->compiledFiles();
        $this->assertNotEmpty($files, 'view:cache must have compiled at least one Blade view into view.compiled');
        // Every discovered Blade view (host + the `heisenberg::` namespace) is compiled to
        // valid, non-empty PHP under this path — a compile failure in ANY of them would
        // have thrown and failed the run above rather than leaving a partial file here.
        // Compiled Blade output is a PHP/HTML mix (raw markup interleaved with PHP tags),
        // so it need not begin with an opening tag — only contain one somewhere.
        $this->assertStringContainsString('<?php', (string) file_get_contents($files[0]));
    }

    /**
     * Idempotent: re-running it does not fail and the compiled views are still
     * present afterwards (view:cache clears and rebuilds the compiled cache on
     * every run — see ViewCacheCommand::handle()'s own `view:clear` call).
     */
    public function test_it_is_idempotent_across_repeated_runs(): void
    {
        $this->artisan('heisenberg:warm')->assertExitCode(0);
        $firstRunCount = count($this->compiledFiles());
        $this->assertGreaterThan(0, $firstRunCount);

        $this->artisan('heisenberg:warm')->assertExitCode(0);
        $this->artisan('heisenberg:warm')->assertExitCode(0);

        $this->assertGreaterThan(0, count($this->compiledFiles()));
    }
}
