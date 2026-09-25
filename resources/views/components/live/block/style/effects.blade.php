@props(['effects' => []])
@php
    // The three effect types, each writing one support: drop shadows compose into effects.shadow
    // (box-shadow), layer blur into effects.filter (filter: blur() only), and background blur into
    // effects.backdrop (backdrop-filter). script-effects.blade.php reads this same catalog, so a
    // type is defined exactly once. Only the targets the block supports appear.
    $hbFxCatalog = [
        'drop-shadow' => ['label' => __('heisenberg::editor.effects.drop_shadow'), 'target' => 'shadow'],
        'layer-blur' => ['label' => __('heisenberg::editor.effects.layer_blur'), 'target' => 'filter', 'fn' => 'blur', 'unit' => 'px', 'amount' => 4, 'max' => 100],
        'background-blur' => ['label' => __('heisenberg::editor.effects.background_blur'), 'target' => 'backdrop', 'fn' => 'blur', 'unit' => 'px', 'amount' => 8, 'max' => 100],
    ];
    $hbFxCatalog = array_filter($hbFxCatalog, static fn (array $type): bool => ($effects[$type['target']] ?? false) === true);
@endphp
<x-heisenberg::ui.panel-section title="Effects">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="{{ __('heisenberg::editor.effects.add') }}" aria-haspopup="menu" aria-expanded="false" data-hb-fx-add data-hb-style-popup-trigger="effect-add">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
    <div data-hb-fx-list data-hb-fx-types="{{ json_encode($hbFxCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"></div>
    <template data-hb-fx-row-template>
        <div class="hb-effectrow" data-hb-fx-row>
            <div class="hb-fxlayer">
                <button type="button" class="hb-fxlayer__ic" aria-label="{{ __('heisenberg::editor.effects.edit') }}" aria-expanded="false" data-hb-style-effect-trigger><x-heisenberg::ui.icon name="pencil-simple" size="14" /></button>
                <span class="hb-fxlayer__name" data-hb-fx-name></span>
                <button type="button" class="hb-fxlayer__ic" aria-label="{{ __('heisenberg::editor.effects.toggle') }}" aria-pressed="true" data-hb-fx-visibility><x-heisenberg::ui.icon name="eye" size="14" /></button>
            </div>
            <button type="button" class="hb-fxlayer__rm" aria-label="{{ __('heisenberg::editor.effects.remove') }}" data-hb-fx-remove><x-heisenberg::ui.icon name="minus" size="14" /></button>
        </div>
    </template>
</x-heisenberg::ui.panel-section>

<div class="hb-style-popup" data-hb-style-popup="effect-add" hidden>
    <div class="hb-pop hb-fxmenu" role="menu" aria-label="{{ __('heisenberg::editor.effects.add') }}">
        @foreach ($hbFxCatalog as $hbType => $hbDef)
            <button type="button" class="hb-vmi hb-fxmenu__item" role="menuitem" data-hb-fx-add-type="{{ $hbType }}">
                <span class="hb-vmi__l"><span class="hb-vmi__name">{{ $hbDef['label'] }}</span></span>
            </button>
        @endforeach
    </div>
</div>
@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-fxmenu { display: flex; flex-direction: column; min-width: 180px; padding: 6px; }
    .hb-fxmenu__item { width: 100%; border: 0; background: none; text-align: left; cursor: pointer; }
    .hb-effectrow[data-hb-fx-hidden="true"] .hb-fxlayer__name { opacity: .45; }
</style>
@endonce
