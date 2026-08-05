<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Support\AnimationCatalog;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Illuminate\Support\Str;

/**
 * Discovers block-contract JSON on disk, validates each via {@see BlockContractValidator},
 * and serves the editor a hashed, localized registry envelope (§3.4).
 *
 * Heisenberg internalizes the contracts under the package `resources/blocks`
 * (overridable via `config('heisenberg.block_root')`) rather than the GTC host's
 * view directory (§3.10). The registry hash is computed on the *untranslated*
 * contracts so it is locale-stable; the public `blocks` are localized.
 */
class BlockRegistryService
{
    public const SCHEMA_VERSION = 1;

    /** Translatable string namespace gate for {@see localize()}. */
    private const TRANSLATION_NAMESPACE = 'heisenberg::';

    /** @var array{contracts: array<string, array>, paths: array<string, array{abs: string, rel: string}>, errors: list<array{file: string, error: string}>}|null */
    private ?array $scanCache = null;

    public function __construct(
        private BlockContractValidator $validator,
        private ?string $blockRootPath = null,
    ) {
    }

    /** The raw scan: valid contracts with their on-disk path keys merged in. */
    public function discover(): array
    {
        $scan = $this->scan();
        $blocks = [];
        foreach ($scan['contracts'] as $name => $contract) {
            $blocks[] = $contract + [
                '_absolutePath' => $scan['paths'][$name]['abs'],
                '_relativePath' => $scan['paths'][$name]['rel'],
            ];
        }

        return ['blocks' => $blocks, 'errors' => $scan['errors']];
    }

    /** The full registry envelope served to the editor. */
    public function registry(?string $locale = null): array
    {
        $scan = $this->scan();
        $bare = $this->sortedByName(array_values($scan['contracts']));

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'registryHash'  => $this->computeHash($bare),
            'blocks'        => array_map(fn (array $c): array => $this->localizeContract($c, $locale), $bare),
            'categories'    => $this->getCategories($bare),
            'icons'         => $this->referencedIcons($bare),
            'generatedAt'   => now()->toIso8601String(),
            'errors'        => $scan['errors'],
        ];
    }

    /** Canonical, locale-stable hash of the (untranslated) contracts. */
    public function computeHash(?array $blocks = null): string
    {
        $blocks ??= $this->sortedByName(array_values($this->scan()['contracts']));

        $json = json_encode(
            $blocks,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return 'sha256:' . hash('sha256', (string) $json);
    }

    /** @return string[] sorted, distinct category values */
    public function getCategories(?array $blocks = null): array
    {
        $blocks ??= array_values($this->scan()['contracts']);

        $categories = [];
        foreach ($blocks as $contract) {
            if (isset($contract['category']) && is_string($contract['category'])) {
                $categories[] = $contract['category'];
            }
        }
        $categories = array_values(array_unique($categories));
        sort($categories);

        return $categories;
    }

    public function getBlock(string $name): ?array
    {
        return $this->scan()['contracts'][$name] ?? null;
    }

    public function isBlockKnown(string $name): bool
    {
        return isset($this->scan()['contracts'][$name]);
    }

    /** Path-traversal guard: realpath-confine a candidate file to the block root. */
    public function validatePath(string $path): bool
    {
        $root = realpath($this->rootPath());
        $real = realpath($path);

        if ($root === false || $real === false) {
            return false;
        }

        return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    // ── internals ─────────────────────────────────────────────

    /**
     * @return array{contracts: array<string, array>, paths: array<string, array{abs: string, rel: string}>, errors: list<array{file: string, error: string}>}
     */
    private function scan(): array
    {
        if ($this->scanCache !== null) {
            return $this->scanCache;
        }

        $contracts = [];
        $paths = [];
        $errors = [];

        $root = $this->rootPath();
        $realRoot = realpath($root);

        if ($realRoot === false || ! is_dir($realRoot)) {
            return $this->scanCache = compact('contracts', 'paths', 'errors');
        }

        $files = $this->jsonFiles($realRoot);
        sort($files);

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false || ! str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                $errors[] = ['file' => $file, 'error' => 'File is outside the block root'];
                continue;
            }

            try {
                $contract = json_decode((string) @file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $errors[] = ['file' => $real, 'error' => 'Invalid JSON: ' . $e->getMessage()];
                continue;
            }

            if (! is_array($contract)) {
                $errors[] = ['file' => $real, 'error' => 'Contract is not a JSON object'];
                continue;
            }

            $result = $this->validator->validate($contract);
            if (! $result['valid']) {
                foreach ($result['errors'] as $message) {
                    $errors[] = ['file' => $real, 'error' => $message];
                }
                continue;
            }

            $name = (string) $contract['name'];
            if (isset($contracts[$name])) {
                $errors[] = ['file' => $real, 'error' => "Duplicate block name: {$name}"];
                continue;
            }

            $contracts[$name] = $this->applyCapabilities($contract);
            $paths[$name] = [
                'abs' => $real,
                'rel' => ltrim(str_replace($realRoot, '', $real), DIRECTORY_SEPARATOR),
            ];
        }

        return $this->scanCache = compact('contracts', 'paths', 'errors');
    }

    /**
     * Expand declared engine capabilities into the contract. Runs at scan
     * time (post-validation) so the renderer, the payload validator and the
     * client registry all see the same expanded contract.
     *
     * `supports.animation: true` — the whole animation kit derives from
     * AnimationCatalog: the animate/duration/delay/easing/once attributes
     * (with inspector controls), the per-preset + easing classNames, the
     * duration/delay style variables, and the runtime data attributes on
     * the template root. Contracts never hand-write animation plumbing.
     */
    private function applyCapabilities(array $contract): array
    {
        if (($contract['supports']['animation'] ?? false) !== true) {
            return $contract;
        }

        $attributes = is_array($contract['attributes'] ?? null) ? $contract['attributes'] : [];
        if (! isset($attributes['animate'])) {
            $entranceKeys = AnimationCatalog::keys();
            $show = ['attribute' => 'animate', 'in' => $entranceKeys];
            $attributes['animate'] = [
                'type' => 'string', 'default' => '', 'sanitize' => 'text',
                'enum' => array_merge([''], AnimationCatalog::keys()),
                'control' => [
                    'type' => 'select', 'section' => 'animation', 'label' => 'Animation',
                    'options' => AnimationCatalog::options(),
                ],
            ];
            $attributes['animateDuration'] = [
                'type' => 'integer', 'default' => AnimationCatalog::DEFAULT_DURATION, 'sanitize' => 'integer',
                'control' => [
                    'type' => 'range', 'section' => 'animation', 'label' => 'Duration (ms)',
                    'min' => 100, 'max' => 3000, 'step' => 50, 'showWhen' => $show,
                ],
            ];
            $attributes['animateDelay'] = [
                'type' => 'integer', 'default' => AnimationCatalog::DEFAULT_DELAY, 'sanitize' => 'integer',
                'control' => [
                    'type' => 'range', 'section' => 'animation', 'label' => 'Delay (ms)',
                    'min' => 0, 'max' => 3000, 'step' => 50, 'showWhen' => $show,
                ],
            ];
            $attributes['animateEasing'] = [
                'type' => 'string', 'default' => AnimationCatalog::DEFAULT_EASING, 'sanitize' => 'text',
                'enum' => AnimationCatalog::easingKeys(),
                'control' => [
                    'type' => 'select', 'section' => 'animation', 'label' => 'Easing',
                    'options' => AnimationCatalog::easingOptions(), 'showWhen' => $show,
                ],
            ];
            $attributes['animateOnce'] = [
                'type' => 'boolean', 'default' => true, 'sanitize' => 'boolean',
                'control' => [
                    'type' => 'toggle', 'section' => 'animation', 'label' => 'Play once',
                    'showWhen' => $show,
                ],
            ];
        }
        $contract['attributes'] = $attributes;

        $classNames = is_array($contract['style']['classNames'] ?? null) ? $contract['style']['classNames'] : [];
        foreach (AnimationCatalog::keys() as $key) {
            $classNames[] = ['class' => 'hb-anim-' . $key, 'when' => ['attribute' => 'animate', 'equals' => $key]];
        }
        foreach (AnimationCatalog::easingKeys() as $key) {
            $classNames[] = ['class' => 'hb-ease-' . $key, 'when' => ['attribute' => 'animateEasing', 'equals' => $key]];
        }
        $contract['style']['classNames'] = $classNames;

        $variables = is_array($contract['style']['variables'] ?? null) ? $contract['style']['variables'] : [];
        $variables['--hb-anim-dur'] = [
            'source' => 'attributes.animateDuration',
            'default' => (string) AnimationCatalog::DEFAULT_DURATION,
            'sanitize' => 'integer',
        ];
        $variables['--hb-anim-delay'] = [
            'source' => 'attributes.animateDelay',
            'default' => (string) AnimationCatalog::DEFAULT_DELAY,
            'sanitize' => 'integer',
        ];
        $contract['style']['variables'] = $variables;

        if (is_array($contract['render']['template'] ?? null)) {
            $template = $contract['render']['template'];
            $templateAttributes = is_array($template['attributes'] ?? null) ? $template['attributes'] : [];
            $templateAttributes['data-hb-anim'] = ['value' => '{{attributes.animate}}', 'omitWhenEmpty' => true];
            $templateAttributes['data-hb-anim-once'] = ['boolean' => '{{attributes.animateOnce}}'];
            $template['attributes'] = $templateAttributes;
            $contract['render']['template'] = $template;
        }

        return $contract;
    }

    /** @return string[] absolute paths of every *.json under the root */
    private function jsonFiles(string $root): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    private function sortedByName(array $blocks): array
    {
        usort($blocks, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $blocks;
    }

    private function localizeContract(array $contract, ?string $locale): array
    {
        $contract['title'] = $this->localize($contract['title'] ?? null, $locale);
        $contract['description'] = $this->localize($contract['description'] ?? null, $locale);
        $contract['controls'] = array_merge(
            $this->deriveControls($contract, $locale),
            $this->deriveSupportControls($contract, $locale),
        );
        $contract['panels'] = $this->derivePanels($contract);

        return $contract;
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
    private function deriveControls(array $contract, ?string $locale): array
    {
        $attributes = $contract['attributes'] ?? null;
        if (! is_array($attributes)) {
            return [];
        }

        $slug = $this->langSlug((string) ($contract['name'] ?? ''));
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
                    ? $this->localize($override['label'], $locale)
                    : $this->localizeLabel("heisenberg::blocks.{$slug}.controls.{$snake}", $attribute, $locale),
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
    private function deriveSupportControls(array $contract, ?string $locale): array
    {
        $supports = $contract['supports'] ?? null;
        if (! is_array($supports)) {
            return [];
        }

        $slug = $this->langSlug((string) ($contract['name'] ?? ''));
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

    /**
     * Derive the nested style-panel model consumed by the inspector. Panels and
     * rows are emitted only for explicitly enabled support features, in a stable
     * canonical order independent of contract declaration order.
     *
     * @return list<array{key: string, title: string, controls: list<array<string, mixed>>}>
     */
    private function derivePanels(array $contract): array
    {
        $supports = $contract['supports'] ?? null;
        if (! is_array($supports)) {
            return [];
        }

        // Panel order mirrors the design's Block.style component (LtsDN):
        // Alignment → Position → Flex Layout → Appearance → Typography → Size
        // → Color → Margin → Padding → Border → Stroke → Border radius → Effects.
        // (Color is ours — the design tucks color into Border only; the
        // engine keeps text/background color as a first-class panel.)
        // The first four + Stroke + Effects are the Phase 1 full-kit overhaul
        // additions (2026-07-19) — emitted only when a contract declares the
        // matching new `supports.*` group, so the 8 pre-overhaul blocks (which
        // don't) see an unchanged panel list.
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
                'controls' => $this->typographyPanelRows($supports),
            ],
            'size' => [
                'title' => 'Size',
                'controls' => $this->sizeRows($supports),
            ],
            'color' => [
                'title' => 'Color',
                'controls' => $this->colorRows($supports),
            ],
            'margin' => [
                'title' => 'Margin',
                'controls' => $this->sideRows($supports, 'spacing', 'margin'),
                'menu' => 'sides',
            ],
            'padding' => [
                'title' => 'Padding',
                'controls' => $this->sideRows($supports, 'spacing', 'padding'),
                'menu' => 'sides',
            ],
            'border' => [
                'title' => 'Border',
                'controls' => $this->borderPanelRows($supports),
            ],
            'borderStroke' => [
                'title' => 'Stroke',
                'controls' => $this->borderStrokeRows($supports),
                'menu' => 'sides',
            ],
            'radius' => [
                'title' => 'Border radius',
                'controls' => $this->radiusRows($supports),
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

        // Full-kit overhaul 2026-07-19 (Phase 1) — fill/hug are boolean per
        // axis (no magnitude), rendered as toggles bound straight to their
        // supports path; SupportsStyle consumes them as utility classes
        // (hb-size-fill-w/h, hb-size-hug-w/h), not vars (see its docblock).
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
     * Color rows: text + background as color fields (typed value + swatch).
     * The registry's color tokens ride along as preset options.
     *
     * @return list<array<string, mixed>>
     */
    private function colorRows(array $supports): array
    {
        $color = $supports['color'] ?? null;
        if (! is_array($color)) {
            return [];
        }

        $controls = [];
        foreach (['text' => 'Text', 'background' => 'Background'] as $feature => $label) {
            if (($color[$feature] ?? false) !== true) {
                continue;
            }
            $controls[] = [
                'type' => 'color', 'sanitize' => 'color-value', 'tokenKind' => 'color',
                'source' => "supports.color.{$feature}",
                'label' => $label,
                'options' => $this->tokenOptions('color'),
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
                'options' => $this->tokenOptions($tokenKind),
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
    private function sideRows(array $supports, string $group, string $feature): array
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
    private function borderPanelRows(array $supports): array
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
                'options' => $this->tokenOptions('color'),
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
    private function radiusRows(array $supports): array
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
    private function typographyPanelRows(array $supports): array
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

        // Full-kit overhaul 2026-07-19 (Phase 1).
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
     * Alignment rows (design toolbar's align control, surfaced in the
     * inspector too): one `segmented` control listing exactly the block's
     * declared `supports.align` values. New control type — Phase 2 builds
     * the segmented widget.
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
            // New control type — a linked X/Y pair; Phase 2 builds the widget.
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
            // New control type — a 2D flex-alignment grid; Phase 2 builds the widget.
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
     * New control type — Phase 2 builds the shadow-layer widget.
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

    /**
     * Border → Stroke rows (design Border section's per-side variant): one
     * row per enabled side for width/color/style, only when the feature is
     * declared as a side-map (`{"top": true, …}`) rather than the existing
     * single-value `true` form (which stays on the `border` panel unchanged).
     *
     * @return list<array<string, mixed>>
     */
    private function borderStrokeRows(array $supports): array
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
                    'options' => $this->tokenOptions('color'),
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

        return $controls;
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
            'label' => $this->localizeLabel("heisenberg::supports.{$group}.{$snake}", $feature, $locale),
        ];

        foreach (['min', 'max', 'step'] as $k) {
            if (array_key_exists($k, $extra)) {
                $control[$k] = $extra[$k];
            }
        }

        if ($tokenKind !== null) {
            $control['tokenKind'] = $tokenKind;
            $control['options'] = $this->tokenOptions($tokenKind);
        }

        return $control;
    }

    /**
     * Design-token options for a supports token-select, read from the configured
     * value-to-label registry (`heisenberg.tokens.<kind>`). Numeric lists remain
     * supported for compatibility and receive humanized token-name labels.
     *
     * @return list<array{value: string, label: string}>
     */
    private function tokenOptions(string $kind): array
    {
        $tokens = function_exists('config') ? config("heisenberg.tokens.{$kind}", []) : [];
        if (! is_array($tokens)) {
            return [];
        }

        $options = [];
        foreach ($tokens as $value => $label) {
            if (is_int($value)) {
                $value = (string) $label;
                $name = preg_replace('/^var\(--|\)$/', '', $value);
                $label = Str::headline((string) $name);
            }

            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
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
                        'label' => $this->localize($opt['label'] ?? '', $locale),
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
            $key = $this->optionKey($value);

            return [
                'value' => $value,
                'label' => $this->localizeLabel("heisenberg::blocks.{$slug}.options.{$snake}.{$key}", (string) $value, $locale),
            ];
        }, array_values($enum));
    }

    /** Normalize an enum value to its lang key: `_self`→`self`, `wide-line`→`wide_line`. */
    private function optionKey(mixed $value): string
    {
        return Str::snake(str_replace('-', '_', ltrim((string) $value, '_')));
    }

    /** Block slug as used in lang keys (`heisenberg/section-head` → `section_head`). */
    private function langSlug(string $name): string
    {
        $slug = str_contains($name, '/') ? explode('/', $name, 2)[1] : $name;

        return str_replace('-', '_', $slug);
    }

    /** Localize a lang key; fall back to a humanized label when it doesn't resolve. */
    private function localizeLabel(string $key, string $fallbackSource, ?string $locale): string
    {
        $translated = __($key, [], $locale);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return Str::headline($fallbackSource);
    }

    private function localize(mixed $value, ?string $locale): mixed
    {
        if (is_string($value) && str_starts_with($value, self::TRANSLATION_NAMESPACE)) {
            return __($value, [], $locale);
        }

        return $value;
    }

    /** @return string[] sorted, distinct Lucide slugs referenced by the contracts */
    private function referencedIcons(array $blocks): array
    {
        $icons = [];
        foreach ($blocks as $contract) {
            if (isset($contract['icon']) && is_string($contract['icon'])) {
                $icons[] = $contract['icon'];
            }
            if (isset($contract['render']['template']) && is_array($contract['render']['template'])) {
                $this->collectLucide($contract['render']['template'], $icons);
            }
        }
        $icons = array_values(array_unique($icons));
        sort($icons);

        return $icons;
    }

    /** @param string[] $icons */
    private function collectLucide(array $node, array &$icons): void
    {
        $lucide = $node['attributes']['data-lucide'] ?? null;
        if (is_string($lucide) && preg_match('/^[a-z0-9-]+$/', $lucide) === 1) {
            $icons[] = $lucide;
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $this->collectLucide($child, $icons);
                }
            }
        }
    }

    private function rootPath(): string
    {
        if ($this->blockRootPath !== null) {
            return $this->blockRootPath;
        }

        $configured = function_exists('config') ? config('heisenberg.block_root') : null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return __DIR__ . '/../../resources/blocks';
    }
}
