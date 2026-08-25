<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;

/**
 * Conditional / dynamic render attributes (engine-requirements R-ATTR-RENDER):
 *   - boolean/presence attributes  → `{ "boolean": "{{…}}" }` renders bare when truthy, omitted when not
 *   - omittable value attributes   → `{ "value": "{{…}}", "omitEmpty": true }` renders only when non-empty
 *   - dynamic tags                 → a tag interpolated from a USER attribute is constrained to a safe
 *                                     allow-list (untrusted), unlike a static author-written tag
 */
class BlockRendererDynamicAttrsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-dyn-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->writeDyn();
        $this->writeHeading();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    private function render(array $attributes, string $slug = 'dyn'): string
    {
        $renderer = new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg'), $this->root));

        return $renderer->renderBlock(
            ['id' => 'b1', 'name' => "heisenberg/{$slug}", 'attributes' => $attributes, 'supports' => [], 'innerBlocks' => []],
            'en'
        );
    }

    public function test_dynamic_heading_tag_accepts_raw_integer_in_contract_enum(): void
    {
        $html = $this->render(['level' => 3, 'content' => 'Safe'], 'heading');

        $this->assertStringContainsString('<h3', $html);
    }

    public function test_dynamic_heading_tag_falls_back_to_first_enum_value_when_outside_enum(): void
    {
        $html = $this->render(['level' => 9, 'content' => 'Safe'], 'heading');

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringNotContainsString('<h9', $html);
    }

    public function test_dynamic_heading_tag_falls_back_to_first_enum_value_for_injection_input(): void
    {
        $html = $this->render(['level' => '\"><script>', 'content' => 'Safe'], 'heading');

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_shipped_heading_level_four_renders_h4_and_h1_is_a_valid_level(): void
    {
        $renderer = new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg')));

        $h4 = $renderer->renderBlock(
            ['id' => 'b1', 'name' => 'heisenberg/heading', 'attributes' => ['level' => 4, 'content' => 'Four'], 'supports' => [], 'innerBlocks' => []],
            'en'
        );
        $h1 = $renderer->renderBlock(
            ['id' => 'b2', 'name' => 'heisenberg/heading', 'attributes' => ['level' => 1, 'content' => 'One'], 'supports' => [], 'innerBlocks' => []],
            'en'
        );

        $this->assertStringContainsString('<h4', $h4);
        $this->assertStringContainsString('<h1', $h1);
        $this->assertStringNotContainsString('<h2', $h1);
    }

    public function test_boolean_attribute_is_bare_when_truthy_and_omitted_when_false(): void
    {
        $on = $this->render(['tagName' => 'section', 'isOpen' => true, 'start' => '']);
        $this->assertMatchesRegularExpression('/<section[^>]*\sopen[\s>]/', $on);
        $this->assertStringNotContainsString('open=""', $on);

        $off = $this->render(['tagName' => 'section', 'isOpen' => false, 'start' => '']);
        $this->assertStringNotContainsString('open', $off);
    }

    public function test_omit_empty_attribute_dropped_when_blank_kept_when_set(): void
    {
        $blank = $this->render(['tagName' => 'ol', 'isOpen' => false, 'start' => '']);
        $this->assertStringNotContainsString(' start=', $blank);

        $set = $this->render(['tagName' => 'ol', 'isOpen' => false, 'start' => '3']);
        $this->assertStringContainsString('start="3"', $set);
    }

    public function test_omit_when_empty_attribute_dropped_when_blank_and_kept_when_set(): void
    {
        $blank = $this->render(['tagName' => 'ol', 'isOpen' => false, 'start' => '']);
        $this->assertStringNotContainsString('data-start=', $blank);

        $set = $this->render(['tagName' => 'ol', 'isOpen' => false, 'start' => '3']);
        $this->assertStringContainsString('data-start="3"', $set);
    }

    public function test_dynamic_tag_is_constrained_to_a_safe_allow_list(): void
    {
        $safe = $this->render(['tagName' => 'section', 'isOpen' => false, 'start' => '']);
        $this->assertStringContainsString('<section', $safe);

        $danger = $this->render(['tagName' => 'script', 'isOpen' => false, 'start' => '']);
        $this->assertStringNotContainsString('<script', $danger);
        $this->assertStringContainsString('<div', $danger); // falls back to div
    }

    private function writeDyn(): void
    {
        $contract = [
            '$schema' => './schema.md', 'apiVersion' => 1, 'name' => 'heisenberg/dyn',
            'title' => 'Dyn', 'category' => 'design', 'icon' => 'box', 'description' => 'x',
            'keywords' => [], 'version' => '1.0.0',
            'attributes' => [
                'tagName' => ['type' => 'string', 'default' => 'div', 'enum' => ['div', 'section', 'article']],
                'isOpen' => ['type' => 'boolean', 'default' => false],
                'start' => ['type' => 'string', 'default' => ''],
            ],
            'supports' => [],
            'style' => ['className' => 'hb-block-dyn'],
            'render' => ['template' => [
                'tag' => '{{attributes.tagName}}',
                'class' => 'hb-block hb-block-dyn',
                'attributes' => [
                    'open' => ['boolean' => '{{attributes.isOpen}}'],
                    'start' => ['value' => '{{attributes.start}}', 'omitEmpty' => true],
                    'data-start' => ['value' => '{{attributes.start}}', 'omitWhenEmpty' => true],
                ],
            ], 'script' => null],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json'],
            'security' => ['allowCustomCss' => false],
        ];

        $dir = $this->root . DIRECTORY_SEPARATOR . 'dyn';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'dyn.json', json_encode($contract, JSON_UNESCAPED_SLASHES));
    }

    private function writeHeading(): void
    {
        $contract = [
            '$schema' => './schema.md', 'apiVersion' => 1, 'name' => 'heisenberg/heading',
            'title' => 'Heading', 'category' => 'text', 'icon' => 'heading', 'description' => 'x',
            'keywords' => [], 'version' => '1.0.0',
            'attributes' => [
                'content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-inline'],
                'level' => ['type' => 'integer', 'default' => 2, 'enum' => [2, 3, 4, 5, 6]],
            ],
            'supports' => [],
            'style' => ['className' => 'hb-block-heading'],
            'render' => ['template' => [
                'tag' => 'h{{attributes.level}}',
                'class' => 'hb-block hb-block-heading',
                'children' => [['type' => 'rich-text', 'attribute' => 'content']],
            ], 'script' => null],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json'],
            'security' => ['richText' => 'inline-basic', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];

        $dir = $this->root . DIRECTORY_SEPARATOR . 'heading';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'heading.json', json_encode($contract, JSON_UNESCAPED_SLASHES));
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
