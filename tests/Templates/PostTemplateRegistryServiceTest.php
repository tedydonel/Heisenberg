<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Templates;

use Heisenberg\Services\PostTemplateContractValidator;
use Heisenberg\Services\PostTemplateRegistryService;
use Heisenberg\Tests\TestCase;

/**
 * The post-template registry discovers contract JSON on disk, validates each,
 * and serves a hashed, localized envelope — mirroring
 * {@see \Heisenberg\Tests\M1\BlockRegistryServiceTest} for
 * {@see PostTemplateRegistryService}. Driven against a temp fixture directory
 * so this is isolated from the shipped `resources/templates` contracts (see
 * ReferenceTemplateTest for those).
 */
class PostTemplateRegistryServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-templates-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    private function registry(): PostTemplateRegistryService
    {
        return new PostTemplateRegistryService(new PostTemplateContractValidator('heisenberg'), $this->root);
    }

    /** Write a valid contract to <root>/<slug>/<slug>.json, with overrides. */
    private function writeContract(string $slug, array $overrides = []): void
    {
        $contract = array_replace_recursive($this->baseContract($slug), $overrides);
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
            'title' => "heisenberg::templates.{$slug}.title",
            'category' => 'post',
            'icon' => 'newspaper',
            'description' => "heisenberg::templates.{$slug}.description",
            'keywords' => [$slug],
            'version' => '1.0.0',
            'render' => ['view' => "theme::posts.{$slug}", 'script' => null],
            'capabilities' => [
                'readingTime' => ['enabled' => true, 'wordsPerMinute' => 200],
            ],
        ];
    }

    public function test_discovers_valid_contracts_sorted_by_name(): void
    {
        $this->writeContract('article', ['icon' => 'newspaper']);
        $this->writeContract('landing', ['icon' => 'layout-template']);

        $registry = $this->registry()->registry();

        $names = array_map(static fn (array $t): string => $t['name'], $registry['templates']);
        $this->assertSame(['heisenberg/article', 'heisenberg/landing'], $names);
        $this->assertSame([], $registry['errors']);
    }

    public function test_invalid_contract_is_excluded_and_reported(): void
    {
        $this->writeContract('article');
        $this->writeContract('broken', ['name' => 'not-a-valid-name']);

        $registry = $this->registry()->registry();

        $names = array_map(static fn (array $t): string => $t['name'], $registry['templates']);
        $this->assertSame(['heisenberg/article'], $names);
        $this->assertNotEmpty($registry['errors']);
    }

    public function test_unknown_capability_is_excluded_and_reported(): void
    {
        $this->writeContract('article');
        $this->writeContract('broken', ['capabilities' => ['telekinesis' => ['enabled' => true]]]);

        $registry = $this->registry()->registry();

        $names = array_map(static fn (array $t): string => $t['name'], $registry['templates']);
        $this->assertSame(['heisenberg/article'], $names);
        $joined = strtolower(implode(' | ', array_column($registry['errors'], 'error')));
        $this->assertStringContainsString('telekinesis', $joined);
    }

    public function test_malformed_json_is_reported_not_thrown(): void
    {
        $this->writeContract('article');
        $this->writeRaw('broken/broken.json', '{ this is not json ');

        $registry = $this->registry()->registry();

        $this->assertNotEmpty($registry['errors']);
        $this->assertCount(1, $registry['templates']);
    }

    public function test_duplicate_name_keeps_first_and_reports(): void
    {
        $this->writeContract('article');
        $this->writeRaw(
            'dupe/dupe.json',
            json_encode(array_replace_recursive($this->baseContract('article'), ['name' => 'heisenberg/article']))
        );

        $registry = $this->registry()->registry();

        $this->assertCount(1, $registry['templates']);
        $this->assertNotEmpty($registry['errors']);
    }

    public function test_registry_hash_is_sha256_prefixed_and_locale_stable(): void
    {
        $this->writeContract('article');
        $this->writeContract('landing');

        $en = $this->registry()->registry('en');
        $fr = $this->registry()->registry('fr');

        $this->assertStringStartsWith('sha256:', $en['registryHash']);
        $this->assertSame($en['registryHash'], $fr['registryHash'], 'hash must not change with locale');
    }

    public function test_localizes_namespaced_labels_without_changing_the_hash(): void
    {
        $this->writeContract('article');
        app('translator')->addLines(['templates.article.title' => 'Article FR'], 'fr', 'heisenberg');

        $bare = $this->registry()->registry('en');
        $localized = $this->registry()->registry('fr');

        $this->assertSame('Article FR', $localized['templates'][0]['title']);
        $this->assertSame($bare['registryHash'], $localized['registryHash']);
    }

    public function test_categories_are_sorted_and_distinct(): void
    {
        $this->writeContract('article', ['category' => 'post']);
        $this->writeContract('landing', ['category' => 'page']);

        $this->assertSame(['page', 'post'], $this->registry()->registry()['categories']);
    }

    public function test_get_template_and_is_template_known(): void
    {
        $this->writeContract('article');

        $registry = $this->registry();
        $this->assertTrue($registry->isTemplateKnown('heisenberg/article'));
        $this->assertFalse($registry->isTemplateKnown('heisenberg/nope'));
        $this->assertSame('heisenberg/article', $registry->getTemplate('heisenberg/article')['name']);
        $this->assertNull($registry->getTemplate('heisenberg/nope'));
    }

    public function test_validate_path_rejects_files_outside_the_root(): void
    {
        $this->writeContract('article');
        $registry = $this->registry();

        $inside = $this->root . DIRECTORY_SEPARATOR . 'article' . DIRECTORY_SEPARATOR . 'article.json';
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totally-elsewhere.json';
        file_put_contents($outside, 'x');

        $this->assertTrue($registry->validatePath($inside));
        $this->assertFalse($registry->validatePath($outside));
        $this->assertFalse($registry->validatePath($this->root . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'escape.json'));

        @unlink($outside);
    }

    public function test_envelope_has_documented_shape(): void
    {
        $this->writeContract('article');
        $registry = $this->registry()->registry();

        foreach (['schemaVersion', 'registryHash', 'templates', 'categories', 'icons', 'generatedAt', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $registry);
        }
        $this->assertSame(1, $registry['schemaVersion']);
    }

    public function test_registry_is_cached_per_instance(): void
    {
        $this->writeContract('article');
        $registry = $this->registry();

        $first = $registry->discover();
        // Write a second contract AFTER the first scan — the cached instance
        // must not pick it up (same per-instance cache contract as
        // BlockRegistryService::scan()).
        $this->writeContract('landing');
        $second = $registry->discover();

        $this->assertCount(1, $first['templates']);
        $this->assertCount(1, $second['templates']);

        // A fresh instance sees both.
        $this->assertCount(2, $this->registry()->discover()['templates']);
    }

    public function test_hash_changes_when_a_contract_changes(): void
    {
        $this->writeContract('article', ['version' => '1.0.0']);
        $first = $this->registry()->computeHash();

        $this->writeContract('article', ['version' => '1.1.0']);
        $second = $this->registry()->computeHash();

        $this->assertNotSame($first, $second);
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
