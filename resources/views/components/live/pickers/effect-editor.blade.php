@props([
    'title' => null,
    'type' => 'drop-shadow',
    'color' => '#000000',
    'opacity' => '14',
    'blur' => '28',
    'x' => '0',
    'y' => '8',
])
@php $title ??= __('heisenberg::editor.effects.drop_shadow'); @endphp
<div class="hb-pop hb-shadow" data-hb-effect="{{ $type }}">
    <button type="button" class="hb-shadow__head" data-hb-effect-toggle>
        <b>{{ $title }}</b>
        @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 13])
    </button>
    <div class="hb-shadow__body">
        @if ($type === 'drop-shadow' || $type === 'inner-shadow')
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.color') }}</span>
                <div class="hb-shadow__color">
                    <span class="hb-shadow__sw" style="background: {{ $color }};" data-hb-fx-swatch></span>
                    <input type="text" class="hb-shadow__hex" value="{{ $color }}" data-hb-fx-color>
                </div>
                <span class="hb-shadow__box hb-shadow__box--fixed"><input type="text" value="{{ $opacity }}" data-hb-fx-opacity><span class="p">%</span></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.blur') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $blur }}" data-hb-fx-blur></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.offset') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $x }}" data-hb-fx-x></span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $y }}" data-hb-fx-y></span>
            </div>
        @else
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.blur') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $blur }}"></span>
            </div>
        @endif
    </div>
</div>
@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-shadow__body { display: flex; flex-direction: column; gap: 12px; }
    .hb-shadow[data-collapsed="true"] .hb-shadow__body { display: none; }
    .hb-shadow__head { border: 0; cursor: pointer; width: 100%; }
    .hb-shadow[data-collapsed="true"] .hb-shadow__head svg { transform: rotate(-90deg); }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-effect-toggle]').forEach((head) => {
            if (head.__hbFx) return; head.__hbFx = true;
            head.addEventListener('click', () => {
                const card = head.closest('[data-hb-effect]');
                card.dataset.collapsed = card.dataset.collapsed === 'true' ? 'false' : 'true';
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
