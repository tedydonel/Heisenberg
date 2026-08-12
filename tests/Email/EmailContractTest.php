<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;

/**
 * docs/email-system.md §4: the optional top-level `email` contract section (validated by the
 * SAME template-node rules as `render.template`) and `BlockRegistryService::contractsFor()`
 * surface filtering. Uses a minimal hand-built contract (not the shipped ones — those are
 * covered end to end by tests/M1/ShippedContractsTest.php and EmailRendererTest below).
 */
class EmailContractTest extends TestCase
{
    private function validator(): BlockContractValidator
    {
        return new BlockContractValidator('heisenberg');
    }

    private function baseContract(array $overrides = []): array
    {
        return array_merge([
            '$schema' => './schema.md',
            'apiVersion' => 1,
            'name' => 'heisenberg/paragraph',
            'title' => 'Paragraph',
            'category' => 'text',
            'icon' => 'pilcrow',
            'description' => 'A paragraph',
            'keywords' => ['paragraph'],
            'version' => '1.0.0',
            'attributes' => [
                'content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block'],
            ],
            'supports' => [],
            'style' => ['className' => 'hb-block-paragraph'],
            'render' => [
                'template' => [
                    'tag' => 'p',
                    'children' => [
                        ['type' => 'rich-text', 'attribute' => 'content'],
                    ],
                ],
                'publicPartial' => 'blocks.paragraph',
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'inline-basic', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ], $overrides);
    }

    public function test_a_contract_with_no_email_section_is_valid(): void
    {
        $result = $this->validator()->validate($this->baseContract());

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
    }

    public function test_a_well_formed_email_section_is_accepted(): void
    {
        $contract = $this->baseContract([
            'email' => [
                'template' => [
                    'tag' => 'table',
                    'children' => [
                        ['tag' => 'tr', 'children' => [
                            ['tag' => 'td', 'children' => [
                                ['type' => 'rich-text', 'attribute' => 'content'],
                            ]],
                        ]],
                    ],
                ],
            ],
        ]);

        $result = $this->validator()->validate($contract);

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
    }

    public function test_email_must_be_an_object(): void
    {
        $contract = $this->baseContract(['email' => 'nope']);

        $result = $this->validator()->validate($contract);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('email must be an object', implode(' | ', $result['errors']));
    }

    public function test_email_template_is_required(): void
    {
        $contract = $this->baseContract(['email' => []]);

        $result = $this->validator()->validate($contract);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('email.template', implode(' | ', $result['errors']));
    }

    public function test_email_template_is_validated_by_the_same_node_rules_as_render_template(): void
    {
        $contract = $this->baseContract([
            'email' => [
                'template' => [
                    'type' => 'not-a-real-node-type',
                ],
            ],
        ]);

        $result = $this->validator()->validate($contract);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unknown type', implode(' | ', $result['errors']));
    }

    public function test_registry_contracts_for_email_returns_exactly_the_ten_email_safe_blocks(): void
    {
        // No explicit root -> the package default (resources/blocks), the real shipped set.
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));

        $names = array_map(
            static fn (array $c): string => $c['name'],
            $registry->contractsFor('email')
        );
        sort($names);

        $this->assertSame([
            'heisenberg/button',
            'heisenberg/column',
            'heisenberg/columns',
            'heisenberg/group',
            'heisenberg/heading',
            'heisenberg/image',
            'heisenberg/list',
            'heisenberg/paragraph',
            'heisenberg/quote',
            'heisenberg/separator',
        ], $names);

        $this->assertNotContains('heisenberg/embed', $names);
        $this->assertNotContains('heisenberg/icon', $names);
    }

    public function test_registry_contracts_for_email_are_localized_like_the_web_registry(): void
    {
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));

        $paragraph = collect($registry->contractsFor('email'))->firstWhere('name', 'heisenberg/paragraph');

        $this->assertIsArray($paragraph);
        $this->assertArrayHasKey('controls', $paragraph);
        $this->assertArrayHasKey('panels', $paragraph);
    }

    public function test_registry_contracts_for_an_unknown_surface_returns_nothing(): void
    {
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));

        $this->assertSame([], $registry->contractsFor('sms'));
    }
}
