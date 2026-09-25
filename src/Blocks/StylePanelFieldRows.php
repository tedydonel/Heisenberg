<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;

/**
 * The inspector style-panel row derivations that read a design-token select
 * (color, border, per-side spacing/border fields, typography). Extracted
 * verbatim from {@see BlockRegistryService}; split out of
 * {@see StylePanelDeriver} so neither file grows past a single glance.
 */
final class StylePanelFieldRows
{
    public function __construct(
        private DesignTokenCatalog $tokens,
    ) {
    }

    /**
     * Color rows: text + background as color fields (typed value + swatch).
     * The registry's color tokens ride along as preset options.
     *
     * @return list<array<string, mixed>>
     */
    public function colorRows(array $supports): array
    {
        $color = $supports['color'] ?? null;
        if (! is_array($color)) {
            return [];
        }

        $controls = [];
        // Background is a fill: it may carry a gradient. Text stays flat-colour-only — a
        // gradient `color` declaration is invalid CSS — so each feature gets its own sanitize
        // kind rather than sharing one, in lockstep with the contracts' own style.variables.
        $sanitizers = ['text' => 'color-value', 'background' => 'color-value-or-gradient'];
        foreach (['text' => 'Text', 'background' => 'Background'] as $feature => $label) {
            if (($color[$feature] ?? false) !== true) {
                continue;
            }
            $controls[] = [
                'type' => 'color', 'sanitize' => $sanitizers[$feature], 'tokenKind' => 'color',
                'source' => "supports.color.{$feature}",
                'label' => $label,
                'options' => $this->tokens->options('color'),
            ];
        }

        return $controls;
    }

    /**
     * @param array<string, array{0: string, 1: string}> $features
     * @return list<array<string, mixed>>
     */
    private function selectSupportRows(array $supports, string $group, array $features): array
    {
        $enabled = $supports[$group] ?? null;
        if (! is_array($enabled)) {
            return [];
        }

        $controls = [];
        foreach ($features as $feature => [$tokenKind, $label]) {
            if (($enabled[$feature] ?? false) !== true) {
                continue;
            }

            $controls[] = [
                'type' => 'select',
                'tokenKind' => $tokenKind,
                'source' => "supports.{$group}.{$feature}",
                'label' => $label,
                'options' => $this->tokens->options($tokenKind),
            ];
        }

        return $controls;
    }

    /**
     * Per-side spacing rows (design Margin/Padding 2×2 grids). A feature
     * declared `true` derives one full-width row; declared as a side map
     * (`{"top": true, …}`) it derives a half-width row per enabled side.
     *
     * @return list<array<string, mixed>>
     */
    public function sideRows(array $supports, string $group, string $feature): array
    {
        $declared = $supports[$group][$feature] ?? null;

        if ($declared === true) {
            return [[
                'type' => 'unit', 'sanitize' => 'size-value',
                'source' => "supports.{$group}.{$feature}",
                'label' => ucfirst($feature),
            ]];
        }

        if (! is_array($declared)) {
            return [];
        }

        $controls = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (($declared[$side] ?? false) !== true) {
                continue;
            }
            // Design Margin/Padding 2×2: per-side fields carry the side
            // glyph (align-top/right/bottom/left) instead of a text label.
            $controls[] = [
                'type' => 'sidefield', 'sanitize' => 'size-value', 'half' => true,
                'side' => $side,
                'source' => "supports.{$group}.{$feature}.{$side}",
                'label' => ucfirst($side),
            ];
        }

        return $controls;
    }

    /**
     * Border rows (design Border section): Style enum select + Width/Color
     * token selects, each opt-in via `supports.border`.
     *
     * @return list<array<string, mixed>>
     */
    public function borderPanelRows(array $supports): array
    {
        $border = $supports['border'] ?? null;
        if (! is_array($border)) {
            return [];
        }

        $controls = [];
        if (($border['style'] ?? false) === true) {
            $controls[] = [
                'type' => 'select', 'source' => 'supports.border.style', 'label' => 'Style',
                'options' => [
                    ['value' => '', 'label' => 'None'],
                    ['value' => 'solid', 'label' => 'Solid'],
                    ['value' => 'dashed', 'label' => 'Dashed'],
                    ['value' => 'dotted', 'label' => 'Dotted'],
                ],
            ];
        }
        if (($border['width'] ?? false) === true) {
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'size-value', 'half' => true,
                'source' => 'supports.border.width', 'label' => 'Width',
            ];
        }
        if (($border['color'] ?? false) === true) {
            $controls[] = [
                'type' => 'color', 'sanitize' => 'color-value', 'tokenKind' => 'color', 'half' => true,
                'source' => 'supports.border.color', 'label' => 'Color',
                'options' => $this->tokens->options('color'),
            ];
        }

        return $controls;
    }

    /**
     * Border-radius rows (design Border radius 2×2): one token select per
     * corner when `supports.border.radius` is a corner map, or a single row
     * when declared `true`.
     *
     * @return list<array<string, mixed>>
     */
    public function radiusRows(array $supports): array
    {
        $radius = $supports['border']['radius'] ?? null;

        if ($radius === true) {
            return [[
                'type' => 'unit', 'sanitize' => 'size-value',
                'source' => 'supports.border.radius', 'label' => 'Radius',
            ]];
        }

        if (! is_array($radius)) {
            return [];
        }

        $controls = [];
        foreach (['topLeft' => 'Top left', 'topRight' => 'Top right', 'bottomLeft' => 'Bottom left', 'bottomRight' => 'Bottom right'] as $corner => $label) {
            if (($radius[$corner] ?? false) !== true) {
                continue;
            }
            // Design Border radius 2×2: fields carry a corner glyph.
            $controls[] = [
                'type' => 'cornerfield', 'sanitize' => 'size-value', 'half' => true,
                'corner' => $corner,
                'source' => "supports.border.radius.{$corner}",
                'label' => $label,
            ];
        }

        return $controls;
    }

    /**
     * Typography rows (design LtsDN): a full-width Font family picker, then
     * Weight (named-hundreds select) + Size (unit input) as halves, plus an
     * optional Line height number row.
     *
     * @return list<array<string, mixed>>
     */
    public function typographyPanelRows(array $supports): array
    {
        $typography = $supports['typography'] ?? null;
        if (! is_array($typography)) {
            return [];
        }

        $controls = [];
        if (($typography['fontFamily'] ?? false) === true) {
            $controls[] = [
                'type' => 'font', 'sanitize' => 'font-family',
                'source' => 'supports.typography.fontFamily',
                'label' => 'Font family',
            ];
        }
        if (($typography['fontWeight'] ?? false) === true) {
            $controls[] = [
                'type' => 'select', 'sanitize' => 'font-weight', 'half' => true,
                'source' => 'supports.typography.fontWeight',
                'label' => 'Weight',
                'options' => [
                    ['value' => '', 'label' => 'Default'],
                    ['value' => '100', 'label' => 'Thin'],
                    ['value' => '200', 'label' => 'Extra light'],
                    ['value' => '300', 'label' => 'Light'],
                    ['value' => '400', 'label' => 'Regular'],
                    ['value' => '500', 'label' => 'Medium'],
                    ['value' => '600', 'label' => 'Semi bold'],
                    ['value' => '700', 'label' => 'Bold'],
                    ['value' => '800', 'label' => 'Extra bold'],
                    ['value' => '900', 'label' => 'Black'],
                ],
            ];
        }
        if (($typography['fontSize'] ?? false) === true) {
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'size-value', 'half' => true,
                'source' => 'supports.typography.fontSize',
                'label' => 'Size',
            ];
        }

        if (($typography['lineHeight'] ?? false) === true) {
            $controls[] = [
                'type' => 'number',
                'source' => 'supports.typography.lineHeight',
                'label' => 'Line height',
                'min' => 1,
                'max' => 3,
                'step' => 0.1,
            ];
        }

        if (($typography['letterSpacing'] ?? false) === true) {
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'length-signed', 'half' => true,
                'source' => 'supports.typography.letterSpacing',
                'label' => 'Letter spacing',
            ];
        }
        if (($typography['textAlign'] ?? false) === true) {
            $controls[] = [
                'type' => 'segmented', 'sanitize' => 'text-align', 'half' => true,
                'source' => 'supports.typography.textAlign',
                'label' => 'Align',
                'options' => [
                    ['value' => 'left', 'label' => 'Left'],
                    ['value' => 'center', 'label' => 'Center'],
                    ['value' => 'right', 'label' => 'Right'],
                    ['value' => 'justify', 'label' => 'Justify'],
                ],
            ];
        }
        if (($typography['textAlignVertical'] ?? false) === true) {
            $controls[] = [
                'type' => 'segmented', 'sanitize' => 'align-3', 'half' => true,
                'source' => 'supports.typography.textAlignVertical',
                'label' => 'Vertical align',
                'options' => [
                    ['value' => 'start', 'label' => 'Top'],
                    ['value' => 'center', 'label' => 'Middle'],
                    ['value' => 'end', 'label' => 'Bottom'],
                ],
            ];
        }

        return $controls;
    }

    /**
     * Border → Stroke rows (design Border section's per-side variant): one
     * row per enabled side for width/color/style, only when the feature is
     * declared as a side-map (`{"top": true, …}`) rather than the existing
     * single-value `true` form (which stays on the `border` panel unchanged).
     *
     * @return list<array<string, mixed>>
     */
    public function borderStrokeRows(array $supports): array
    {
        $border = $supports['border'] ?? null;
        if (! is_array($border)) {
            return [];
        }

        $controls = [];

        $width = $border['width'] ?? null;
        if (is_array($width)) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (($width[$side] ?? false) !== true) {
                    continue;
                }
                $controls[] = [
                    'type' => 'sidefield', 'sanitize' => 'length-signed', 'half' => true,
                    'side' => $side,
                    'source' => "supports.border.width.{$side}",
                    'label' => ucfirst($side) . ' width',
                ];
            }
        }

        $color = $border['color'] ?? null;
        if (is_array($color)) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (($color[$side] ?? false) !== true) {
                    continue;
                }
                $controls[] = [
                    'type' => 'color', 'sanitize' => 'color-value', 'tokenKind' => 'color', 'half' => true,
                    'side' => $side,
                    'source' => "supports.border.color.{$side}",
                    'label' => ucfirst($side) . ' color',
                    'options' => $this->tokens->options('color'),
                ];
            }
        }

        $style = $border['style'] ?? null;
        if (is_array($style)) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (($style[$side] ?? false) !== true) {
                    continue;
                }
                $controls[] = [
                    'type' => 'select', 'sanitize' => 'border-style', 'half' => true,
                    'side' => $side,
                    'source' => "supports.border.style.{$side}",
                    'label' => ucfirst($side) . ' style',
                    'options' => [
                        ['value' => 'none', 'label' => 'None'],
                        ['value' => 'solid', 'label' => 'Solid'],
                        ['value' => 'dashed', 'label' => 'Dashed'],
                        ['value' => 'dotted', 'label' => 'Dotted'],
                    ],
                ];
            }
        }

        if (($border['position'] ?? false) === true) {
            $controls[] = [
                'type' => 'select', 'sanitize' => 'box-sizing', 'half' => true,
                'source' => 'supports.border.position',
                'label' => 'Position',
                'options' => [
                    ['value' => 'border-box', 'label' => 'Inside'],
                    ['value' => 'content-box', 'label' => 'Outside'],
                ],
            ];
        }

        return $controls;
    }
}
