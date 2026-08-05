<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Templates;

use Heisenberg\Console\Commands\BlocksVerifyCommand;
use Heisenberg\Enums\BlockType;
use Heisenberg\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `blocks:verify` — the long-missing command referenced by
 * docs/BLUEPRINT.md §12.2 and {@see BlockType}'s own docblock but never
 * built (confirmed absent: no `src/Console` existed before this delivery).
 * Added alongside `templates:verify` since both are cheap and the blueprint
 * calls this "the cheapest possible defence against a block type that's
 * editable but unrenderable." Not yet registered with the console kernel —
 * same "Wiring" note as `templates:verify` — so it is run directly here.
 */
class BlocksVerifyCommandTest extends TestCase
{
    private function runCommand(array $input = []): array
    {
        $command = new BlocksVerifyCommand();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }

    public function test_json_output_reports_the_documented_shape(): void
    {
        [, $text] = $this->runCommand(['--json' => true]);
        $payload = json_decode($text, true);

        foreach (['in_sync', 'sets', 'diffs'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        foreach (['enum', 'contract'] as $key) {
            $this->assertArrayHasKey($key, $payload['sets']);
        }
        foreach (['enum_missing_contract', 'contract_missing_enum'] as $key) {
            $this->assertArrayHasKey($key, $payload['diffs']);
        }
    }

    public function test_the_enum_set_matches_block_type_values(): void
    {
        [, $text] = $this->runCommand(['--json' => true]);
        $payload = json_decode($text, true);

        $expected = BlockType::values();
        sort($expected);
        $this->assertSame($expected, $payload['sets']['enum']);
    }

    public function test_reports_drift_between_block_type_and_the_shipped_contracts(): void
    {
        // As-built today: only 2 of the 20 BlockType cases (paragraph, heading)
        // have a shipped contract — the block-contract pruning of 2026-08-02
        // (see TODO.md) dropped the other 13 shipped contracts alongside the
        // builder's removal. Both `paragraph` and `heading` have a matching
        // enum case, so `contract_missing_enum` is now EMPTY (no contract
        // exists that the enum doesn't already know about); the other
        // direction (enum cases with no contract) still has 18 entries, so
        // this command must (correctly) still report non-zero overall.
        [$exitCode, $text] = $this->runCommand(['--json' => true]);
        $payload = json_decode($text, true);

        $this->assertFalse($payload['in_sync']);
        $this->assertNotEmpty($payload['diffs']['enum_missing_contract']);
        $this->assertSame([], $payload['diffs']['contract_missing_enum']);
        $this->assertSame(1, $exitCode);
    }

    public function test_human_readable_output_mentions_drift(): void
    {
        [$exitCode, $text] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Drift detected', $text);
    }
}
