<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;

/**
 * Derives the nested style-panel model consumed by the inspector from a
 * contract's `supports` (§3.6 / study R-SUP-PANELS). Extracted verbatim from
 * {@see BlockRegistryService}; the token-select-backed
 * row groups (color/border/radius/typography) live in
 * {@see StylePanelFieldRows} so neither file grows past a single glance.
 */
final class StylePanelDeriver
{
    public function __construct(
        private StylePanelFieldRows $fieldRows,
    ) {
    }

    /**
     * Panels and rows are emitted only for explicitly enabled support features, in a
     * stable canonical order independent of contract declaration order.
     *
     * @return list<array{key: string, title: string, controls: list<array<string, mixed>>}>
     */
    public function derivePanels(array $contract): array
    {
        $supports = $contract['supports'] ?? null;
        if (! is_array($supports)) {
            return [];
        }

        // Panel order: Alignment → Position → Flex Layout → Appearance → Typography → Size
        // → Color → Margin → Padding → Border → Stroke → Border radius → Effects.
        // Each panel is emitted only when the contract declares its supports group.
        $groups = [
            'align' => [
                'title' => 'Alignment',
                'controls' => $this->alignmentRows($supports),
            ],
            'position' => [
                'title' => 'Position',
                'controls' => $this->positionRows($supports),
            ],
            'layout' => [
                'title' => 'Flex Layout',
                'controls' => $this->flexLayoutRows($supports),
            ],
            'appearance' => [
                'title' => 'Appearance',
                'controls' => $this->appearanceRows($supports),
            ],
            'typography' => [
                'title' => 'Typography',
                'controls' => $this->fieldRows->typographyPanelRows($supports),
            ],
            'size' => [
                'title' => 'Size',
                'controls' => $this->sizeRows($supports),
            ],
            'color' => [
                'title' => 'Color',
                'controls' => $this->fieldRows->colorRows($supports),
            ],
            'margin' => [
                'title' => 'Margin',
                'controls' => $this->fieldRows->sideRows($supports, 'spacing', 'margin'),
                'menu' => 'sides',
            ],
            'padding' => [
                'title' => 'Padding',
                'controls' => $this->fieldRows->sideRows($supports, 'spacing', 'padding'),
                'menu' => 'sides',
            ],
            'border' => [
                'title' => 'Border',
                'controls' => $this->fieldRows->borderPanelRows($supports),
            ],
            'borderStroke' => [
                'title' => 'Stroke',
                'controls' => $this->fieldRows->borderStrokeRows($supports),
                'menu' => 'sides',
            ],
            'radius' => [
                'title' => 'Border radius',
                'controls' => $this->fieldRows->radiusRows($supports),
                'menu' => 'corners',
            ],
            'effects' => [
                'title' => 'Effects',
                'controls' => $this->effectsRows($supports),
            ],
        ];

        $panels = [];
        foreach ($groups as $key => $group) {
            if ($group['controls'] === []) {
                continue;
            }

            $panel = [
                'key' => $key,
                'title' => $group['title'],
                'controls' => $group['controls'],
            ];
            if (isset($group['menu'])) {
                $panel['menu'] = $group['menu'];
            }
            $panels[] = $panel;
        }

        return $panels;
    }

    /**
     * Size rows (design Size section): Width/Height, Min Width/Min Height,
     * Max Width/Max Height unit inputs, opt-in per feature via `supports.size`.
     *
     * @return list<array<string, mixed>>
     */
    private function sizeRows(array $supports): array
    {
        $size = $supports['size'] ?? null;
        if (! is_array($size)) {
            return [];
        }

        $controls = [];
        $features = [
            'width' => 'Width', 'height' => 'Height',
            'minWidth' => 'Min Width', 'minHeight' => 'Min Height',
            'maxWidth' => 'Max Width', 'maxHeight' => 'Max Height',
        ];
        foreach ($features as $feature => $label) {
            if (($size[$feature] ?? false) !== true) {
                continue;
            }
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'size-value', 'half' => true,
                'source' => "supports.size.{$feature}",
                'label' => $label,
            ];
        }

        // fill/hug are boolean per axis; SupportsStyle consumes them as utility classes
        // (hb-size-fill-w/h, hb-size-hug-w/h), not vars.
        foreach (['fill' => 'Fill', 'hug' => 'Hug'] as $mode => $modeLabel) {
            $declared = $size[$mode] ?? null;
            if (! is_array($declared)) {
                continue;
            }
            foreach (['width' => 'Width', 'height' => 'Height'] as $axis => $axisLabel) {
                if (($declared[$axis] ?? false) !== true) {
                    continue;
                }
                $controls[] = [
                    'type' => 'toggle', 'half' => true,
                    'source' => "supports.size.{$mode}.{$axis}",
                    'label' => "{$modeLabel} {$axisLabel}",
                ];
            }
        }

        // `clip` offers the overflow enum directly (visible/hidden/clip) —
        // consumed via --hb-overflow. See SupportsStyle for the CSS side.
        if (($size['clip'] ?? false) === true) {
            $controls[] = [
                'type' => 'select', 'sanitize' => 'overflow',
                'source' => 'supports.size.clip',
                'label' => 'Clip content',
                'options' => [
                    ['value' => 'visible', 'label' => 'Visible'],
                    ['value' => 'hidden', 'label' => 'Hidden'],
                    ['value' => 'clip', 'label' => 'Clip'],
                ],
            ];
        }

        return $controls;
    }

    /**
     * Alignment rows: one `segmented` control listing exactly the block's
     * declared `supports.align` values.
     *
     * @return list<array<string, mixed>>
     */
    private function alignmentRows(array $supports): array
    {
        $align = $supports['align'] ?? null;
        if (! is_array($align) || $align === []) {
            return [];
        }

        $labels = ['left' => 'Left', 'center' => 'Center', 'right' => 'Right', 'wide' => 'Wide', 'full' => 'Full'];
        $options = [];
        foreach ($align as $value) {
            if (is_string($value) && isset($labels[$value])) {
                $options[] = ['value' => $value, 'label' => $labels[$value]];
            }
        }
        if ($options === []) {
            return [];
        }

        return [[
            'type' => 'segmented',
            'source' => 'supports.align',
            'label' => 'Alignment',
            'options' => $options,
        ]];
    }

    /**
     * Position rows (design Advanced tab): position mode select, an X/Y
     * offset pair, and rotation, opt-in per field via `supports.position`.
     *
     * @return list<array<string, mixed>>
     */
    private function positionRows(array $supports): array
    {
        $position = $supports['position'] ?? null;
        if (! is_array($position)) {
            return [];
        }

        $controls = [];
        if (($position['mode'] ?? false) === true) {
            $controls[] = [
                'type' => 'select', 'sanitize' => 'position-mode',
                'source' => 'supports.position.mode',
                'label' => 'Position',
                'options' => [
                    ['value' => 'static', 'label' => 'Static'],
                    ['value' => 'relative', 'label' => 'Relative'],
                    ['value' => 'absolute', 'label' => 'Absolute'],
                ],
            ];
        }
        if (($position['x'] ?? false) === true || ($position['y'] ?? false) === true) {
            $controls[] = [
                'type' => 'xy-pair', 'sanitize' => 'length-signed',
                'xSource' => 'supports.position.x',
                'ySource' => 'supports.position.y',
                'label' => 'Offset',
            ];
        }
        if (($position['rotation'] ?? false) === true) {
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'angle', 'half' => true,
                'source' => 'supports.position.rotation',
                'label' => 'Rotation',
            ];
        }

        return $controls;
    }

    /**
     * Flex-layout rows (the currently-dead `layout` group, now implemented):
     * direction, justify + align (grid pickers), gap, and the container's
     * own padding, opt-in per field via `supports.layout`.
     *
     * @return list<array<string, mixed>>
     */
    private function flexLayoutRows(array $supports): array
    {
        $layout = $supports['layout'] ?? null;
        if (! is_array($layout)) {
            return [];
        }

        $controls = [];
        if (($layout['direction'] ?? false) === true) {
            $controls[] = [
                'type' => 'segmented', 'sanitize' => 'flex-direction', 'half' => true,
                'source' => 'supports.layout.direction',
                'label' => 'Direction',
                'options' => [
                    ['value' => 'row', 'label' => 'Row'],
                    ['value' => 'column', 'label' => 'Column'],
                    ['value' => 'row-reverse', 'label' => 'Row reverse'],
                    ['value' => 'column-reverse', 'label' => 'Column reverse'],
                ],
            ];
        }
        if (($layout['justify'] ?? false) === true) {
            $controls[] = [
                'type' => 'align-grid', 'sanitize' => 'flex-justify', 'half' => true,
                'source' => 'supports.layout.justify',
                'label' => 'Justify',
                'options' => [
                    ['value' => 'start', 'label' => 'Start'],
                    ['value' => 'center', 'label' => 'Center'],
                    ['value' => 'end', 'label' => 'End'],
                    ['value' => 'space-between', 'label' => 'Space between'],
                    ['value' => 'space-around', 'label' => 'Space around'],
                ],
            ];
        }
        if (($layout['align'] ?? false) === true) {
            $controls[] = [
                'type' => 'align-grid', 'sanitize' => 'flex-align', 'half' => true,
                'source' => 'supports.layout.align',
                'label' => 'Align',
                'options' => [
                    ['value' => 'start', 'label' => 'Start'],
                    ['value' => 'center', 'label' => 'Center'],
                    ['value' => 'end', 'label' => 'End'],
                    ['value' => 'stretch', 'label' => 'Stretch'],
                ],
            ];
        }
        if (($layout['gap'] ?? false) === true) {
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'size-value', 'half' => true,
                'source' => 'supports.layout.gap',
                'label' => 'Gap',
            ];
        }
        if (($layout['padding'] ?? false) === true) {
            $controls[] = [
                'type' => 'unit', 'sanitize' => 'size-value', 'half' => true,
                'source' => 'supports.layout.padding',
                'label' => 'Padding',
            ];
        }

        return $controls;
    }

    /**
     * Appearance rows: opacity as a 0–1 range, opt-in via `supports.appearance.opacity`.
     *
     * @return list<array<string, mixed>>
     */
    private function appearanceRows(array $supports): array
    {
        $appearance = $supports['appearance'] ?? null;
        if (! is_array($appearance) || ($appearance['opacity'] ?? false) !== true) {
            return [];
        }

        return [[
            'type' => 'range', 'sanitize' => 'opacity',
            'source' => 'supports.appearance.opacity',
            'label' => 'Opacity',
            'min' => 0, 'max' => 1, 'step' => 0.01,
        ]];
    }

    /**
     * Effects rows: a box-shadow builder, opt-in via `supports.effects.shadow`.
     *
     * @return list<array<string, mixed>>
     */
    private function effectsRows(array $supports): array
    {
        $effects = $supports['effects'] ?? null;
        if (! is_array($effects) || ($effects['shadow'] ?? false) !== true) {
            return [];
        }

        return [[
            'type' => 'shadow', 'sanitize' => 'shadow',
            'source' => 'supports.effects.shadow',
            'label' => 'Shadow',
        ]];
    }
}
