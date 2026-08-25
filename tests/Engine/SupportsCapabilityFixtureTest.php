<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;

/**
 * Builder full-kit overhaul (Phase 1) — the RENDER side of the new sanitizer
 * kinds (lockstep with {@see SupportsSanitizerValidatorTest}, which covers
 * BlockContractValidator). A single fixture contract (`heisenberg/supports-fixture`)
 * opts into every new `supports.*` group AND carries one plain attribute per
 * new kind (for isolated valid/invalid/XSS probing), matching
 * BlockRenderer::cssValueValid()'s new cases + isSafeShadowValue().
 */
class SupportsCapabilityFixtureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-supports-fixture-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->writeFixtureContract();
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

    private function block(array $attributes = [], array $supports = []): array
    {
        return [
            'id' => 'fx1',
            'name' => 'heisenberg/supports-fixture',
            'attributes' => $attributes,
            'supports' => $supports,
            'innerBlocks' => [],
        ];
    }

    public function test_fixture_opting_into_every_new_support_renders_the_expected_inline_vars(): void
    {
        $html = $this->renderer()->renderBlock($this->block([], [
            'appearance' => ['opacity' => '0.5'],
            'position' => ['x' => '10px', 'y' => '-5px', 'rotation' => '45deg', 'mode' => 'relative'],
            'layout' => ['direction' => 'column', 'justify' => 'center', 'align' => 'stretch', 'gap' => '12px', 'padding' => '8px'],
            'typography' => ['letterSpacing' => '0.05em', 'textAlign' => 'center', 'textAlignVertical' => 'end'],
            'size' => ['clip' => 'hidden'],
            'border' => ['width' => ['top' => '2px'], 'color' => ['top' => 'var(--ink)'], 'style' => ['top' => 'dashed']],
            'effects' => ['shadow' => '0px 2px 8px var(--ink)'],
        ]), 'en');

        foreach ([
            '--hb-opacity: 0.5',
            '--hb-tx: 10px',
            '--hb-ty: -5px',
            '--hb-rotate: 45deg',
            '--hb-position-mode: relative',
            '--hb-flex-direction: column',
            '--hb-flex-justify: center',
            '--hb-flex-align: stretch',
            '--hb-flex-gap: 12px',
            '--hb-flex-padding: 8px',
            '--hb-letter-spacing: 0.05em',
            '--hb-text-align: center',
            '--hb-text-align-v: end',
            '--hb-overflow: hidden',
            '--hb-border-top-width: 2px',
            '--hb-border-top-color: var(--ink)',
            '--hb-border-top-style: dashed',
            '--hb-shadow: 0px 2px 8px var(--ink)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing expected declaration: {$needle}");
        }

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('expression(', $html);
    }

    public function test_unsafe_supports_values_never_reach_the_markup_and_defaults_apply(): void
    {
        $html = $this->renderer()->renderBlock($this->block([], [
            'appearance' => ['opacity' => '1; } body { background: url(javascript:alert(1))'],
            'position' => ['x' => '10px; } * { color: red', 'rotation' => '45deg" onload="alert(1)'],
            'layout' => ['direction' => 'diagonal'],
            'typography' => ['textAlign' => 'javascript:alert(1)'],
            'effects' => ['shadow' => '0 0 4px url(javascript:alert(1))'],
        ]), 'en');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onload=', $html);
        $this->assertStringNotContainsString('diagonal', $html);
        $this->assertStringNotContainsString('background: url', $html);

        // Every rejected value falls back to ITS OWN contract-declared default.
        $this->assertStringContainsString('--hb-opacity: 1', $html);
        $this->assertStringContainsString('--hb-tx: 0', $html);
        $this->assertStringContainsString('--hb-rotate: 0deg', $html);
        $this->assertStringContainsString('--hb-position-mode: static', $html);
        $this->assertStringContainsString('--hb-flex-direction: row', $html);
        $this->assertStringContainsString('--hb-text-align: left', $html);
        $this->assertStringContainsString('--hb-shadow: none', $html);
    }

    public function test_fixture_declaring_border_sides_and_radius_renders_every_custom_property(): void
    {
        // The corner half is new (2026-08-07). SupportsStyle had NO radius backing at all, so
        // Appearance's four corner fields wrote supports.border.radius.* and no stylesheet ever
        // read it. The four --hb-border-radius-* vars are what SupportsStyle now consumes.
        $html = $this->renderer()->renderBlock($this->block([], [
            'border' => [
                'width' => ['top' => '2px', 'right' => '3px', 'bottom' => '4px', 'left' => '5px'],
                'color' => ['top' => 'var(--ink)'],
                'style' => ['top' => 'dashed'],
                'radius' => ['topLeft' => '8px', 'topRight' => '0', 'bottomRight' => '2rem', 'bottomLeft' => '50%'],
            ],
        ]), 'en');

        foreach ([
            '--hb-border-top-width: 2px',
            '--hb-border-right-width: 3px',
            '--hb-border-bottom-width: 4px',
            '--hb-border-left-width: 5px',
            '--hb-border-radius-tl: 8px',
            // A bare 0 resolves its implied unit, like every other length kind.
            '--hb-border-radius-tr: 0px',
            '--hb-border-radius-br: 2rem',
            '--hb-border-radius-bl: 50%',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing expected declaration: {$needle}");
        }
    }

    public function test_malformed_border_side_and_corner_values_never_reach_the_markup(): void
    {
        $html = $this->renderer()->renderBlock($this->block([], [
            'border' => [
                'width' => ['right' => '3px; } body { background: url(javascript:alert(1))'],
                'radius' => [
                    'topLeft' => 'expression(alert(1))',
                    'topRight' => '8px" onload="alert(1)',
                    'bottomRight' => 'calc(100% - 4px)',
                    'bottomLeft' => '8xyz',
                ],
            ],
        ]), 'en');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('expression(', $html);
        $this->assertStringNotContainsString('onload=', $html);
        $this->assertStringNotContainsString('calc(', $html);
        $this->assertStringNotContainsString('8xyz', $html);

        // Each rejected value falls back to ITS OWN contract-declared default, never to the
        // neighbouring corner's value and never to nothing-at-all mid-declaration.
        foreach ([
            '--hb-border-right-width: 0px',
            '--hb-border-radius-tl: 0px',
            '--hb-border-radius-tr: 0px',
            '--hb-border-radius-br: 0px',
            '--hb-border-radius-bl: 0px',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing fallback declaration: {$needle}");
        }
    }

    public function test_a_container_contract_paints_all_four_edges_from_one_stroke_colour_scalar(): void
    {
        // The shipped container contracts (group/columns/column) declare border.color and
        // border.style as SCALARS, because the inspector writes one value for the whole box:
        // Stroke's colour is a composited layer stack committing to supports.border.color, and
        // Cap writes supports.border.style. style.variables is var => {source}, so all four
        // per-side vars read that single source — which is what makes one scalar paint four
        // edges through SupportsStyle's existing per-side rule.
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));
        $renderer = new BlockRenderer($registry);

        foreach (['heisenberg/group', 'heisenberg/columns', 'heisenberg/column'] as $name) {
            $html = $renderer->renderBlock([
                'id' => 'c1', 'name' => $name, 'attributes' => [], 'innerBlocks' => [],
                'supports' => [
                    'border' => [
                        'width' => ['top' => '2px', 'right' => '2px', 'bottom' => '2px', 'left' => '2px'],
                        'style' => 'dashed',
                        'color' => '#ff0000',
                        'radius' => ['topLeft' => '8px', 'topRight' => '8px', 'bottomRight' => '8px', 'bottomLeft' => '8px'],
                    ],
                ],
            ], 'en');

            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $this->assertStringContainsString("--hb-border-{$side}-width: 2px", $html, $name);
                $this->assertStringContainsString("--hb-border-{$side}-style: dashed", $html, $name);
                $this->assertStringContainsString("--hb-border-{$side}-color: #ff0000", $html, $name);
            }
            foreach (['tl', 'tr', 'br', 'bl'] as $corner) {
                $this->assertStringContainsString("--hb-border-radius-{$corner}: 8px", $html, $name);
            }
        }
    }

    public function test_a_container_with_no_stroke_set_stays_visually_unchanged(): void
    {
        // The style vars default to `solid` so a Weight the user types actually paints — the Cap
        // select shows Solid, and an unset style would fall back to SupportsStyle's `none` and
        // silently swallow the weight. That default must stay invisible on its own: with no
        // width and no colour set, no width or colour var is emitted at all, so the capability
        // rule keeps its 0-width/transparent fallbacks.
        $renderer = new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg')));

        $html = $renderer->renderBlock([
            'id' => 'c2', 'name' => 'heisenberg/group',
            'attributes' => [], 'supports' => [], 'innerBlocks' => [],
        ], 'en');

        $this->assertStringContainsString('--hb-border-top-style: solid', $html);
        foreach (['--hb-border-top-width', '--hb-border-top-color', '--hb-border-radius-tl'] as $var) {
            $this->assertStringNotContainsString($var . ':', $html, "{$var} must stay unset until the user sets it");
        }
    }

    public function test_opacity_kind_valid_and_invalid_values(): void
    {
        foreach (['0', '1', '0.5', '.75', '50%', '100%', '0%'] as $good) {
            $html = $this->renderer()->renderBlock($this->block(['opacityAttr' => $good]), 'en');
            $this->assertStringContainsString("--hb-fix-opacity: {$good}", $html, "opacity should accept '{$good}'");
        }

        // Bare numbers above 1 resolve to percent (the 0-100 UI scale) instead of being discarded.
        foreach (['2' => '2%', '37' => '37%'] as $bare => $normalized) {
            $html = $this->renderer()->renderBlock($this->block(['opacityAttr' => (string) $bare]), 'en');
            $this->assertStringContainsString("--hb-fix-opacity: {$normalized}", $html, "bare '{$bare}' should normalize to {$normalized}");
        }

        foreach (['101%', '-0.5', 'abc', '1;}body{color:red'] as $bad) {
            $html = $this->renderer()->renderBlock($this->block(['opacityAttr' => $bad]), 'en');
            $this->assertStringNotContainsString($bad, $html, "opacity should reject '{$bad}'");
            $this->assertStringNotContainsString('--hb-fix-opacity: ' . $bad, $html);
        }
    }

    public function test_angle_kind_valid_and_invalid_values(): void
    {
        foreach (['0deg', '45deg', '-120deg', '999deg', '45.5deg'] as $good) {
            $html = $this->renderer()->renderBlock($this->block(['angleAttr' => $good]), 'en');
            $this->assertStringContainsString("--hb-fix-angle: {$good}", $html, "angle should accept '{$good}'");
        }

        // A bare number resolves its implied unit instead of being silently discarded.
        $html = $this->renderer()->renderBlock($this->block(['angleAttr' => '45']), 'en');
        $this->assertStringContainsString('--hb-fix-angle: 45deg', $html, "bare '45' should normalize to 45deg");

        foreach (['1000deg', '45rad', 'expression(alert(1))', '45deg;}body{background:red'] as $bad) {
            $html = $this->renderer()->renderBlock($this->block(['angleAttr' => $bad]), 'en');
            $this->assertStringNotContainsString($bad, $html, "angle should reject '{$bad}'");
        }
    }

    public function test_length_signed_kind_valid_and_invalid_values(): void
    {
        foreach (['0', '10px', '-10px', '2.5rem', '-2.5em', '50%', '-50%', '10vw', '10vh'] as $good) {
            $html = $this->renderer()->renderBlock($this->block(['lengthAttr' => $good]), 'en');
            $this->assertStringContainsString("--hb-fix-length: {$good}", $html, "length-signed should accept '{$good}'");
        }

        // A bare number resolves its implied unit instead of being silently discarded.
        $html = $this->renderer()->renderBlock($this->block(['lengthAttr' => '12']), 'en');
        $this->assertStringContainsString('--hb-fix-length: 12px', $html, "bare '12' should normalize to 12px");

        foreach (['10xyz', 'calc(10px)', '10px;}body{background:url(javascript:alert(1))', 'javascript:alert(1)'] as $bad) {
            $html = $this->renderer()->renderBlock($this->block(['lengthAttr' => $bad]), 'en');
            $this->assertStringNotContainsString($bad, $html, "length-signed should reject '{$bad}'");
        }
    }

    public function test_shadow_kind_valid_layers_including_inset_and_multi_layer(): void
    {
        $cases = [
            '0px 2px 8px var(--ink)',
            'inset 0px 0px 4px #ff0000',
            '0px 0px 0px 4px rgba(0, 0, 0, 0.2)',
            '0px 2px 8px #ff0000, inset 0px 0px 4px var(--ink)',
        ];
        foreach ($cases as $good) {
            $html = $this->renderer()->renderBlock($this->block(['shadowAttr' => $good]), 'en');
            $this->assertStringContainsString("--hb-fix-shadow: {$good}", $html, "shadow should accept '{$good}'");
        }
    }

    public function test_shadow_kind_rejects_malformed_and_adversarial_layers(): void
    {
        $cases = [
            '0px 0px',                                            // lengths but no colour
            '0px 0px 0px 0px 0px var(--ink)',                      // more than 4 lengths
            '0px 2px 8px',                                          // no colour
            '0px 2px 8px #ff0000 #00ff00',                          // two colours
            'inset inset 0px 0px 4px var(--ink)',                   // inset twice
            '0px 0px 4px url(javascript:alert(1))',                 // url() masquerading as colour
            '0px 0px 4px expression(alert(1))',                     // legacy IE expression()
            '0px 0px 4px var(--ink); } body { background: red',    // brace/semicolon breakout
            '0px 0px 4px var(--ink)} * {color:red{',               // unbalanced brace breakout
        ];

        foreach ($cases as $bad) {
            $html = $this->renderer()->renderBlock($this->block(['shadowAttr' => $bad]), 'en');
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('expression(', $html);
            $this->assertStringNotContainsString('--hb-fix-shadow: ' . $bad, $html, "shadow should reject '{$bad}'");
        }
    }

    public function test_enum_kinds_only_accept_their_declared_values(): void
    {
        $matrix = [
            'textAlignAttr' => ['--hb-fix-text-align', ['left', 'center', 'right', 'justify'], ['sideways', 'center;}body{color:red', 'javascript:alert(1)']],
            'align3Attr' => ['--hb-fix-align3', ['start', 'center', 'end'], ['middle', 'end"onmouseover="alert(1)']],
            'positionModeAttr' => ['--hb-fix-position-mode', ['static', 'relative', 'absolute'], ['fixed', 'sticky']],
            'flexDirectionAttr' => ['--hb-fix-flex-direction', ['row', 'column', 'row-reverse', 'column-reverse'], ['diagonal', 'row;}body{background:url(javascript:alert(1))']],
            'flexJustifyAttr' => ['--hb-fix-flex-justify', ['start', 'center', 'end', 'space-between', 'space-around'], ['spaced', 'center}*{color:red']],
            'flexAlignAttr' => ['--hb-fix-flex-align', ['start', 'center', 'end', 'stretch'], ['middle', 'stretch"><script>alert(1)</script>']],
            'overflowAttr' => ['--hb-fix-overflow', ['visible', 'hidden', 'clip'], ['scroll', 'auto']],
        ];

        foreach ($matrix as $attribute => [$var, $valid, $invalid]) {
            foreach ($valid as $good) {
                $html = $this->renderer()->renderBlock($this->block([$attribute => $good]), 'en');
                $this->assertStringContainsString("{$var}: {$good}", $html, "{$attribute} should accept '{$good}'");
            }
            foreach ($invalid as $bad) {
                $html = $this->renderer()->renderBlock($this->block([$attribute => $bad]), 'en');
                $this->assertStringNotContainsString($bad, $html, "{$attribute} should reject '{$bad}'");
                $this->assertStringNotContainsString('<script', $html);
                $this->assertStringNotContainsString('javascript:', $html);
            }
        }
    }

    public function test_wide_and_full_alignment_classes_render_when_declared_and_selected(): void
    {
        $wide = $this->renderer()->renderBlock([
            'id' => 'w1', 'name' => 'heisenberg/supports-fixture',
            'attributes' => [], 'innerBlocks' => [],
            'supports' => ['align' => 'wide'],
        ], 'en');
        $full = $this->renderer()->renderBlock([
            'id' => 'f1', 'name' => 'heisenberg/supports-fixture',
            'attributes' => [], 'innerBlocks' => [],
            'supports' => ['align' => 'full'],
        ], 'en');

        $this->assertStringContainsString('hb-align-wide', $wide);
        $this->assertStringContainsString('hb-align-full', $full);
    }

    public function test_alignment_class_never_emitted_for_a_value_the_contract_does_not_declare(): void
    {
        // The fixture contract's supports.align only lists left/center/right/wide/full;
        // an out-of-list value must never produce a class.
        $html = $this->renderer()->renderBlock([
            'id' => 'x1', 'name' => 'heisenberg/supports-fixture',
            'attributes' => [], 'innerBlocks' => [],
            'supports' => ['align' => 'diagonal'],
        ], 'en');

        $this->assertStringNotContainsString('hb-align-', $html);
    }

    public function test_shipped_paragraph_never_gains_wide_or_full_since_it_does_not_declare_them(): void
    {
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));
        $renderer = new BlockRenderer($registry);

        $html = $renderer->renderBlock([
            'id' => 'p1', 'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'Hi'],
            'supports' => ['align' => 'wide'],
            'innerBlocks' => [],
        ], 'en');

        $this->assertStringNotContainsString('hb-align-wide', $html);
        $this->assertStringNotContainsString('hb-align-full', $html);
    }

    public function test_text_blocks_opt_into_the_capability_sheet_but_not_its_structural_markers(): void
    {
        // SUPERSEDES test_the_working_blocks_never_carry_a_new_capability_marker_class, which
        // asserted the shipped blocks carry NO marker at all. That was the additive-only guard
        // from the phase where SupportsStyle had to be provably inert so it could not disturb
        // blocks that already worked. TODO 7.1 reverses it deliberately: the contracts have to
        // opt in, or every capability the sheet implements — opacity, letter-spacing,
        // text-align — stays unreachable no matter what the contract declares.
        //
        // The container half of the guard still stands: `hb-flex-layout` flips `display` to lay
        // out CHILDREN, so it is meaningless on a block whose innerBlocks are disabled and must
        // never appear on one — in style.className (unconditional) or style.classNames
        // (conditional).
        //
        // The `hb-size-*` markers are NOT forbidden any more. They were, while every marker was
        // unreachable by design; they now appear as conditional classNames driven by per-instance
        // boolean attributes (fillWidth/hugWidth/clipContent — TODO 7.1), which is how a
        // width:100% paragraph is expressed. They are opt-in per block instance, never blanket.
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));
        $structural = ['hb-flex-layout'];

        foreach ([
            'heisenberg/paragraph', 'heisenberg/heading',
        ] as $name) {
            $contract = $registry->getBlock($name);
            $this->assertNotNull($contract, "{$name} should be registered");

            $classNames = trim((string) ($contract['style']['className'] ?? ''));
            $conditionalClasses = array_column($contract['style']['classNames'] ?? [], 'class');

            $this->assertStringContainsString(
                'hb-supports',
                $classNames,
                "{$name} must opt into the capability sheet, or its declared supports render nothing",
            );

            foreach ($structural as $marker) {
                $this->assertStringNotContainsString($marker, $classNames, "{$name} must not carry {$marker} in style.className");
                $this->assertNotContains($marker, $conditionalClasses, "{$name} must not carry {$marker} in style.classNames");
            }

            // The size markers must stay CONDITIONAL — bound to an attribute predicate, never
            // added unconditionally to style.className, which would make every instance of the
            // block full-width or clipped.
            foreach (['hb-size-fill-w', 'hb-size-hug-w', 'hb-size-clip'] as $marker) {
                $this->assertStringNotContainsString($marker, $classNames, "{$marker} must be conditional, not blanket");
                $this->assertContains($marker, $conditionalClasses, "{$marker} should be a classNames binding");
            }
        }
    }

    private function writeFixtureContract(): void
    {
        $contract = [
            '$schema' => '../../../docs/block-schema.md',
            'apiVersion' => 1,
            'name' => 'heisenberg/supports-fixture',
            'title' => 'Supports fixture',
            'category' => 'text',
            'icon' => 'pilcrow',
            'description' => 'Supports fixture',
            'keywords' => ['fixture'],
            'version' => '1.0.0',
            'attributes' => [
                'opacityAttr' => ['type' => 'string', 'default' => ''],
                'angleAttr' => ['type' => 'string', 'default' => ''],
                'lengthAttr' => ['type' => 'string', 'default' => ''],
                'shadowAttr' => ['type' => 'string', 'default' => ''],
                'textAlignAttr' => ['type' => 'string', 'default' => ''],
                'align3Attr' => ['type' => 'string', 'default' => ''],
                'positionModeAttr' => ['type' => 'string', 'default' => ''],
                'flexDirectionAttr' => ['type' => 'string', 'default' => ''],
                'flexJustifyAttr' => ['type' => 'string', 'default' => ''],
                'flexAlignAttr' => ['type' => 'string', 'default' => ''],
                'overflowAttr' => ['type' => 'string', 'default' => ''],
            ],
            'supports' => [
                'align' => ['left', 'center', 'right', 'wide', 'full'],
                'appearance' => ['opacity' => true],
                'position' => ['x' => true, 'y' => true, 'rotation' => true, 'mode' => true],
                'layout' => ['direction' => true, 'justify' => true, 'align' => true, 'gap' => true, 'padding' => true],
                'typography' => ['letterSpacing' => true, 'textAlign' => true, 'textAlignVertical' => true],
                'size' => ['clip' => true],
                'border' => [
                    'width' => ['top' => true, 'right' => true, 'bottom' => true, 'left' => true],
                    'color' => ['top' => true],
                    'style' => ['top' => true],
                    'radius' => [
                        'topLeft' => true, 'topRight' => true,
                        'bottomRight' => true, 'bottomLeft' => true,
                    ],
                ],
                'effects' => ['shadow' => true],
            ],
            'style' => [
                'css' => './supports-fixture.css',
                'className' => 'hb-block-supports-fixture hb-supports',
                'variables' => [
                    // Attribute-sourced — isolated per-kind probing.
                    '--hb-fix-opacity' => ['source' => 'attributes.opacityAttr', 'default' => '', 'sanitize' => 'opacity'],
                    '--hb-fix-angle' => ['source' => 'attributes.angleAttr', 'default' => '', 'sanitize' => 'angle'],
                    '--hb-fix-length' => ['source' => 'attributes.lengthAttr', 'default' => '', 'sanitize' => 'length-signed'],
                    '--hb-fix-shadow' => ['source' => 'attributes.shadowAttr', 'default' => '', 'sanitize' => 'shadow'],
                    '--hb-fix-text-align' => ['source' => 'attributes.textAlignAttr', 'default' => '', 'sanitize' => 'text-align'],
                    '--hb-fix-align3' => ['source' => 'attributes.align3Attr', 'default' => '', 'sanitize' => 'align-3'],
                    '--hb-fix-position-mode' => ['source' => 'attributes.positionModeAttr', 'default' => '', 'sanitize' => 'position-mode'],
                    '--hb-fix-flex-direction' => ['source' => 'attributes.flexDirectionAttr', 'default' => '', 'sanitize' => 'flex-direction'],
                    '--hb-fix-flex-justify' => ['source' => 'attributes.flexJustifyAttr', 'default' => '', 'sanitize' => 'flex-justify'],
                    '--hb-fix-flex-align' => ['source' => 'attributes.flexAlignAttr', 'default' => '', 'sanitize' => 'flex-align'],
                    '--hb-fix-overflow' => ['source' => 'attributes.overflowAttr', 'default' => '', 'sanitize' => 'overflow'],
                    // Supports-sourced — the real generic vars SupportsStyle consumes.
                    '--hb-opacity' => ['source' => 'supports.appearance.opacity', 'default' => '1', 'sanitize' => 'opacity'],
                    '--hb-tx' => ['source' => 'supports.position.x', 'default' => '0', 'sanitize' => 'length-signed'],
                    '--hb-ty' => ['source' => 'supports.position.y', 'default' => '0', 'sanitize' => 'length-signed'],
                    '--hb-rotate' => ['source' => 'supports.position.rotation', 'default' => '0deg', 'sanitize' => 'angle'],
                    '--hb-position-mode' => ['source' => 'supports.position.mode', 'default' => 'static', 'sanitize' => 'position-mode'],
                    '--hb-flex-direction' => ['source' => 'supports.layout.direction', 'default' => 'row', 'sanitize' => 'flex-direction'],
                    '--hb-flex-justify' => ['source' => 'supports.layout.justify', 'default' => 'flex-start', 'sanitize' => 'flex-justify'],
                    '--hb-flex-align' => ['source' => 'supports.layout.align', 'default' => 'stretch', 'sanitize' => 'flex-align'],
                    '--hb-flex-gap' => ['source' => 'supports.layout.gap', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-flex-padding' => ['source' => 'supports.layout.padding', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-letter-spacing' => ['source' => 'supports.typography.letterSpacing', 'default' => '0', 'sanitize' => 'length-signed'],
                    '--hb-text-align' => ['source' => 'supports.typography.textAlign', 'default' => 'left', 'sanitize' => 'text-align'],
                    '--hb-text-align-v' => ['source' => 'supports.typography.textAlignVertical', 'default' => 'start', 'sanitize' => 'align-3'],
                    '--hb-overflow' => ['source' => 'supports.size.clip', 'default' => 'visible', 'sanitize' => 'overflow'],
                    '--hb-border-top-width' => ['source' => 'supports.border.width.top', 'default' => '0', 'sanitize' => 'length-signed'],
                    '--hb-border-top-color' => ['source' => 'supports.border.color.top', 'default' => 'transparent', 'sanitize' => 'color-value'],
                    '--hb-border-top-style' => ['source' => 'supports.border.style.top', 'default' => 'none', 'sanitize' => 'border-style'],
                    '--hb-border-right-width' => ['source' => 'supports.border.width.right', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-border-bottom-width' => ['source' => 'supports.border.width.bottom', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-border-left-width' => ['source' => 'supports.border.width.left', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-border-radius-tl' => ['source' => 'supports.border.radius.topLeft', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-border-radius-tr' => ['source' => 'supports.border.radius.topRight', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-border-radius-br' => ['source' => 'supports.border.radius.bottomRight', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-border-radius-bl' => ['source' => 'supports.border.radius.bottomLeft', 'default' => '0', 'sanitize' => 'size-value'],
                    '--hb-shadow' => ['source' => 'supports.effects.shadow', 'default' => 'none', 'sanitize' => 'shadow'],
                ],
            ],
            'render' => [
                'template' => [
                    'tag' => 'div',
                    'class' => 'hb-block hb-block-supports-fixture',
                    'attributes' => ['data-block-name' => '{{name}}', 'data-block-id' => '{{id}}'],
                    'children' => [
                        ['type' => 'text', 'content' => 'fixture'],
                    ],
                ],
                'publicPartial' => 'blocks.supports-fixture',
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'none', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];

        $dir = $this->root . DIRECTORY_SEPARATOR . 'supports-fixture';
        mkdir($dir, 0775, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'supports-fixture.json',
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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
