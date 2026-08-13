<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M0;

use Heisenberg\Adapters\NativeSeoMetaProvider;
use Heisenberg\Adapters\NullPostSeoMetaProvider;
use Heisenberg\Adapters\NullRelatedPostsProvider;
use Heisenberg\HeisenbergServiceProvider;
use Heisenberg\Tests\TestCase;

/**
 * Integration pin for ConfigMerge as actually wired into
 * HeisenbergServiceProvider::register() (mergeHeisenbergConfig()) — not just
 * the pure-array unit tests in ConfigMergeTest.
 *
 * Testbench registers providers (running `register()`) BEFORE it runs
 * `getEnvironmentSetUp()`/`defineEnvironment()`, so there is no hook available
 * to pre-seed a "host's published config" into the container before the
 * package's own `register()` first runs — by the time any test method's setup
 * code executes, `config('heisenberg')` is already the package defaults
 * merged against an empty host. This test therefore does exactly what a real
 * boot does: it overwrites `config('heisenberg')` with a simulated STALE
 * published host config (a real config file, minus one nested key a later
 * package version added, plus one host-customized sibling and one overridden
 * top-level scalar), then re-runs the SAME `HeisenbergServiceProvider::
 * register()` a real boot would call — not a re-implementation of the merge —
 * against that simulated host config, and asserts on the resulting
 * `config()`.
 */
class ConfigMergeIntegrationTest extends TestCase
{
    public function test_register_fills_a_missing_nested_default_while_leaving_a_host_sibling_and_scalar_untouched(): void
    {
        $defaults = require HeisenbergServiceProvider::configPath();

        // Simulate a host that published the config before `related_posts_provider`
        // existed, and who separately customized `seo_meta_provider` (a sibling in
        // the same section) and the top-level `css_prefix` scalar.
        $staleHostConfig = $defaults;
        unset($staleHostConfig['post_template']['related_posts_provider']);
        $staleHostConfig['post_template']['seo_meta_provider'] = NullPostSeoMetaProvider::class;
        $staleHostConfig['css_prefix'] = 'acme';

        config(['heisenberg' => $staleHostConfig]);

        (new HeisenbergServiceProvider($this->app))->register();

        // The package default reaches config() for the key the host never had.
        $this->assertSame(
            NullRelatedPostsProvider::class,
            config('heisenberg.post_template.related_posts_provider')
        );

        // The host's own values, in the very same nested section and at the
        // top level, are completely untouched by the merge.
        $this->assertSame(NullPostSeoMetaProvider::class, config('heisenberg.post_template.seo_meta_provider'));
        $this->assertSame('acme', config('heisenberg.css_prefix'));

        // Sanity: the package's own shipped default for that same key is the
        // NATIVE adapter, not the null one the host chose — proving the
        // assertion above is really "host wins," not a coincidence of equal
        // defaults.
        $this->assertSame(NativeSeoMetaProvider::class, $defaults['post_template']['seo_meta_provider']);
    }
}
