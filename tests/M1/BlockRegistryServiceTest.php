<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M1;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;

/**
 * The registry discovers contract JSON on disk, validates each, and serves a
 * hashed, localized envelope to the editor (§3.4). Driven here against a temp
 * fixture directory so the logic (discovery, dedup, locale-stable hashing,
 * localization, traversal guard) is exercised in isolation from the shipped
 * contracts.
 */
class BlockRegistryServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-registry-' . uniqid('', true);
        mkdir($this->root, 0775, true);
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

    /** Write a valid contract to <root>/<slug>/<slug>.json, with overrides. */
    private function writeContract(string $slug, array $overrides = []): void
    {
        $contract = array_merge($this->baseContract($slug), $overrides);
        $dir = $this->root . DIRECTORY_SEPARATOR . $slug;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . $slug . '.json',
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeRaw(string $relativePath, string $contents): void
    {
        $full = $this->root . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($full, $contents);
    }

    private function baseContract(string $slug): array
    {
        return [
            '$schema' => './schema.md',
            'apiVersion' => 1,
            'name' => "heisenberg/{$slug}",
            'title' => "heisenberg::blocks.{$slug}.title",
            'category' => 'text',
            'icon' => 'pilcrow',
            'description' => "heisenberg::blocks.{$slug}.description",
            'keywords' => [$slug],
            'version' => '1.0.0',
            'attributes' => [
                'content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block'],
            ],
            'supports' => ['color' => ['text' => true]],
            'style' => [
                'css' => "./{$slug}.css",
                'className' => "hb-block-{$slug}",
                'variables' => [
                    '--hb-color' => ['source' => 'supports.color.text', 'default' => 'var(--accent-1)', 'sanitize' => 'color-token'],
                ],
            ],
            'render' => [
                'template' => ['tag' => 'p', 'class' => "hb-block hb-block-{$slug}", 'children' => [['type' => 'rich-text', 'attribute' => 'content', 'class' => '.x']]],
                'publicPartial' => "blocks.{$slug}",
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'inline-basic', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];
    }

    public function test_discovers_valid_contracts_sorted_by_name(): void
    {
        $this->writeContract('paragraph', ['icon' => 'pilcrow', 'category' => 'text']);
        $this->writeContract('heading', ['icon' => 'bookmark', 'category' => 'text']);
        $this->writeContract('image', ['icon' => 'image', 'category' => 'media']);

        $registry = $this->registry()->registry();

        $names = array_map(static fn (array $b): string => $b['name'], $registry['blocks']);
        $this->assertSame(['heisenberg/heading', 'heisenberg/image', 'heisenberg/paragraph'], $names);
        $this->assertSame([], $registry['errors']);
    }

    public function test_invalid_contract_is_excluded_and_reported(): void
    {
        $this->writeContract('paragraph');
        $this->writeContract('broken', ['name' => 'not-a-valid-name']);

        $registry = $this->registry()->registry();

        $names = array_map(static fn (array $b): string => $b['name'], $registry['blocks']);
        $this->assertSame(['heisenberg/paragraph'], $names);
        $this->assertNotEmpty($registry['errors']);
    }

    public function test_malformed_json_is_reported_not_thrown(): void
    {
        $this->writeContract('paragraph');
        $this->writeRaw('broken/broken.json', '{ this is not json ');

        $registry = $this->registry()->registry();

        $this->assertNotEmpty($registry['errors']);
        $this->assertCount(1, $registry['blocks']);
    }

    public function test_duplicate_name_keeps_first_and_reports(): void
    {
        $this->writeContract('paragraph');
        $this->writeRaw(
            'dupe/dupe.json',
            json_encode(array_merge($this->baseContract('paragraph'), ['name' => 'heisenberg/paragraph']))
        );

        $registry = $this->registry()->registry();

        $this->assertCount(1, $registry['blocks']);
        $this->assertNotEmpty($registry['errors']);
    }

    public function test_registry_hash_is_sha256_prefixed_and_locale_stable(): void
    {
        $this->writeContract('paragraph');
        $this->writeContract('heading', ['icon' => 'bookmark']);

        $en = $this->registry()->registry('en');
        $fr = $this->registry()->registry('fr');

        $this->assertStringStartsWith('sha256:', $en['registryHash']);
        $this->assertSame($en['registryHash'], $fr['registryHash'], 'hash must not change with locale');
    }

    public function test_localizes_namespaced_labels_without_changing_the_hash(): void
    {
        $this->writeContract('paragraph');
        app('translator')->addLines(['blocks.paragraph.title' => 'Paragraphe'], 'fr', 'heisenberg');

        $bare = $this->registry()->registry('en');
        $localized = $this->registry()->registry('fr');

        $this->assertSame('Paragraphe', $localized['blocks'][0]['title']);
        $this->assertSame($bare['registryHash'], $localized['registryHash']);
    }

    public function test_categories_are_sorted_and_distinct(): void
    {
        $this->writeContract('paragraph', ['category' => 'text']);
        $this->writeContract('heading', ['category' => 'text']);
        $this->writeContract('image', ['category' => 'media']);

        $this->assertSame(['media', 'text'], $this->registry()->registry()['categories']);
    }

    public function test_referenced_icons_are_collected_distinct(): void
    {
        $this->writeContract('paragraph', ['icon' => 'pilcrow']);
        $this->writeContract('heading', ['icon' => 'bookmark']);
        $this->writeContract('image', ['icon' => 'pilcrow']);

        $icons = $this->registry()->registry()['icons'];
        $this->assertContains('pilcrow', $icons);
        $this->assertContains('bookmark', $icons);
        $this->assertSame(array_values(array_unique($icons)), $icons);
    }

    public function test_get_block_and_is_block_known(): void
    {
        $this->writeContract('paragraph');

        $registry = $this->registry();
        $this->assertTrue($registry->isBlockKnown('heisenberg/paragraph'));
        $this->assertFalse($registry->isBlockKnown('heisenberg/nope'));
        $this->assertSame('heisenberg/paragraph', $registry->getBlock('heisenberg/paragraph')['name']);
        $this->assertNull($registry->getBlock('heisenberg/nope'));
    }

    public function test_scan_cache_invalidates_when_contract_files_change(): void
    {
        $this->writeContract('alpha');
        $registry = $this->registry();

        $this->assertTrue($registry->isBlockKnown('heisenberg/alpha'));
        $this->assertFalse($registry->isBlockKnown('heisenberg/beta'));

        // A NEW contract must become visible to the SAME instance (persistent workers
        // never re-construct the singleton).
        $this->writeContract('beta');
        $this->assertTrue($registry->isBlockKnown('heisenberg/beta'));

        // An EDIT must refresh the cached contents. (The override changes the file
        // size, so the fingerprint flips even when both writes share an mtime second.)
        $this->writeContract('alpha', ['keywords' => ['alpha', 'edited-after-first-scan']]);
        $this->assertSame(
            ['alpha', 'edited-after-first-scan'],
            $registry->getBlock('heisenberg/alpha')['keywords']
        );
    }

    public function test_validate_path_rejects_files_outside_the_root(): void
    {
        $this->writeContract('paragraph');
        $registry = $this->registry();

        $inside = $this->root . DIRECTORY_SEPARATOR . 'paragraph' . DIRECTORY_SEPARATOR . 'paragraph.json';
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totally-elsewhere.json';
        file_put_contents($outside, 'x');

        $this->assertTrue($registry->validatePath($inside));
        $this->assertFalse($registry->validatePath($outside));
        $this->assertFalse($registry->validatePath($this->root . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'escape.json'));

        @unlink($outside);
    }

    public function test_envelope_has_documented_shape(): void
    {
        $this->writeContract('paragraph');
        $registry = $this->registry()->registry();

        foreach (['schemaVersion', 'registryHash', 'blocks', 'categories', 'icons', 'generatedAt', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $registry);
        }
        $this->assertArrayNotHasKey('iconUrlTemplate', $registry, 'dead field (§4.12) — pointed at a route that was never registered');
        $this->assertSame(1, $registry['schemaVersion']);
    }

    public function test_derives_inspector_controls_from_supports(): void
    {
        // A block whose entire styling surface is `supports` (no attribute controls)
        // must still produce an inspector — the supports panels are derived.
        $this->writeContract('panel', [
            'attributes' => [],
            'supports' => [
                'color' => ['text' => true, 'background' => true],
                'typography' => ['fontSize' => true],
                'spacing' => ['padding' => true],
            ],
        ]);

        $controls = $this->blockControls('heisenberg/panel');

        $this->assertNotSame([], $controls, 'a supports-only block must not render an empty inspector');

        $sections = array_values(array_unique(array_column($controls, 'section')));
        $this->assertContains('color', $sections);
        $this->assertContains('typography', $sections);
        $this->assertContains('spacing', $sections);

        // A supports control binds to a path under `block.supports`, not an attribute.
        $colorText = $this->controlById($controls, 'color.text');
        $this->assertNotNull($colorText);
        $this->assertSame('supports', $colorText['source']);
        $this->assertSame('color.text', $colorText['binding']);
        $this->assertSame('select', $colorText['type']);
        $this->assertNotEmpty($colorText['options'], 'token select must offer palette options');
    }

    public function test_supports_controls_follow_attribute_controls(): void
    {
        // Attribute-derived controls (the block's own settings) come first; the
        // supports style panels follow.
        $this->writeContract('mixed', [
            'attributes' => ['label' => ['type' => 'string', 'default' => '']],
            'supports' => ['color' => ['text' => true]],
        ]);

        $controls = $this->blockControls('heisenberg/mixed');
        $ids = array_column($controls, 'id');

        $this->assertSame('label', $ids[0]);
        $this->assertContains('color.text', $ids);
        $this->assertLessThan(
            array_search('color.text', $ids, true),
            array_search('label', $ids, true),
            'attribute controls precede supports panels'
        );
    }

    public function test_attribute_control_conditions_survive_registry_derivation(): void
    {
        $this->writeContract('conditional', [
            'attributes' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'label' => [
                    'type' => 'string',
                    'default' => '',
                    'control' => [
                        'showWhen' => ['attribute' => 'enabled', 'equals' => true],
                        'disableWhen' => ['attribute' => 'label', 'in' => ['', 'locked']],
                    ],
                ],
            ],
        ]);

        $control = $this->controlById($this->blockControls('heisenberg/conditional'), 'label');

        $this->assertSame(['attribute' => 'enabled', 'equals' => true], $control['showWhen'] ?? null);
        $this->assertSame(['attribute' => 'label', 'in' => ['', 'locked']], $control['disableWhen'] ?? null);
    }

    private function blockControls(string $name): array
    {
        foreach ($this->registry()->registry()['blocks'] as $block) {
            if (($block['name'] ?? null) === $name) {
                return $block['controls'] ?? [];
            }
        }

        return [];
    }

    private function controlById(array $controls, string $id): ?array
    {
        foreach ($controls as $control) {
            if (($control['id'] ?? null) === $id) {
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
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
