<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Locks the exact MCP tool catalogue — names, descriptions, JSON schemas, tiers, and ORDER —
 * against a committed fixture. This exists as a refactor safety net (splitting
 * {@see McpToolRegistry}'s god-class tool table into `Heisenberg\Mcp\Tools\*` collaborators):
 * nothing here should ever change what `tools/list` answers or what the Expose tab
 * (`describeAll()`) shows, only how the answer is assembled.
 *
 * The fixture captures, for every (tier, surface) pair `listFor()` accepts, the full
 * `tools/list` result — plus `describeAll()` for both surfaces, which folds in tools that
 * exist but don't come with a body call would use (still worth pinning: it's what the
 * settings dialog's Expose tab renders).
 *
 * To regenerate the fixture after a DELIBERATE catalogue change (a new tool, a reworded
 * description, a schema tweak):
 *
 *     HEISENBERG_MCP_SNAPSHOT_UPDATE=1 php vendor/bin/phpunit tests/Mcp/ToolCatalogueSnapshotTest.php
 *
 * then run it again without the env var to confirm it now passes, and review the fixture
 * diff like any other reviewed change.
 */
class ToolCatalogueSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__ . '/../Fixtures/mcp/tool-catalogue.json';

    public function test_tool_catalogue_matches_committed_snapshot(): void
    {
        $registry = app(McpToolRegistry::class);

        $tiers = [McpToolRegistry::TIER_READ, McpToolRegistry::TIER_AUTHORS, McpToolRegistry::TIER_ADMINS];
        $surfaces = [McpToolRegistry::SURFACE_EDITOR, McpToolRegistry::SURFACE_EXTERNAL];

        $snapshot = ['listFor' => [], 'describeAll' => []];

        foreach ($tiers as $tier) {
            foreach ($surfaces as $surface) {
                $snapshot['listFor']["{$tier}/{$surface}"] = $registry->listFor($tier, $surface);
            }
        }

        foreach ($surfaces as $surface) {
            $snapshot['describeAll'][$surface] = $registry->describeAll($surface);
        }

        $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (getenv('HEISENBERG_MCP_SNAPSHOT_UPDATE') === '1') {
            file_put_contents(self::FIXTURE, $encoded);
            $this->markTestSkipped('Snapshot regenerated at ' . self::FIXTURE);
        }

        $this->assertFileExists(self::FIXTURE, 'Run with HEISENBERG_MCP_SNAPSHOT_UPDATE=1 to generate it.');
        $expected = (string) file_get_contents(self::FIXTURE);

        $this->assertSame(
            $expected,
            $encoded,
            'The MCP tool catalogue changed. If this is a deliberate catalogue change, regenerate the '
            . 'fixture with HEISENBERG_MCP_SNAPSHOT_UPDATE=1 php vendor/bin/phpunit tests/Mcp/ToolCatalogueSnapshotTest.php '
            . 'and review the diff; if this is a refactor, the catalogue must stay byte-identical.'
        );
    }
}
