<?php

declare(strict_types=1);

namespace Heisenberg\Console\Commands;

use Heisenberg\Services\PostTemplateContractValidator;
use Heisenberg\Services\PostTemplateRegistryService;
use Illuminate\Console\Command;

/**
 * The post-template parity sweep (docs/post-template-schema.md "Verifying
 * templates"), mirroring `blocks:verify`'s spirit for post templates: scan
 * `resources/templates` (or `config('heisenberg.template_root')`), validate
 * every contract via {@see PostTemplateContractValidator}, and report any
 * invalid ones. Unlike `blocks:verify` there is no third identifier set to
 * cross-check against (no enum of expected template names — a template is
 * picked by name at the host's discretion, not drawn from a closed list), so
 * this is a direct "is everything on disk valid" health check rather than a
 * three-way diff.
 *
 * Not yet registered with the console kernel — see the schema doc's "Wiring"
 * section for the one-line `$this->commands([...])` addition a host (or a
 * future HeisenbergServiceProvider change) needs to make this callable as
 * `php artisan templates:verify`. The class works standalone regardless
 * (`(new TemplatesVerifyCommand)->run(...)` or direct `handle()` testing).
 */
class TemplatesVerifyCommand extends Command
{
    protected $signature = 'templates:verify {--json : Emit machine-readable JSON instead of human-readable text}';

    protected $description = 'Scan resources/templates, validate every post-template contract, and report drift/invalid contracts.';

    public function handle(): int
    {
        $scan = $this->registry()->discover();

        $names = array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $scan['templates']);
        sort($names);
        $valid = $scan['errors'] === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'valid' => $valid,
                'templates' => $names,
                'errors' => $scan['errors'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $valid ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            '%d valid template(s): %s',
            count($names),
            $names === [] ? '(none)' : implode(', ', $names)
        ));

        if (! $valid) {
            $this->error(sprintf('%d error(s):', count($scan['errors'])));
            foreach ($scan['errors'] as $error) {
                $this->line(" - {$error['file']}: {$error['error']}");
            }

            return self::FAILURE;
        }

        $this->info('All post-template contracts are valid.');

        return self::SUCCESS;
    }

    private function registry(): PostTemplateRegistryService
    {
        $prefix = (string) config('heisenberg.template_prefix', 'heisenberg');
        $root = config('heisenberg.template_root');

        return new PostTemplateRegistryService(
            new PostTemplateContractValidator($prefix),
            is_string($root) && $root !== '' ? $root : null,
        );
    }
}
