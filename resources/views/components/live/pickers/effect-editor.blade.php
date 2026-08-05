{{-- live/pickers/effect-editor — local Drop Shadow editor popover. --}}
@props([
    'title' => 'Drop Shadow',
    'type' => 'drop-shadow',
    'color' => '#000000',
    'opacity' => '14',
    'blur' => '28',
    'x' => '0',
    'y' => '8',
])
<div class="hb-pop hb-shadow" data-hb-effect="{{ $type }}">
    <button type="button" class="hb-shadow__head" data-hb-effect-toggle>
        <b>{{ $title }}</b>
        @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 13])
    </button>
    <div class="hb-shadow__body">
        @if ($type === 'drop-shadow' || $type === 'inner-shadow')
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">Color</span>
                <div class="hb-shadow__color">
                    <span class="hb-shadow__sw" style="background: {{ $color }};"></span>
                    <input type="text" class="hb-shadow__hex" value="{{ $color }}">
                </div>
                <span class="hb-shadow__box hb-shadow__box--fixed"><input type="text" value="{{ $opacity }}"><span class="p">%</span></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">Blur</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $blur }}"></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">Offset</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $x }}"></span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $y }}"></span>
            </div>
        @else
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">Blur</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="{{ $blur }}"></span>
            </div>
        @endif
    </div>
</div>
@once
<style>
    .hb-shadow__body { display: flex; flex-direction: column; gap: 12px; }
    .hb-shadow[data-collapsed="true"] .hb-shadow__body { display: none; }
    .hb-shadow__head { border: 0; cursor: pointer; width: 100%; }
    .hb-shadow[data-collapsed="true"] .hb-shadow__head svg { transform: rotate(-90deg); }
</style>
<script>
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
