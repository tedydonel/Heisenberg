{{-- live/block/advanced — responsive-visibility toggles + the scroll-animation kit.
     Every animation option/range/default comes from AnimationCatalog — the same source
     the registry expands into the contract — so this panel cannot drift from the engine. --}}
@php
    $hbAnimShow = json_encode(['attribute' => 'animate', 'in' => \Heisenberg\Support\AnimationCatalog::keys()]);
@endphp
<div class="hb-blockadvanced">
    <x-ui.panel-section :title="__('heisenberg::editor.advanced.section_visibility')" collapsible>
        @foreach ([
            [['hide_xs', 'hideXs'], ['hide_sm', 'hideSm']],
            [['hide_md', 'hideMd'], ['hide_lg', 'hideLg']],
            [['hide_xl', 'hideXl'], ['hide_xxl', 'hideXxl']],
        ] as $pair)
            <div class="hb-irow">
                @foreach ($pair as $field)
                    <label class="hb-tglrow"><span class="hb-tglrow__l">{{ __('heisenberg::editor.advanced.' . $field[0]) }}</span><x-ui.toggle data-hb-control="{{ $field[1] }}" data-hb-control-kind="attributes" data-hb-control-type="toggle" /></label>
                @endforeach
            </div>
        @endforeach
    </x-ui.panel-section>

    <x-ui.panel-section :title="__('heisenberg::editor.advanced.section_animate')" collapsible>
        <div class="hb-icol">
            <span class="hb-ilbl">{{ __('heisenberg::editor.advanced.animation_type') }}</span>
            {{-- Searchable: the catalog is ~40 presets, so the static-mode combobox (typing
                 filters the full list itself) beats scanning a select menu. --}}
            <x-ui.combobox :static="true" value="" :options="\Heisenberg\Support\AnimationCatalog::options()" data-hb-control="animate" data-hb-control-kind="attributes" data-hb-control-type="combobox" />
        </div>
        <div class="hb-icol" data-hb-showwhen="{{ $hbAnimShow }}">
            <div class="hb-irow" style="justify-content:space-between;"><span class="hb-ilbl">{{ __('heisenberg::editor.advanced.duration') }}</span><span class="hb-tglrow__l"><span data-hb-range-readout>{{ \Heisenberg\Support\AnimationCatalog::DEFAULT_DURATION }}</span> ms</span></div>
            <x-ui.slider :value="\Heisenberg\Support\AnimationCatalog::DEFAULT_DURATION" :min="100" :max="3000" :step="50" data-hb-control="animateDuration" data-hb-control-kind="attributes" data-hb-control-type="range" />
        </div>
        <div class="hb-icol" data-hb-showwhen="{{ $hbAnimShow }}">
            <div class="hb-irow" style="justify-content:space-between;"><span class="hb-ilbl">{{ __('heisenberg::editor.advanced.delay') }}</span><span class="hb-tglrow__l"><span data-hb-range-readout>{{ \Heisenberg\Support\AnimationCatalog::DEFAULT_DELAY }}</span> ms</span></div>
            <x-ui.slider :value="\Heisenberg\Support\AnimationCatalog::DEFAULT_DELAY" :min="0" :max="3000" :step="50" data-hb-control="animateDelay" data-hb-control-kind="attributes" data-hb-control-type="range" />
        </div>
        <div class="hb-icol" data-hb-showwhen="{{ $hbAnimShow }}">
            <span class="hb-ilbl">{{ __('heisenberg::editor.advanced.easing') }}</span>
            <x-ui.select :value="\Heisenberg\Support\AnimationCatalog::DEFAULT_EASING" :options="\Heisenberg\Support\AnimationCatalog::easingOptions()" data-hb-control="animateEasing" data-hb-control-kind="attributes" data-hb-control-type="select" />
        </div>
        <label class="hb-tglrow" data-hb-showwhen="{{ $hbAnimShow }}"><span class="hb-tglrow__l">{{ __('heisenberg::editor.advanced.play_once') }}</span><x-ui.toggle :on="true" data-hb-control="animateOnce" data-hb-control-kind="attributes" data-hb-control-type="toggle" /></label>
        <x-ui.button variant="secondary" leading-icon="play" data-hb-anim-preview data-hb-showwhen="{{ $hbAnimShow }}">{{ __('heisenberg::editor.advanced.play_animation') }}</x-ui.button>
    </x-ui.panel-section>
</div>
@once
<style>
    .hb-tglrow { display: flex; align-items: center; justify-content: space-between; gap: var(--hb-space-2, 8px); cursor: pointer; }
    .hb-tglrow__l { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12px; color: var(--hb-text-secondary, #5A5A5A); }
    /* The animation-preset menu lists the whole catalog — cap it and scroll. */
    .hb-blockadvanced .hb-select__menu { max-height: 260px; overflow-y: auto; }
</style>
<script>
    (() => {
        if (document.__hbAnimPreview) return;
        document.__hbAnimPreview = true;
        // Play the selected block's configured entrance in place: restart the keyframes by
        // re-adding .hb-anim-play (the class AnimationCatalog's stylesheet keys on), then
        // drop it after duration+delay so attention presets don't loop forever in the editor.
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-hb-anim-preview]');
            if (!btn || !window.hbEditor) return;
            const id = window.hbEditor.getSelectedId();
            if (!id) return;
            const model = window.hbEditor.getModel(id);
            const preset = model && model.attributes ? model.attributes.animate : '';
            if (!preset) return;
            const root = document.querySelector('.hb-blk[data-block="' + id + '"] [data-block-id]');
            if (!root) return;
            root.classList.remove('hb-anim-play');
            void root.offsetWidth; // reflow so re-adding the class restarts from frame 0
            root.classList.add('hb-anim-play');
            const duration = Number(model.attributes.animateDuration) || 600;
            const delay = Number(model.attributes.animateDelay) || 0;
            clearTimeout(root.__hbAnimPreviewT);
            root.__hbAnimPreviewT = setTimeout(() => root.classList.remove('hb-anim-play'), duration + delay + 80);
        });
    })();
</script>
@endonce
