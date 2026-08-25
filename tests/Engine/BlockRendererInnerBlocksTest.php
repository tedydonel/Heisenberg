<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;

/**
 * Inner-block rendering (engine-requirements R-NEST-SLOT / R-NEST-RECURSE):
 * a container block's `render.template` carries a `{ "type": "inner-blocks" }`
 * node which the renderer expands by recursing the block's `innerBlocks`
 * children — each child rendered through its OWN contract (its own template and
 * sanitizers). Driven against temp fixture contracts so the capability is
 * exercised before any real container block ships.
 */
class BlockRendererInnerBlocksTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-inner-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->writeContract('test-leaf', $this->leafContract());
        $this->writeContract('test-container', $this->containerContract());
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    private function renderer(): BlockRenderer
    {
        return new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg'), $this->root));
    }

    public function test_renders_inner_blocks_in_document_order(): void
    {
        $block = $this->container('c1', [
            $this->leaf('a', 'Alpha'),
            $this->leaf('b', 'Beta'),
        ]);

        $html = $this->renderer()->renderBlock($block, 'en');

        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
        $this->assertLessThan(
            strpos($html, 'Beta'),
            strpos($html, 'Alpha'),
            'inner blocks must render in document order'
        );
        // children nested INSIDE the container wrapper (the slot expands in place)
        $this->assertMatchesRegularExpression(
            '#<div class="hb-block hb-block-test-container">.*Alpha.*Beta.*</div>#s',
            $html
        );
    }

    public function test_applies_each_childs_own_contract_when_nesting(): void
    {
        $inner = $this->container('c2', [$this->leaf('x', 'Deep')]);
        $outer = $this->container('c1', [$inner]);

        $html = $this->renderer()->renderBlock($outer, 'en');

        $this->assertStringContainsString('Deep', $html);
        // a container nested inside a container, leaf at the bottom — each via its own contract
        $this->assertMatchesRegularExpression(
            '#<div class="hb-block hb-block-test-container"><div class="hb-block hb-block-test-container">.*Deep.*</div></div>#s',
            $html
        );
    }

    public function test_caps_runaway_inner_block_recursion(): void
    {
        // A pathologically deep tree must not blow the stack; rendering stays bounded.
        $node = $this->leaf('bottom', 'BottomMarker');
        for ($i = 0; $i < 40; $i++) {
            $node = $this->container('c' . $i, [$node]);
        }

        $html = $this->renderer()->renderBlock($node, 'en');

        $this->assertStringContainsString('hb-block-test-container', $html); // top levels still render
        $this->assertStringNotContainsString('BottomMarker', $html);         // content past the cap is dropped
    }

    /** A container block instance wrapping the given child block instances. */
    private function container(string $id, array $children): array
    {
        return ['id' => $id, 'name' => 'heisenberg/test-container', 'attributes' => [], 'supports' => [], 'innerBlocks' => $children];
    }

    /** A leaf block instance carrying rich-text content. */
    private function leaf(string $id, string $text): array
    {
        return ['id' => $id, 'name' => 'heisenberg/test-leaf', 'attributes' => ['content' => $text], 'supports' => [], 'innerBlocks' => []];
    }

    private function leafContract(): array
    {
        return [
            '$schema' => './schema.md', 'apiVersion' => 1, 'name' => 'heisenberg/test-leaf',
            'title' => 'Test Leaf', 'category' => 'text', 'icon' => 'square', 'description' => 'x',
            'keywords' => [], 'version' => '1.0.0',
            'attributes' => ['content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block']],
            'supports' => [],
            'style' => ['className' => 'hb-block-test-leaf'],
            'render' => ['template' => ['tag' => 'p', 'class' => 'hb-block hb-block-test-leaf', 'children' => [['type' => 'rich-text', 'attribute' => 'content']]], 'script' => null],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json'],
            'security' => ['allowCustomCss' => false],
        ];
    }

    private function containerContract(): array
    {
        return [
            '$schema' => './schema.md', 'apiVersion' => 1, 'name' => 'heisenberg/test-container',
            'title' => 'Test Container', 'category' => 'design', 'icon' => 'box', 'description' => 'x',
            'keywords' => [], 'version' => '1.0.0',
            'attributes' => [],
            'supports' => [],
            'style' => ['className' => 'hb-block-test-container'],
            'render' => ['template' => ['tag' => 'div', 'class' => 'hb-block hb-block-test-container', 'children' => [['type' => 'inner-blocks']]], 'script' => null],
            'innerBlocks' => ['enabled' => true, 'allowedBlocks' => ['heisenberg/test-leaf']],
            'serialization' => ['mode' => 'json'],
            'security' => ['allowCustomCss' => false],
        ];
    }

    private function writeContract(string $slug, array $contract): void
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . $slug;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . $slug . '.json',
            json_encode($contract, JSON_UNESCAPED_SLASHES)
        );
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
