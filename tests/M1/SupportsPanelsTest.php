<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M1;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;

class SupportsPanelsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-panels-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_empty_supports_produces_no_panels(): void
    {
        $this->writeContract([]);

        $this->assertSame([], $this->registeredBlock()['panels'] ?? null);
    }

    public function test_paragraph_supports_produce_canonical_panels_and_rows(): void
    {
        $this->writeContract([
            'color' => ['text' => true, 'background' => true],
            'typography' => ['fontSize' => true],
            'spacing' => ['margin' => true, 'padding' => true],
        ]);

        $panels = $this->registeredBlock()['panels'] ?? null;

        $this->assertIsArray($panels);
        $this->assertSame(['typography', 'color', 'margin', 'padding'], array_column($panels, 'key'));
        $this->assertSame(['Typography', 'Color', 'Margin', 'Padding'], array_column($panels, 'title'));
        // Overhaul 2026-07-18 — the .pen kit: fontSize is a unit input,
        // colors are typed color fields, single-value spacing is a unit row.
        $this->assertSame([
            [
                'type' => 'unit',
                'sanitize' => 'size-value',
                'source' => 'supports.typography.fontSize',
                'label' => 'Size',
            ],
        ], $this->rowShapes($panels[0]['controls']));
        $this->assertSame([
            [
                'type' => 'color',
                'sanitize' => 'color-value',
                'source' => 'supports.color.text',
                'label' => 'Text',
            ],
            [
                'type' => 'color',
                'sanitize' => 'color-value',
                'source' => 'supports.color.background',
                'label' => 'Background',
            ],
        ], $this->rowShapes($panels[1]['controls']));
        $this->assertSame([
            [
                'type' => 'unit',
                'sanitize' => 'size-value',
                'source' => 'supports.spacing.margin',
                'label' => 'Margin',
            ],
        ], $this->rowShapes($panels[2]['controls']));
        $this->assertSame([
            [
                'type' => 'unit',
                'sanitize' => 'size-value',
                'source' => 'supports.spacing.padding',
                'label' => 'Padding',
            ],
        ], $this->rowShapes($panels[3]['controls']));
        // Spacing panels expose the values-mode menu (gear popover).
        $this->assertSame('sides', collect($panels)->firstWhere('key', 'margin')['menu'] ?? null);

        // Per-side declarations derive half-width side fields with glyphs.
        $this->writeContract([
            'spacing' => ['margin' => ['top' => true, 'bottom' => true]],
        ]);
        $sides = $this->registeredBlock()['panels'] ?? [];
        $this->assertSame(['margin'], array_column($sides, 'key'));
        $this->assertSame(['supports.spacing.margin.top', 'supports.spacing.margin.bottom'], array_column($sides[0]['controls'], 'source'));
        $this->assertSame([true, true], array_column($sides[0]['controls'], 'half'));
        $this->assertSame(['sidefield', 'sidefield'], array_column($sides[0]['controls'], 'type'));
        $this->assertSame(['top', 'bottom'], array_column($sides[0]['controls'], 'side'));
    }

    public function test_size_supports_produce_unit_rows(): void
    {
        $this->writeContract([
            'size' => ['width' => true, 'maxWidth' => true],
        ]);

        $panels = $this->registeredBlock()['panels'] ?? [];

        $this->assertSame(['size'], array_column($panels, 'key'));
        $this->assertSame(['supports.size.width', 'supports.size.maxWidth'], array_column($panels[0]['controls'], 'source'));
        $this->assertSame(['Width', 'Max Width'], array_column($panels[0]['controls'], 'label'));
        $this->assertSame(['unit', 'unit'], array_column($panels[0]['controls'], 'type'));
    }

    public function test_line_height_support_adds_a_typography_row(): void
    {
        $this->writeContract([
            'typography' => ['fontSize' => true, 'lineHeight' => true],
        ]);

        $panels = $this->registeredBlock()['panels'] ?? [];
        $lineHeight = $this->controlBySource($panels[0]['controls'] ?? [], 'supports.typography.lineHeight');

        $this->assertNotNull($lineHeight);
        $this->assertSame('number', $lineHeight['type']);
        $this->assertSame('Line height', $lineHeight['label']);
    }

    public function test_unknown_support_group_is_ignored(): void
    {
        $this->writeContract(['telekinesis' => ['levitate' => true]]);

        $validator = new class('heisenberg') extends BlockContractValidator
        {
            public function validate(array $contract): array
            {
                return ['valid' => true, 'errors' => []];
            }
        };

        $this->assertSame([], $this->registeredBlock($validator)['panels'] ?? null);
    }

    public function test_token_options_come_from_configured_registry(): void
    {
        config()->set('heisenberg.tokens.color', [
            '' => 'Automatic',
            'var(--brand-primary)' => 'Brand primary',
        ]);
        $this->writeContract(['color' => ['text' => true]]);

        $panels = $this->registeredBlock()['panels'] ?? [];

        $this->assertSame([
            ['value' => '', 'label' => 'Automatic'],
            ['value' => 'var(--brand-primary)', 'label' => 'Brand primary'],
        ], $panels[0]['controls'][0]['options'] ?? null);
    }

    private function registeredBlock(?BlockContractValidator $validator = null): array
    {
        $registry = new BlockRegistryService(
            $validator ?? new BlockContractValidator('heisenberg'),
            $this->root,
        );

        return $registry->registry()['blocks'][0] ?? [];
    }

    private function writeContract(array $supports): void
    {
        $contract = [
            '$schema' => './schema.md',
            'apiVersion' => 1,
            'name' => 'heisenberg/panel-fixture',
            'title' => 'Panel fixture',
            'category' => 'text',
            'icon' => 'pilcrow',
            'description' => 'Panel fixture',
            'keywords' => ['panel'],
            'version' => '1.0.0',
            'attributes' => [
                'content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block'],
            ],
            'supports' => $supports,
            'style' => [
                'css' => './panel-fixture.css',
                'className' => 'hb-block-panel-fixture',
                'variables' => [],
            ],
            'render' => [
                'template' => [
                    'tag' => 'p',
                    'class' => 'hb-block hb-block-panel-fixture',
                    'children' => [
                        ['type' => 'rich-text', 'attribute' => 'content', 'class' => 'hb-block-panel-fixture__text'],
                    ],
                ],
                'publicPartial' => 'blocks.panel-fixture',
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => [
                'mode' => 'json',
                'saveAttributes' => true,
                'saveSupports' => true,
                'saveInnerBlocks' => true,
                'migrations' => [],
            ],
            'security' => [
                'richText' => 'inline-basic',
                'allowRawHtml' => false,
                'allowCustomCss' => false,
            ],
        ];

        $dir = $this->root . DIRECTORY_SEPARATOR . 'panel-fixture';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'panel-fixture.json',
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /** @return list<array{type: mixed, sanitize: mixed, source: mixed, label: mixed}> */
    private function rowShapes(array $controls): array
    {
        return array_map(static fn (array $control): array => [
            'type' => $control['type'] ?? null,
            'sanitize' => $control['sanitize'] ?? null,
            'source' => $control['source'] ?? null,
            'label' => $control['label'] ?? null,
        ], $controls);
    }

    private function controlBySource(array $controls, string $source): ?array
    {
        foreach ($controls as $control) {
            if (($control['source'] ?? null) === $source) {
                return $control;
            }
        }

        return null;
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
