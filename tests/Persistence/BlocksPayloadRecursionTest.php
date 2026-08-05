<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Tests\TestCase;

/**
 * BlocksPayloadService::validateBlockInstance() used to only check that
 * `innerBlocks` was an array — it never validated a nested block's own
 * name/attributes/schemaVersion against its own contract, and applied no
 * depth cap. A client could persist an arbitrarily deep or attribute-invalid
 * nested tree. This exercises the fix: full recursive validation, capped at
 * the SAME {@see BlockRenderer::MAX_NESTING_DEPTH} the renderer enforces.
 *
 * Uses a small synthetic self-nesting contract (`heisenberg/nesting-fixture`,
 * `innerBlocks.allowedBlocks: "*"`, so it can nest another instance of
 * itself) written to a temp registry root — the block-contract pruning of
 * 2026-08-02 (heading + paragraph only, see TODO.md) removed the real
 * `heisenberg/columns`/`heisenberg/column` pair this test used to nest.
 */
class BlocksPayloadRecursionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-recursion-fixture-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->writeFixtureContract();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    private function registry(): BlockRegistryService
    {
        return new BlockRegistryService(new BlockContractValidator('heisenberg'), $this->root);
    }

    private function service(): BlocksPayloadService
    {
        return new BlocksPayloadService($this->registry());
    }

    private function envelope(array $blocks): array
    {
        return [
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'blocks' => $blocks,
        ];
    }

    private function nested(array $innerBlocks = [], array $attributes = []): array
    {
        return [
            'id' => 'nf-' . uniqid('', true),
            'name' => 'heisenberg/nesting-fixture',
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes + ['level' => 1],
            'supports' => [],
            'innerBlocks' => $innerBlocks,
        ];
    }

    /** level=9 is not in the fixture's declared enum ([1, 2, 3]). */
    private function invalidNested(): array
    {
        return $this->nested([], ['level' => 9]);
    }

    /** A chain of nested `heisenberg/nesting-fixture` blocks; the innermost sits at nesting depth `$depth`. */
    private function nestingChain(int $depth): array
    {
        $block = $this->nested([]);
        for ($d = $depth; $d > 0; $d--) {
            $block = $this->nested([$block]);
        }

        return $block;
    }

    public function test_an_invalid_grandchild_nested_two_levels_deep_is_rejected(): void
    {
        $tree = $this->nested([
            $this->nested([
                $this->invalidNested(),
            ]),
        ]);

        $result = $this->service()->validatePayload($this->envelope([$tree]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('blocks.0.innerBlocks.0.innerBlocks.0.attributes.level', $result['errorMap']);
    }

    public function test_a_valid_nested_tree_is_still_accepted(): void
    {
        $tree = $this->nested([
            $this->nested([]),
        ]);

        $result = $this->service()->validatePayload($this->envelope([$tree]));

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
    }

    public function test_a_tree_within_the_depth_cap_is_accepted(): void
    {
        $tree = $this->nestingChain(BlockRenderer::MAX_NESTING_DEPTH);

        $result = $this->service()->validatePayload($this->envelope([$tree]));

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
    }

    public function test_a_tree_deeper_than_the_cap_is_rejected(): void
    {
        $tree = $this->nestingChain(BlockRenderer::MAX_NESTING_DEPTH + 1);

        $result = $this->service()->validatePayload($this->envelope([$tree]));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('nesting depth', implode(' | ', $result['errors']));
    }

    private function writeFixtureContract(): void
    {
        $contract = [
            '$schema' => '../../../docs/block-schema.md',
            'apiVersion' => 1,
            'name' => 'heisenberg/nesting-fixture',
            'title' => 'Nesting fixture',
            'category' => 'design',
            'icon' => 'rows',
            'description' => 'Nesting fixture',
            'keywords' => ['fixture'],
            'version' => '1.0.0',
            'attributes' => [
                'level' => ['type' => 'integer', 'default' => 1, 'enum' => [1, 2, 3]],
            ],
            'supports' => [],
            'style' => ['css' => './nesting-fixture.css', 'className' => 'hb-block-nesting-fixture'],
            'render' => [
                'template' => [
                    'tag' => 'div',
                    'class' => 'hb-block hb-block-nesting-fixture',
                    'attributes' => ['data-block-name' => '{{name}}', 'data-block-id' => '{{id}}'],
                    'children' => [
                        ['type' => 'inner-blocks'],
                    ],
                ],
                'publicPartial' => 'blocks.nesting-fixture',
                'script' => null,
            ],
            'innerBlocks' => [
                'enabled' => true,
                'allowedBlocks' => '*',
                'orientation' => 'vertical',
                'appender' => 'none',
            ],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'none', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];

        $dir = $this->root . DIRECTORY_SEPARATOR . 'nesting-fixture';
        mkdir($dir, 0775, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'nesting-fixture.json',
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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
}
