{{-- live/block/style — the Block Style composition, gated on the selected block's contract.

     2026-08-05: this component accepted a `$supports` prop and never read it, so every block type
     rendered all ten sections regardless of what it declared — Position, Flex Layout and Effects
     included, none of which either shipped contract supports. Those sections wrote into the model
     and rendered nothing, which is indistinguishable from a working control until you reload.

     This reverses tests/Editor/EditorRendersTest.php's earlier
     `test_style_panel_keeps_the_complete_pencil_section_stack_mounted`, which pinned the opposite
     rule ("mounting a selected block must not remove designed sections"). That rule predates the
     decision that the inspector loads only what the active block needs; the .pen composition is
     the design's full vocabulary, not a per-block contract.

     GATING RULE — deliberately identical to live/toolbar/block-toolbar.blade.php's `$has`, so the
     two surfaces cannot drift: a group counts as supported when the key is present and not `false`.
     An empty array/object still counts (a contract that declares the group but no members has opted
     in). Gating here is render-time only, which is sufficient: the inspector pre-renders ONE panel
     per registered block type and shows/hides by name on selection (see inspector.blade.php), so
     each panel is already built against exactly one contract. The toolbar needs a second JS layer
     only because a single toolbar element is shared across every block.

     Two mappings are NOT what their section titles suggest — see docs/inspector-composition.md:
       - Dimensions gates on `size`, not `dimensions`. Both are legal SUPPORT_KEYS, but the
         section's controls write size.width/size.height and no shipped contract declares a
         `dimensions` group at all. Gating on the title's key would hide a section both blocks
         fully support.
       - Appearance spans TWO groups: opacity writes appearance.opacity, the four corner fields
         write border.radius.*. Neither contract declares `appearance`; both fully declare
         `border.radius`. A single-key gate is wrong either way, so the section shows when either
         group is present and the opacity field gates independently on `appearance`. --}}
@props(['supports' => [], 'state' => 0, 'themeTokens' => []])
@php
    use Illuminate\Support\Arr;

    $has = fn (string $key): bool => Arr::get($supports, $key, null) !== null
        && Arr::get($supports, $key) !== false;

    // Typography gates per CONTROL as well as per section: heading declares lineHeight and
    // paragraph does not, and neither declares letterSpacing. Previously this map was hardcoded
    // to all-true, so paragraph offered a line-height field with no variable behind it.
    $typography = [
        'fontFamily' => $has('typography.fontFamily'),
        'fontWeight' => $has('typography.fontWeight'),
        'fontSize' => $has('typography.fontSize'),
        'lineHeight' => $has('typography.lineHeight'),
        'letterSpacing' => $has('typography.letterSpacing'),
        // Text placement inside the block (TODO 7.4) — not the standalone Alignment section,
        // which places the block itself within its parent.
        'textAlign' => $has('typography.textAlign'),
        'textAlignVertical' => $has('typography.textAlignVertical'),
    ];

    $showFill = $has('color');
    $showStroke = $has('border');
    $showEffects = $has('effects');
    // Corner radius lives under border; opacity under appearance. Either one earns the section.
    $showAppearance = $showStroke || $has('appearance');
@endphp
<div {{ $attributes->merge(['class' => 'hb-blockstyle']) }}>
    {{-- State is per-INSTANCE, not contract-declared: BlockRenderer::stateStylesCss() reads
         `supports.states` off the block instance, and `states` is deliberately absent from
         BlockContractValidator::SUPPORT_KEYS, so no contract can declare it. Always mounted. --}}
    <x-ui.panel-section title="State">
        <x-ui.tabs data-hb-style-state :active-index="0" :items="[
            ['value' => 'default', 'label' => 'Default'],
            ['value' => 'hover', 'label' => 'Hover'],
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'focus', 'label' => 'Focus'],
        ]" />
    </x-ui.panel-section>

    {{-- `align` is special-cased in the contract schema: a top-level array of allowed values,
         not a member of SUPPORT_KEYS, and BlockRenderer::resolveClass() consumes it directly
         without a style variable. An empty array means "no alignments", hence the count check. --}}
    @if (is_array($supports['align'] ?? null) && count($supports['align']) > 0)
        <x-live.block.style.alignment />
    @endif

    @if ($has('typography'))
        <x-live.block.style.typography :typography="$typography" />
    @endif

    @if ($has('position'))
        <x-live.block.style.position />
    @endif

    @if ($has('layout'))
        <x-live.block.style.flex-layout />
    @endif

    @if ($has('spacing'))
        <x-live.block.style.spacing />
    @endif

    @if ($has('size'))
        <x-live.block.style.dimensions />
    @endif

    @if ($showAppearance)
        <x-live.block.style.appearance :show-opacity="$has('appearance')" :show-corners="$showStroke" />
    @endif

    @if ($showFill)
        <x-live.block.style.fill />
    @endif

    @if ($showStroke)
        <x-live.block.style.stroke />
    @endif

    @if ($showEffects)
        <x-live.block.style.effects />
    @endif

    {{-- Popups follow their triggers: the colour picker is opened by Fill and Stroke's layer rows
         and by Appearance's swatches; the effect editor only by Effects. Mounting a popup whose
         only trigger is gated away would ship an unreachable colour picker into every panel. --}}
    @if ($showFill || $showStroke || $showAppearance)
        <div class="hb-style-popup" data-hb-style-popup="color" hidden>
            <x-live.pickers.color-picker value="#000000" />
        </div>
    @endif
    {{-- Padding/Margin's own mode-switcher popups are inlined inside live/block/style/spacing.blade.php
         itself now (2026-08-04) — nothing padding/margin-specific is registered from here anymore. --}}
    @if ($showEffects)
        <div class="hb-style-popup" data-hb-style-popup="effect" hidden>
            <x-live.pickers.effect-editor />
        </div>
    @endif

    {{-- Theme-variable pickers (TODO 7.6). live/pickers/variable-menu existed but was mounted
         ONLY in the components gallery, never in the inspector — and its token list was a
         hardcoded array in the component, so wiring it without passing real tokens would have
         offered names that do not exist. These are fed ThemeRepository::tokens(), which merges
         the user's theme over the config registry and had no callers at all.

         Two modes because the menu shows a swatch for colours and a raw value for everything
         else. Both are mounted once per panel and repositioned at whichever field opened them. --}}
    <div class="hb-style-popup" data-hb-style-popup="var-color" hidden>
        <x-live.pickers.variable-menu mode="color" selected="" :tokens="$themeTokens['color'] ?? null" />
    </div>
    <div class="hb-style-popup" data-hb-style-popup="var-number" hidden>
        <x-live.pickers.variable-menu mode="number" selected="" :tokens="$themeTokens['space'] ?? null" />
    </div>
    {{-- Font families need their own menu: routing them to var-number would offer spacing tokens.
         Its first row is the empty "Default" entry ThemeRepository::tokens() always prepends, so
         picking it clears the field — which is what the `x` button this trigger replaced did. --}}
    <div class="hb-style-popup" data-hb-style-popup="var-font" hidden>
        <x-live.pickers.variable-menu mode="number" selected="" :tokens="$themeTokens['fontFamily'] ?? null" />
    </div>

    {{-- Trigger prototype, cloned into every Block.style text field's right edge (TODO 7.7).
         Injected from script rather than added to each of the eight style/*.blade.php files:
         the requirement is "only for the Block.style sub-tab", and one decorator over
         [data-hb-control] inside .hb-blockstyle expresses exactly that scope — adding a prop to
         ui/field would put the affordance on every field in the editor, Post tab included. --}}
    <span data-hb-style-var-prototype hidden aria-hidden="true">
        <button type="button" class="hb-varbtn" data-hb-style-var-trigger aria-expanded="false" aria-label="{{ __('heisenberg::editor.inspector.bind_theme_variable') }}">
            @include('heisenberg::components.ui.icon', ['name' => 'selection-all-fill', 'size' => 14])
        </button>
    </span>
</div>
