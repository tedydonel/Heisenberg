@props(['supports' => [], 'state' => 0, 'theme' => [], 'innerBlocks' => []])
@php
    use Illuminate\Support\Arr;

    $isContainer = (bool) ($innerBlocks['enabled'] ?? false);

        $stripUnit = static function (string $value): string {
        return (string) (preg_match('/^(-?\d*\.?\d+)/', $value, $m) ? $m[1] : '');
    };

    $hbVarMenu = static function (array $rows, string $display) use ($stripUnit): array {
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            $label = trim((string) ($row['label'] ?? '')) !== '' ? $row['label'] : $name;
            $rawValue = (string) ($row[$display] ?? '');
            $labels[$label] = $stripUnit($rawValue);
            $values[$label] = 'var(--' . \Heisenberg\Services\ThemeRepository::CSS_PREFIX . $name . ')';
        }

        return [$labels, $values];
    };

    [$hbColorTokens, $hbColorValues] = $hbVarMenu($theme['colors'] ?? [], 'value');
    [$hbSpaceTokens, $hbSpaceValues] = $hbVarMenu($theme['spaces'] ?? [], 'value');
    [$hbFontTokens, $hbFontValues] = $hbVarMenu($theme['fonts'] ?? [], 'family');
    $hbColorTokens = ['Default' => null] + $hbColorTokens;
    $hbSpaceTokens = ['Default' => ''] + $hbSpaceTokens;
    $hbFontTokens = ['Default' => ''] + $hbFontTokens;
    $hbColorValues['Default'] = '';
    $hbSpaceValues['Default'] = '';
    $hbFontValues['Default'] = '';

    $hbVarLabels = [];
    $hbVarValues = [];
    foreach ([
        [$hbColorValues, $hbColorTokens],
        [$hbSpaceValues, $hbSpaceTokens],
        [$hbFontValues, $hbFontTokens],
    ] as [$refs, $displays]) {
        foreach ($refs as $label => $ref) {
            if (is_string($ref) && $ref !== '') {
                $hbVarLabels[$ref] = $label;
                $hbVarValues[$ref] = (string) ($displays[$label] ?? '');
            }
        }
    }

    $has = fn (string $key): bool => Arr::get($supports, $key, null) !== null
        && Arr::get($supports, $key) !== false;

    $typography = [
        'fontFamily' => $has('typography.fontFamily'),
        'fontWeight' => $has('typography.fontWeight'),
        'fontSize' => $has('typography.fontSize'),
        'lineHeight' => $has('typography.lineHeight'),
        'letterSpacing' => $has('typography.letterSpacing'),
        'textAlign' => $has('typography.textAlign'),
        'textAlignVertical' => $has('typography.textAlignVertical'),
    ];

    $showFill = $has('color');
    $showStroke = $has('border');
    $hbFillPath = ($isContainer && ($supports['color']['background'] ?? false) === true)
        ? 'color.background'
        : 'color.text';
    $showEffects = $has('effects');
    $showAppearance = $showStroke || $has('appearance');
@endphp
<div data-hb-var-labels="{{ json_encode($hbVarLabels, JSON_UNESCAPED_SLASHES) }}" data-hb-var-values="{{ json_encode($hbVarValues, JSON_UNESCAPED_SLASHES) }}" {{ $attributes->merge(['class' => 'hb-blockstyle']) }}>
    <x-ui.panel-section title="State">
        <x-ui.tabs data-hb-style-state :active-index="0" :items="[
            ['value' => 'default', 'label' => 'Default'],
            ['value' => 'hover', 'label' => 'Hover'],
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'focus', 'label' => 'Focus'],
        ]" />
    </x-ui.panel-section>

    @if (is_array($supports['align'] ?? null) && count($supports['align']) > 0)
        <x-live.block.style.alignment :values="$supports['align']" />
    @endif

    @if ($has('typography'))
        <x-live.block.style.typography :typography="$typography" />
    @endif

    @if ($has('position'))
        <x-live.block.style.position />
    @endif

    @if ($isContainer && $has('layout'))
        <x-live.block.style.flex-layout :layout="is_array($supports['layout'] ?? null) ? $supports['layout'] : []" />
    @endif

    @if ($has('spacing'))
        <x-live.block.style.spacing :spacing="is_array($supports['spacing'] ?? null) ? $supports['spacing'] : []" />
    @endif

    @if ($has('size'))
        <x-live.block.style.dimensions :size="is_array($supports['size'] ?? null) ? $supports['size'] : []" />
    @endif

    @if ($showAppearance)
        <x-live.block.style.appearance :show-opacity="$has('appearance')" :show-corners="$showStroke" />
    @endif

    @if ($showFill)
        <x-live.block.style.fill :path="$hbFillPath" />
    @endif

    @if ($showStroke)
        <x-live.block.style.stroke />
    @endif

    @if ($showEffects)
        <x-live.block.style.effects />
    @endif

    @if ($showFill || $showStroke || $showAppearance)
        <div class="hb-style-popup" data-hb-style-popup="color" hidden>
            <x-live.pickers.color-picker value="#000000" />
        </div>
        <div class="hb-style-popup" data-hb-style-popup="gradient-stop" hidden>
            <x-live.pickers.color-picker value="#000000" :standalone="true" />
        </div>
    @endif
    @if ($showEffects)
        <div class="hb-style-popup" data-hb-style-popup="effect" hidden>
            <x-live.pickers.effect-editor />
        </div>
    @endif

    <div class="hb-style-popup" data-hb-style-popup="var-color" hidden>
        <x-live.pickers.variable-menu mode="color" selected="" :tokens="$hbColorTokens" :values="$hbColorValues" />
    </div>
    <div class="hb-style-popup" data-hb-style-popup="var-number" hidden>
        <x-live.pickers.variable-menu mode="number" selected="" :tokens="$hbSpaceTokens" :values="$hbSpaceValues" />
    </div>
    <div class="hb-style-popup" data-hb-style-popup="var-font" hidden>
        <x-live.pickers.variable-menu mode="number" selected="" :tokens="$hbFontTokens" :values="$hbFontValues" />
    </div>

    <span data-hb-style-var-prototype hidden aria-hidden="true">
        <button type="button" class="hb-varbtn" data-hb-style-var-trigger aria-expanded="false" aria-label="{{ __('heisenberg::editor.inspector.bind_theme_variable') }}">
            @include('heisenberg::components.ui.icon', ['name' => 'selection-all-fill', 'size' => 14])
        </button>
    </span>
</div>
