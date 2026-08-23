<?php

declare(strict_types=1);

namespace Heisenberg\Console\Commands;

use Illuminate\Console\Command;

/**
 * Precompile every Blade view (host + package) so the first /editor request doesn't
 * pay the cold-compile cost. The /editor route loads ~100+ Blade views including
 * resources/views/components/live/block-runtime.blade.php (~123 KB), and a cold
 * compile on a default PHP/wall budget can exceed 30 s and 500 — a live defect
 * the editor's `set_time_limit(120)` only partially mitigates (an upstream
 * php-fpm `request_terminate_timeout` still wins). `view:cache` is the
 * real fix: it compiles every view the Finder knows about — including the
 * heisenberg:: namespace registered via {@see \Heisenberg\HeisenbergServiceProvider}'s
 * loadViewsFrom() — once at deploy time, so the runtime only loads.
 *
 * Thin wrapper around `view:cache` + a clear "what just happened" line so the
 * command shows up in a deploy runbook next to `migrate --force` and
 * `config:cache`. Idempotent: re-running overwrites the compiled cache in place.
 * Does NOT run config:cache / route:cache / event:cache — those are host
 * choices (some hosts deliberately skip route caching for very large route
 * files) and out of scope here.
 */
class WarmViewsCommand extends Command
{
    protected $signature = 'heisenberg:warm';

    protected $description = 'Precompile every Blade view (host + Heisenberg package) so the first /editor request is not a cold compile.';

    public function handle(): int
    {
        $this->info('Precompiling Blade views…');

        $exitCode = $this->call('view:cache');

        if ($exitCode !== self::SUCCESS) {
            $this->error('view:cache failed; see output above.');

            return self::FAILURE;
        }

        $this->info('Views precompiled. The first /editor request will no longer cold-compile.');

        return self::SUCCESS;
    }
}
