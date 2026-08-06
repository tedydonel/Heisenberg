<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M1;

use Heisenberg\Services\BlockContractValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validates contract *definitions* (not block instances). Pure/stateless, zero
 * host couplings — the only Heisenberg change vs GTC is the configurable block
 * prefix in the `name` regex (§3.5). Asserts the load-bearing rules from the
 * blueprint test matrix (Appendix F).
 */
class BlockContractValidatorTest extends TestCase
{
    private function validator(string $prefix = 'heisenberg'): BlockContractValidator
    {
        return new BlockContractValidator($prefix);
    }

    /** A complete, well-formed contract that must pass every validator. */
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
            'keywords' => ['paragraph', 'text'],
            'version' => '1.0.0',
            'attributes' => [
                'content' => [
                    'type' => 'rich-text',
                    'default' => '',
                    'sanitize' => 'rich-text-block',
                ],
            ],
            'supports' => [
                'align' => ['left', 'center', 'right'],
                'color' => ['text' => true, 'background' => true, 'custom' => false],
                'spacing' => ['margin' => true, 'padding' => true],
            ],
            'style' => [
                'css' => './paragraph.css',
                'className' => 'hb-block-paragraph',
                'variables' => [
                    '--hb-paragraph-color' => ['source' => 'supports.color.text', 'default' => 'var(--accent-1)', 'sanitize' => 'color-token'],
                ],
            ],
            'render' => [
                'template' => [
                    'tag' => 'p',
                    'class' => 'hb-block hb-block-paragraph',
                    'attributes' => ['data-block-name' => '{{name}}', 'data-block-id' => '{{id}}'],
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

    private function assertValid(array $contract, string $message = ''): void
    {
        $result = $this->validator()->validate($contract);
        $this->assertTrue($result['valid'], $message . ' :: ' . implode(' | ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    private function assertInvalid(array $contract, ?string $needle = null): void
    {
        $result = $this->validator()->validate($contract);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        if ($needle !== null) {
            $joined = strtolower(implode(' | ', $result['errors']));
            $this->assertStringContainsString(strtolower($needle), $joined);
        }
    }

    public function test_a_well_formed_contract_is_valid(): void
    {
        $this->assertValid($this->validContract());
    }

    public function test_block_prefix_is_configurable(): void
    {
        $contract = $this->validContract();
        $contract['name'] = 'gtc/paragraph';

        // Default (heisenberg) prefix rejects a gtc/ name...
        $this->assertFalse($this->validator('heisenberg')->validate($contract)['valid']);
        // ...but a validator configured for the gtc prefix accepts it.
        $this->assertTrue($this->validator('gtc')->validate($contract)['valid']);
    }

    public function test_each_required_top_level_key_is_mandatory(): void
    {
        $keys = [
            '$schema', 'apiVersion', 'name', 'title', 'category', 'icon',
            'description', 'keywords', 'version', 'attributes', 'supports',
            'style', 'render', 'innerBlocks', 'serialization', 'security',
        ];
        $this->assertCount(16, $keys);

        foreach ($keys as $key) {
            $contract = $this->validContract();
            unset($contract[$key]);
            $this->assertInvalid($contract, $key);
        }
    }

    public function test_api_version_must_be_integer_one(): void
    {
        foreach ([2, '1', 1.0, true] as $bad) {
            $contract = $this->validContract();
            $contract['apiVersion'] = $bad;
            $this->assertInvalid($contract, 'apiVersion');
        }
    }

    public function test_name_must_match_prefixed_slug(): void
    {
        foreach (['paragraph', 'heisenberg/Paragraph', 'heisenberg/', 'other/paragraph', 'heisenberg/-bad'] as $bad) {
            $contract = $this->validContract();
            $contract['name'] = $bad;
            $this->assertInvalid($contract, 'name');
        }
    }

    public function test_version_must_be_semver(): void
    {
        foreach (['1.0', '1', 'v1.0.0', '1.0.0.0', '1.0.x'] as $bad) {
            $contract = $this->validContract();
            $contract['version'] = $bad;
            $this->assertInvalid($contract, 'version');
        }
    }

    public function test_unknown_attribute_type_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['type'] = 'frobnicate';
        $this->assertInvalid($contract, 'type');
    }

    public function test_attribute_sanitizer_must_be_known(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['sanitize'] = 'make-it-safe-trust-me';
        $this->assertInvalid($contract, 'sanitize');
    }

    public function test_attribute_enum_must_be_an_array(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['level'] = ['type' => 'integer', 'default' => 2, 'enum' => 'nope'];
        $this->assertInvalid($contract, 'enum');
    }

    public function test_unknown_support_group_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['supports']['telekinesis'] = true;
        $this->assertInvalid($contract);
    }

    public function test_align_support_values_are_constrained(): void
    {
        $contract = $this->validContract();
        $contract['supports']['align'] = ['left', 'diagonal'];
        $this->assertInvalid($contract, 'align');
    }

    // ── Phase G: supports interior-shape validation ───────────

    public function test_unknown_support_feature_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['supports']['typography'] = ['fontsize' => true]; // typo: fontSize
        $this->assertInvalid($contract, "unknown feature 'fontsize'");
    }

    public function test_support_feature_flags_must_be_booleans(): void
    {
        $contract = $this->validContract();
        $contract['supports']['color'] = ['text' => 'yes'];
        $this->assertInvalid($contract, 'supports.color.text');
    }

    public function test_support_group_must_be_an_object_not_a_bare_flag(): void
    {
        $contract = $this->validContract();
        $contract['supports']['color'] = true; // engine derives nothing from a bare true
        $this->assertInvalid($contract, 'supports.color');
    }

    public function test_spacing_features_accept_bool_or_side_maps(): void
    {
        $contract = $this->validContract();
        $contract['supports']['spacing'] = [
            'margin' => ['top' => true, 'bottom' => true],
            'padding' => true,
        ];
        $this->assertValid($contract, 'side-map margin + bool padding');

        $contract['supports']['spacing'] = ['padding' => ['diagonal' => true]];
        $this->assertInvalid($contract, 'supports.spacing.padding');
    }

    public function test_border_radius_accepts_bool_or_corner_map(): void
    {
        $contract = $this->validContract();
        $contract['supports']['border'] = ['radius' => ['topLeft' => true, 'bottomRight' => true]];
        $this->assertValid($contract, 'corner-map radius');

        $contract['supports']['border'] = ['radius' => ['top' => true]]; // side, not corner
        $this->assertInvalid($contract, 'supports.border.radius');
    }

    public function test_size_fill_and_hug_require_axis_maps(): void
    {
        $contract = $this->validContract();
        $contract['supports']['size'] = ['fill' => ['width' => true], 'hug' => ['height' => true]];
        $this->assertValid($contract, 'axis maps');

        $contract['supports']['size'] = ['fill' => true]; // engine only consumes the axis-map form
        $this->assertInvalid($contract, 'supports.size.fill');
    }

    public function test_animation_support_must_be_a_boolean(): void
    {
        $contract = $this->validContract();
        $contract['supports']['animation'] = true;
        $this->assertValid($contract, 'animation flag');

        $contract['supports']['animation'] = ['enabled' => true];
        $this->assertInvalid($contract, 'supports.animation');
    }

    public function test_states_flags_must_be_booleans(): void
    {
        $contract = $this->validContract();
        $contract['supports']['states'] = ['hover' => 'yes'];
        $this->assertInvalid($contract, 'supports.states.hover');
    }

    public function test_control_override_type_must_be_known(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = ['type' => 'hologram'];
        $this->assertInvalid($contract, 'control');
    }

    public function test_style_variable_source_must_reference_attributes_or_supports(): void
    {
        $contract = $this->validContract();
        $contract['style']['variables']['--hb-paragraph-color']['source'] = 'window.location';
        $this->assertInvalid($contract, 'source');
    }

    public function test_style_variable_default_must_not_be_raw_hex(): void
    {
        $contract = $this->validContract();
        $contract['style']['variables']['--hb-paragraph-color']['default'] = '#ff0000';
        $this->assertInvalid($contract, 'hex');
    }

    public function test_asset_paths_reject_traversal_absolute_and_wrong_extension(): void
    {
        foreach (['../evil.css', '/abs.css', '//cdn/x.css', './x.txt'] as $badCss) {
            $contract = $this->validContract();
            $contract['style']['css'] = $badCss;
            $this->assertInvalid($contract);
        }

        foreach (['../evil.js', '/abs.js', './x.exe'] as $badScript) {
            $contract = $this->validContract();
            $contract['render']['script'] = $badScript;
            $this->assertInvalid($contract);
        }
    }

    public function test_serialization_mode_must_be_json(): void
    {
        $contract = $this->validContract();
        $contract['serialization']['mode'] = 'xml';
        $this->assertInvalid($contract, 'json');
    }

    public function test_security_allow_custom_css_must_be_false(): void
    {
        $contract = $this->validContract();
        $contract['security']['allowCustomCss'] = true;
        $this->assertInvalid($contract, 'allowCustomCss');
    }

    public function test_render_requires_a_template(): void
    {
        $contract = $this->validContract();
        unset($contract['render']['template']);
        $this->assertInvalid($contract, 'template');
    }

    public function test_control_override_options_must_be_an_array(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = ['type' => 'select', 'options' => 'nope'];
        $this->assertInvalid($contract, 'options');
    }

    // ── Phase 0: contract-schema additions ────────────────────

    public function test_inner_blocks_allowed_blocks_accepts_wildcard_or_string_array(): void
    {
        $contract = $this->validContract();

        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => '*'];
        $this->assertValid($contract, 'wildcard allowedBlocks');

        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => ['heisenberg/paragraph']];
        $this->assertValid($contract, 'array allowedBlocks');

        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => 123];
        $this->assertInvalid($contract, 'allowedBlocks');
    }

    public function test_inner_blocks_orientation_is_constrained(): void
    {
        $contract = $this->validContract();
        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => '*', 'orientation' => 'horizontal'];
        $this->assertValid($contract, 'horizontal orientation');

        $contract['innerBlocks']['orientation'] = 'diagonal';
        $this->assertInvalid($contract, 'orientation');
    }

    public function test_inner_blocks_appender_is_constrained(): void
    {
        $contract = $this->validContract();
        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => '*', 'appender' => false];
        $this->assertValid($contract, 'appender false');

        $contract['innerBlocks']['appender'] = 'button';
        $this->assertValid($contract, 'appender button');

        $contract['innerBlocks']['appender'] = 'magic';
        $this->assertInvalid($contract, 'appender');
    }

    public function test_inner_blocks_parent_must_be_a_string_array(): void
    {
        $contract = $this->validContract();
        $contract['innerBlocks'] = ['enabled' => false, 'parent' => ['heisenberg/columns']];
        $this->assertValid($contract, 'parent array');

        $contract['innerBlocks'] = ['enabled' => false, 'parent' => 'heisenberg/columns'];
        $this->assertInvalid($contract, 'parent');
    }

    public function test_inner_blocks_template_must_be_a_list_of_entries(): void
    {
        $contract = $this->validContract();
        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => '*', 'template' => [['heisenberg/paragraph']]];
        $this->assertValid($contract, 'template entries');

        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => '*', 'template' => 'nope'];
        $this->assertInvalid($contract, 'template');
    }

    public function test_template_node_type_is_constrained(): void
    {
        $contract = $this->validContract();
        $contract['render']['template']['children'][] = ['type' => 'wormhole'];
        $this->assertInvalid($contract, 'type');
    }

    public function test_inner_blocks_template_node_is_accepted(): void
    {
        $contract = $this->validContract();
        $contract['innerBlocks'] = ['enabled' => true, 'allowedBlocks' => '*'];
        $contract['render']['template']['children'][] = ['type' => 'inner-blocks'];
        $this->assertValid($contract, 'inner-blocks node');
    }

    public function test_rich_text_template_node_requires_an_attribute(): void
    {
        $contract = $this->validContract();
        $contract['render']['template']['children'] = [['type' => 'rich-text', 'class' => 'x']];
        $this->assertInvalid($contract, 'rich-text');
    }

    public function test_control_accepts_show_when_equals_predicate(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = [
            'type' => 'rich-text',
            'showWhen' => ['attribute' => 'content', 'equals' => 'visible'],
        ];

        $this->assertValid($contract, 'showWhen equals predicate');
    }

    public function test_control_accepts_disable_when_in_predicate(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = [
            'type' => 'rich-text',
            'disableWhen' => ['attribute' => 'content', 'in' => ['', 'locked']],
        ];

        $this->assertValid($contract, 'disableWhen in predicate');
    }

    public function test_control_predicate_rejects_undeclared_attribute(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = [
            'showWhen' => ['attribute' => 'missing', 'equals' => true],
        ];

        $this->assertInvalid($contract, 'showWhen');
    }

    public function test_control_predicate_rejects_equals_and_in_together(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = [
            'disableWhen' => ['attribute' => 'content', 'equals' => '', 'in' => ['', 'locked']],
        ];

        $this->assertInvalid($contract, 'disableWhen');
    }

    public function test_control_predicate_requires_equals_or_in(): void
    {
        $contract = $this->validContract();
        $contract['attributes']['content']['control'] = [
            'forceWhen' => ['attribute' => 'content'],
        ];

        $this->assertInvalid($contract, 'forceWhen');
    }
}
