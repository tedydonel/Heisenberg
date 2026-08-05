<?php

declare(strict_types=1);

namespace Heisenberg\Console\Commands;

use Heisenberg\Enums\BlockType;
use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Illuminate\Console\Command;

/**
 * The parity sweep promised by docs/BLUEPRINT.md §12.2 ("blog:blocks:verify")
 * and TARGET-renamed there to `heisenberg:blocks:verify`, referenced by
 * {@see BlockType}'s own docblock ("the `blocks:verify` parity command") —
 * but never actually built. This ships it as `blocks:verify` alongside
 * `templates:verify` since the two are cheap to add together and the
 * blueprint calls it "the cheapest possible defence against a block type
 * that's editable but unrenderable."
 *
 * Diffs two identifier sets and reports drift:
 *  - the **enum set** — {@see BlockType::values()};
 *  - the **contract set** — every validly-discovered contract's `name`, with
 *    the configured block prefix stripped and `-` -> `_`
 *    (`heisenberg/section-head` -> `section_head`).
 *
 * This is a DELIBERATE simplification of the AS-BUILT three-way sweep
 * (docs/BLUEPRINT.md §12.2), which additionally reflected `BlockRenderer`'s
 * `render*` methods as a third "renderer set" (per-block-type legacy render
 * methods, GTC-era). That dimension does not exist in Heisenberg's
 * `BlockRenderer` as built: rendering is fully generic and contract-driven
 * (`renderNode()` walks any contract's template tree; there are no per-type
 * `renderParagraph()`/`renderHeading()`/... methods left to reflect). Adding
 * that reflection back would only ever find the same handful of internal
 * helpers regardless of how many contracts exist — it would never detect
 * drift — so it is dropped here rather than shipped as dead weight. See the
 * class docblock in `BlockRenderer` for confirmation of the current method set.
 */
class BlocksVerifyCommand extends Command
{
    protected $signature = 'blocks:verify {--json : Emit machine-readable JSON instead of human-readable text}';

    protected $description = 'Cross-check BlockType against the shipped block contracts and report drift.';

    public function handle(): int
    {
        $prefix = (string) config('heisenberg.block_prefix', 'heisenberg');
        $registry = new BlockRegistryService(new BlockContractValidator($prefix), config('heisenberg.block_root'));

        $enumSet = BlockType::values();
        sort($enumSet);

        $contractSet = [];
        foreach ($registry->discover()['blocks'] as $contract) {
            $name = (string) ($contract['name'] ?? '');
            if (! str_starts_with($name, $prefix . '/')) {
                continue;
            }
            $contractSet[] = str_replace('-', '_', substr($name, strlen($prefix) + 1));
        }
        $contractSet = array_values(array_unique($contractSet));
        sort($contractSet);

        $diffs = [
            'enum_missing_contract' => array_values(array_diff($enumSet, $contractSet)),
            'contract_missing_enum' => array_values(array_diff($contractSet, $enumSet)),
        ];
        $inSync = $diffs['enum_missing_contract'] === [] && $diffs['contract_missing_enum'] === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'in_sync' => $inSync,
                'sets' => ['enum' => $enumSet, 'contract' => $contractSet],
                'diffs' => $diffs,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $inSync ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf('%d block type(s), %d contract(s).', count($enumSet), count($contractSet)));

        foreach ($diffs as $label => $values) {
            if ($values === []) {
                continue;
            }
            $this->warn("{$label}: " . implode(', ', $values));
        }

        if ($inSync) {
            $this->info('BlockType and the shipped contracts are in sync.');

            return self::SUCCESS;
        }

        $this->error('Drift detected between BlockType and the shipped contracts.');

        return self::FAILURE;
    }
}
