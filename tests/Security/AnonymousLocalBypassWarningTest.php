<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Security;

use Heisenberg\HeisenbergServiceProvider;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * The boot-time Log::warning() that fires while the local-dev anonymous
 * bypass (see src/Adapters/LocalDevRoleGate.php) is active — the editor and
 * its media library authorizing EVERY request, including a fully anonymous
 * one, is expected on a developer's own machine but must never go unnoticed
 * anywhere else.
 *
 * warnAboutAnonymousLocalBypassIfActive() is called directly here rather than
 * through boot() itself: boot()'s own gate (maybeWarnAboutAnonymousLocalBypass())
 * deliberately skips while `runningUnitTests()` is true, precisely so this
 * warning never fires as noise on every single test in the suite. Testing the
 * WARNING LOGIC itself therefore has to bypass that outer gate — which is
 * exactly what the split into two methods is for (see its docblock) — while a
 * dedicated test below confirms the outer gate really does suppress it during
 * a real test run.
 */
class AnonymousLocalBypassWarningTest extends TestCase
{
    private function provider(): HeisenbergServiceProvider
    {
        /** @var HeisenbergServiceProvider $provider */
        $provider = $this->app->getProvider(HeisenbergServiceProvider::class);

        return $provider;
    }

    public function test_it_warns_when_the_bypass_is_fully_active(): void
    {
        Log::spy();
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => true, 'heisenberg.warn_anonymous_in_local' => true]);

        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'Heisenberg')
                && str_contains($message, 'anonymous')
                && str_contains($message, 'NEVER be true'));
    }

    public function test_repeated_boots_inside_the_throttle_window_warn_only_once(): void
    {
        Log::spy();
        $this->app['env'] = 'local';
        // The Testbench skeleton defaults to the `database` store with no cache table,
        // which is the "cache backend is down" path (warn unthrottled) — not this one.
        config([
            'cache.default' => 'array',
            'heisenberg.allow_anonymous_in_local' => true,
            'heisenberg.warn_anonymous_in_local' => true,
        ]);

        // boot() runs per request, so this is "three requests in a row".
        $this->provider()->warnAboutAnonymousLocalBypassIfActive();
        $this->provider()->warnAboutAnonymousLocalBypassIfActive();
        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_does_not_warn_outside_the_local_environment(): void
    {
        Log::spy();
        $this->app['env'] = 'testing';
        config(['heisenberg.allow_anonymous_in_local' => true, 'heisenberg.warn_anonymous_in_local' => true]);

        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_it_does_not_warn_when_the_bypass_itself_is_disabled(): void
    {
        Log::spy();
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => false, 'heisenberg.warn_anonymous_in_local' => true]);

        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_it_can_be_silenced_independently_of_the_bypass_itself(): void
    {
        Log::spy();
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => true, 'heisenberg.warn_anonymous_in_local' => false]);

        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * APP_DEBUG=false alongside APP_ENV=local is a signal this box might not
     * actually be a developer's own machine — the wording escalates rather
     * than staying identical.
     */
    public function test_wording_escalates_when_app_debug_is_false(): void
    {
        Log::spy();
        $this->app['env'] = 'local';
        config([
            'heisenberg.allow_anonymous_in_local' => true,
            'heisenberg.warn_anonymous_in_local' => true,
            'app.debug' => false,
        ]);

        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'POSSIBLE PRODUCTION MISCONFIGURATION'));
    }

    public function test_wording_does_not_escalate_when_app_debug_is_true(): void
    {
        Log::spy();
        $this->app['env'] = 'local';
        config([
            'heisenberg.allow_anonymous_in_local' => true,
            'heisenberg.warn_anonymous_in_local' => true,
            'app.debug' => true,
        ]);

        $this->provider()->warnAboutAnonymousLocalBypassIfActive();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => ! str_contains($message, 'POSSIBLE PRODUCTION MISCONFIGURATION'));
    }

    /**
     * The outer gate boot() actually calls — this must NEVER log during a
     * real test run, however the bypass itself is configured, or every test
     * in the whole suite would spam it on every boot.
     *
     * Deliberately does NOT override `$this->app['env']`: this IS the actual
     * environment every test in this repository already boots under (see
     * phpunit.xml.dist), which is exactly the condition `runningUnitTests()`
     * detects. (Overriding env to 'local' here to exercise the bypass
     * condition would, by Laravel's own `runningUnitTests()` definition —
     * `$this['env'] === 'testing'` — ALSO flip that check to false, so the
     * two conditions can never be tested as independent axes in the same
     * process; this test instead pins the one combination that actually
     * occurs on every real test run.)
     */
    public function test_the_boot_time_gate_is_silent_during_a_real_test_run(): void
    {
        $this->assertTrue($this->app->runningUnitTests(), 'precondition: this IS what every test in the suite runs under');

        Log::spy();
        config(['heisenberg.allow_anonymous_in_local' => true, 'heisenberg.warn_anonymous_in_local' => true]);

        // Calls the SAME method boot() calls — unlike every other test above,
        // which calls warnAboutAnonymousLocalBypassIfActive() directly to
        // bypass this exact gate.
        $method = new \ReflectionMethod($this->provider(), 'maybeWarnAboutAnonymousLocalBypass');
        $method->setAccessible(true);
        $method->invoke($this->provider());

        Log::shouldNotHaveReceived('warning');
    }
}
