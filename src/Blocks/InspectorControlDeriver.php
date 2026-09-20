<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;
use Illuminate\Support\Str;

/**
 * Derives the editor's inspector controls from a contract's `attributes` and
 * `supports` (§3.6 / study R-SUP-PANELS). Extracted verbatim from
 * {@see BlockRegistryService}.
 */
final class InspectorControlDeriver
{
    public function __construct(
        private ContractLocalizer $localizer,
        private DesignTokenCatalog $tokens,
    ) {
    }

    /**
     * Derive the inspector controls from the contract's attributes (§3.6). One
     * control per attribute, in declaration order; widget type and options follow
     * the attribute's `type`/`enum`, with an optional per-attribute `control`
     * override for the few cases derivation can't express (curated option lists,
     * media pickers, textareas, ranges, custom sections, or `false` to hide).
     *
     * @return list<array<string, mixed>>
     */
    public function deriveControls(array $contract, ?string $locale): array
    {
        $attributes = $contract['attributes'] ?? null;
        if (! is_array($attributes)) {
            return [];
        }

        $slug = $this->localizer->langSlug((string) ($contract['name'] ?? ''));
        $controls = [];

        foreach ($attributes as $attribute => $def) {
            if (! is_array($def)) {
                continue;
            }

            $override = $def['control'] ?? null;
            if ($override === false) {
                continue; // explicitly hidden from the inspector
            }
            $override = is_array($override) ? $override : [];

            $type = $override['type'] ?? $this->defaultControlType($def);
            if ($type === null) {
                continue; // array/object with no override: no sensible control
            }

            $snake = Str::snake($attribute);
            $control = [
                'id' => $attribute,
                'attribute' => $attribute,
                'type' => $type,
                'section' => $override['section'] ?? 'settings',
                'label' => isset($override['label']) && is_string($override['label'])
                    ? $this->localizer->localize($override['label'], $locale)
                    : $this->localizer->localizeLabel("heisenberg::blocks.{$slug}.controls.{$snake}", $attribute, $locale),
            ];

            foreach (['min', 'max', 'step', 'placeholder', 'half'] as $extra) {
                if (array_key_exists($extra, $override)) {
                    $control[$extra] = $override[$extra];
                }
            }

            foreach (['showWhen', 'disableWhen'] as $condition) {
                if (isset($override[$condition]) && is_array($override[$condition])) {
                    $control[$condition] = $override[$condition];
                }
            }

            if (in_array($type, ['select', 'button-group'], true)) {
                $control['options'] = $this->deriveOptions($def, $override, $slug, $snake, $locale);
            }

            $controls[] = $control;
        }

        return $controls;
    }

    /**
     * Derive inspector controls from the contract's `supports` (§3.6 / study
     * R-SUP-PANELS). One control per enabled support sub-feature, grouped into the
     * canonical style panels (color, typography, spacing, border). Each binds to a
     * path under `block.supports` (not an attribute) and a token-select offers
     * design-token options from the registry. Without this a block whose styling is
     * entirely `supports` (e.g. heading, quote) would render an empty inspector.
     *
     * @return list<array<string, mixed>>
     */
    public function deriveSupportControls(array $contract, ?string $locale): array
    {
        $supports = $contract['supports'] ?? null;
        if (! is_array($supports)) {
            return [];
        }

        $slug = $this->localizer->langSlug((string) ($contract['name'] ?? ''));
        $controls = [];

        $color = $supports['color'] ?? [];
        if (is_array($color)) {
            foreach (['text', 'background'] as $feature) {
                if (($color[$feature] ?? false) === true) {
                    $controls[] = $this->supportControl('color', $feature, 'color', $slug, $locale);
                }
            }
        }

        $typography = $supports['typography'] ?? [];
        if (is_array($typography)) {
            if (($typography['fontSize'] ?? false) === true) {
                $controls[] = $this->supportControl('typography', 'fontSize', 'fontSize', $slug, $locale);
            }
            if (($typography['lineHeight'] ?? false) === true) {
                $controls[] = $this->supportControl('typography', 'lineHeight', null, $slug, $locale, [
                    'type' => 'number', 'min' => 1, 'max' => 3, 'step' => 0.1,
                ]);
            }
        }

        $spacing = $supports['spacing'] ?? [];
        if (is_array($spacing)) {
            foreach (['margin', 'padding', 'blockGap'] as $feature) {
                if (($spacing[$feature] ?? false) === true) {
                    $controls[] = $this->supportControl('spacing', $feature, 'space', $slug, $locale);
                }
            }
        }

        $border = $supports['border'] ?? [];
        if (is_array($border) && ($border['radius'] ?? false) === true) {
            $controls[] = $this->supportControl('border', 'radius', 'space', $slug, $locale);
        }

        return $controls;
    }

    /** Default editor widget for an attribute (an `enum` always means a select). */
    private function defaultControlType(array $def): ?string
    {
        if (isset($def['enum']) && is_array($def['enum'])) {
            return 'select';
        }

        return match ($def['type'] ?? null) {
            'rich-text' => 'rich-text',
            'boolean' => 'toggle',
            'integer', 'number' => 'number',
            'url' => 'link',
            'media' => 'media',
            'string', 'token' => 'text',
            default => null,
        };
    }

    /**
     * Options for a select: a curated override list wins (e.g. button hover
     * colours, whose value→label the enum can't express); otherwise derive one
     * option per enum value, labelled by lang convention.
     *
     * @return list<array{value: mixed, label: mixed}>
     */
    private function deriveOptions(array $def, array $override, string $slug, string $snake, ?string $locale): array
    {
        if (isset($override['options']) && is_array($override['options'])) {
            return array_values(array_map(
                function (array $opt) use ($locale): array {
                    $mapped = [
                        'value' => $opt['value'] ?? '',
                        'label' => $this->localizer->localize($opt['label'] ?? '', $locale),
                    ];
                    if (isset($opt['group']) && is_string($opt['group'])) {
                        $mapped['group'] = $opt['group'];   // select optgroup
                    }

                    return $mapped;
                },
                array_filter($override['options'], 'is_array')
            ));
        }

        $enum = (isset($def['enum']) && is_array($def['enum'])) ? $def['enum'] : [];

        return array_map(function ($value) use ($slug, $snake, $locale): array {
            $key = $this->localizer->optionKey($value);

            return [
                'value' => $value,
                'label' => $this->localizer->localizeLabel("heisenberg::blocks.{$slug}.options.{$snake}.{$key}", (string) $value, $locale),
            ];
        }, array_values($enum));
    }

    /**
     * Build one supports-derived control. `$tokenKind` selects the palette
     * (color/fontSize/space); pass `null` with an `$extra` override for a non-token
     * control (e.g. lineHeight as a number). Labels resolve from the generic
     * `heisenberg::supports.<group>.<feature>` namespace, humanized as a fallback.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function supportControl(string $group, string $feature, ?string $tokenKind, string $slug, ?string $locale, array $extra = []): array
    {
        $snake = Str::snake($feature);
        $control = [
            'id' => "{$group}.{$feature}",
            'binding' => "{$group}.{$feature}",
            'source' => 'supports',
            'type' => $extra['type'] ?? 'select',
            'section' => $group,
            'label' => $this->localizer->localizeLabel("heisenberg::supports.{$group}.{$snake}", $feature, $locale),
        ];

        foreach (['min', 'max', 'step'] as $k) {
            if (array_key_exists($k, $extra)) {
                $control[$k] = $extra[$k];
            }
        }

        if ($tokenKind !== null) {
            $control['tokenKind'] = $tokenKind;
            $control['options'] = $this->tokens->options($tokenKind);
        }

        return $control;
    }
}
