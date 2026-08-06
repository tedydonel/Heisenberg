<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Tests\TestCase;

/**
 * Builder full-kit overhaul (Phase 1) — contract-time recognition of the new
 * sanitizer kinds (opacity/angle/length-signed/shadow/text-align/align-3/
 * position-mode/flex-direction/flex-justify/flex-align/overflow) and the new
 * `supports` groups (appearance/position/effects; `layout` already existed).
 * This is the VALIDATOR side of the lockstep — {@see SupportsCapabilityFixtureTest}
 * covers the matching BlockRenderer::cssValueValid() regex/allow-list side.
 */
class SupportsSanitizerValidatorTest extends TestCase
{
    private const NEW_SANITIZER_KINDS = [
        'opacity', 'angle', 'length-signed', 'shadow',
        'text-align', 'align-3', 'position-mode',
        'flex-direction', 'flex-justify', 'flex-align', 'overflow',
    ];

    /** Each new group with a REAL feature — the validator now checks interiors too. */
    private const NEW_SUPPORT_GROUPS = [
        'appearance' => ['opacity' => true],
        'position' => ['mode' => true, 'x' => true, 'y' => true, 'rotation' => true],
        'effects' => ['shadow' => true],
    ];

    private function validator(): BlockContractValidator
    {
        return new BlockContractValidator('heisenberg');
    }

    private function validContract(): array
    {
        return [
            '$schema' => './schema.md',
            'apiVersion' => 1,
            'name' => 'heisenberg/paragraph',
            'title' => 'heisenberg::blocks.paragraph.title',
            'category' => 'text',
            'icon' => 'pilcrow',
            'description' => 'heisenberg::blocks.paragraph.description',
            'keywords' => ['paragraph'],
            'version' => '1.0.0',
            'attributes' => [
                'content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block'],
            ],
            'supports' => [],
            'style' => [
                'css' => './paragraph.css',
                'className' => 'hb-block-paragraph',
                'variables' => [],
            ],
            'render' => [
                'template' => [
                    'tag' => 'p',
                    'class' => 'hb-block hb-block-paragraph',
                    'children' => [
                        ['type' => 'rich-text', 'attribute' => 'content', 'class' => 'hb-block-paragraph__text'],
                    ],
                ],
                'publicPartial' => 'blocks.paragraph',
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'inline-basic', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];
    }

    public function test_every_new_sanitizer_kind_is_accepted_as_a_style_variable_sanitizer(): void
    {
        foreach (self::NEW_SANITIZER_KINDS as $kind) {
            $contract = $this->validContract();
            $contract['style']['variables']['--hb-test'] = [
                'source' => 'supports.appearance.opacity',
                'default' => '',
                'sanitize' => $kind,
            ];

            $result = $this->validator()->validate($contract);

            $this->assertTrue($result['valid'], "kind '{$kind}' should be a recognized sanitizer :: " . implode(' | ', $result['errors']));
        }
    }

    public function test_every_new_sanitizer_kind_is_accepted_as_an_attribute_sanitizer(): void
    {
        foreach (self::NEW_SANITIZER_KINDS as $kind) {
            $contract = $this->validContract();
            $contract['attributes']['probe'] = ['type' => 'token', 'default' => '', 'sanitize' => $kind];

            $result = $this->validator()->validate($contract);

            $this->assertTrue($result['valid'], "kind '{$kind}' should be accepted on a token attribute :: " . implode(' | ', $result['errors']));
        }
    }

    public function test_unknown_sanitizer_kind_is_still_rejected(): void
    {
        $contract = $this->validContract();
        $contract['style']['variables']['--hb-test'] = [
            'source' => 'supports.appearance.opacity',
            'default' => '',
            'sanitize' => 'definitely-not-a-real-kind',
        ];

        $result = $this->validator()->validate($contract);

        $this->assertFalse($result['valid']);
    }

    public function test_every_new_support_group_is_recognized(): void
    {
        foreach (self::NEW_SUPPORT_GROUPS as $group => $features) {
            $contract = $this->validContract();
            $contract['supports'] = [$group => $features];

            $result = $this->validator()->validate($contract);

            $this->assertTrue($result['valid'], "support group '{$group}' should be recognized :: " . implode(' | ', $result['errors']));
        }
    }

    public function test_layout_support_group_is_recognized(): void
    {
        // `layout` was already an allowed key but was a dead branch end-to-end;
        // Phase 1 implements it — confirm it still validates.
        $contract = $this->validContract();
        $contract['supports'] = ['layout' => ['direction' => true, 'justify' => true, 'align' => true, 'gap' => true, 'padding' => true]];

        $result = $this->validator()->validate($contract);

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
    }

    public function test_still_unknown_support_group_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['supports'] = ['telekinesis' => ['levitate' => true]];

        $result = $this->validator()->validate($contract);

        $this->assertFalse($result['valid']);
    }
}
