<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Templates;

use Heisenberg\Services\PostTemplateContractValidator;
use Heisenberg\Services\PostTemplateRegistryService;
use Heisenberg\Tests\TestCase;

/**
 * Integration guard for the shipped `heisenberg/article` reference template
 * under resources/templates — mirrors
 * {@see \Heisenberg\Tests\M1\ShippedContractsTest} for post templates
 * (docs/post-template-schema.md "Worked example").
 */
class ReferenceTemplateTest extends TestCase
{
    private function registry(): PostTemplateRegistryService
    {
        // No explicit root -> the package default (resources/templates).
        return new PostTemplateRegistryService(new PostTemplateContractValidator('heisenberg'));
    }

    public function test_the_reference_template_is_discovered_and_valid(): void
    {
        $registry = $this->registry()->registry();

        $this->assertSame([], $registry['errors'], 'the shipped reference template must be valid');

        $names = array_map(static fn (array $t): string => $t['name'], $registry['templates']);
        $this->assertSame(['heisenberg/article'], $names);
    }

    public function test_the_reference_template_is_individually_valid(): void
    {
        $validator = new PostTemplateContractValidator('heisenberg');

        foreach ($this->registry()->discover()['templates'] as $contract) {
            $bare = $contract;
            unset($bare['_absolutePath'], $bare['_relativePath']);
            $result = $validator->validate($bare);
            $this->assertTrue($result['valid'], "{$contract['name']}: " . implode(' | ', $result['errors']));
        }
    }

    public function test_the_reference_template_exercises_every_capability(): void
    {
        $article = $this->registry()->getTemplate('heisenberg/article');
        $this->assertIsArray($article);

        $expected = [
            'tableOfContents', 'featuredImage', 'readingTime', 'authorBox',
            'shareButtons', 'breadcrumbs', 'pagination',
            'postViews', 'comments', 'relatedPosts', 'seoMeta',
        ];
        $this->assertSame($expected, array_keys($article['capabilities']));

        // seoMeta is the one capability shipped off — no backing data source exists yet.
        $this->assertFalse($article['capabilities']['seoMeta']['enabled']);
        foreach ($expected as $key) {
            if ($key === 'seoMeta') {
                continue;
            }
            $this->assertTrue($article['capabilities'][$key]['enabled'], "{$key} should be enabled in the worked example");
        }
    }

    public function test_registry_hash_is_present_and_stable(): void
    {
        $a = $this->registry()->registry('en')['registryHash'];
        $b = $this->registry()->registry('fr')['registryHash'];

        $this->assertStringStartsWith('sha256:', $a);
        $this->assertSame($a, $b);
    }

    public function test_labels_localize_via_the_templates_lang_namespace(): void
    {
        $registry = $this->registry()->registry('fr');
        $article = collect($registry['templates'])->firstWhere('name', 'heisenberg/article');

        $this->assertSame('Article', $article['title']);
        $this->assertSame('Sur cette page', $article['capabilities']['tableOfContents']['title']);
        $this->assertSame('Accueil', $article['capabilities']['breadcrumbs']['homeLabel']);
        $this->assertSame('Vues', $article['capabilities']['postViews']['label']);
    }

    public function test_this_registry_hash_is_independent_of_the_block_registry(): void
    {
        // A separate registry with its own hash namespace — never touches
        // BlockRegistryService's scan/hash (docs/post-template-schema.md
        // "Versioning and compatibility").
        $templateHash = $this->registry()->registry()['registryHash'];
        $blockHash = (new \Heisenberg\Services\BlockRegistryService(new \Heisenberg\Services\BlockContractValidator('heisenberg')))->registry()['registryHash'];

        $this->assertNotSame($templateHash, $blockHash);
    }
}
