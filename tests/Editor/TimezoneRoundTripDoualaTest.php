<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\Support\TimezoneRoundTripCases;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Africa/Douala (UTC+1, no DST) half of the timezone round-trip regression pin — see
 * tests/Support/TimezoneRoundTripCases.php's own docblock for the bug this pins. This is the
 * owner's own machine's zone and the zone the reported +1h drift actually reproduced in.
 */
final class TimezoneRoundTripDoualaTest extends TestCase
{
    use RefreshDatabase;
    use TimezoneRoundTripCases;

    // Testbench's own timezone extension point — resolveApplicationConfiguration() calls
    // this (and feeds the result into date_default_timezone_set()) BEFORE getEnvironmentSetUp()
    // ever runs, so overriding app.timezone there is too late to affect PHP's default zone.
    // Setting config here too keeps config('app.timezone') and the actual PHP zone in sync,
    // same invariant PostController's TIMEZONE docblock relies on.
    protected function getApplicationTimezone($app)
    {
        $app['config']->set('app.timezone', 'Africa/Douala');

        return 'Africa/Douala';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }
}
