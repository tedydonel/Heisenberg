{{-- live/media/media-card — one component; the `state` prop selects the variant
     (default/hover/selected/uploading/error). 200px vertical card: thumbnail (190px) + name +
     (meta | progress | status row). Styles in 30-media.css (shared with the Livewire library's
     optimistic uploading cards). --}}
@props([
    'state' => 'default',
    'name' => 'Filename.png',
    'meta' => '0 KB • Jan 1, 2026',
    'thumb' => null,
    'progress' => 0,
    'status' => null,
])
@php
    $selectable = in_array($state, ['default', 'hover', 'selected'], true);
    $classes = 'hb-mediacard';
    if ($selectable) $classes .= ' hb-mediacard--selectable';
    if (in_array($state, ['hover', 'selected', 'uploading', 'error'], true)) $classes .= ' hb-mediacard--' . $state;
    $pct = max(0, min(100, (int) $progress));
@endphp
<div {{ $attributes->merge(['class' => $classes]) }} @if ($state === 'selected') aria-selected="true" @endif>
    <div class="hb-mediacard__thumb">
        @if ($state === 'uploading')
            <span class="hb-mediacard__icon hb-mediacard__spin" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'spinner-gap', 'size' => 32])</span>
        @elseif ($state === 'error')
            <span class="hb-mediacard__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'warning-circle', 'size' => 32])</span>
        @else
            @if ($thumb)<img class="hb-mediacard__img" src="{{ $thumb }}" alt="{{ $name }}">@endif
            @if ($selectable)
                <span class="hb-mediacard__badge" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'check-bold', 'size' => 12])</span>
            @endif
        @endif
    </div>
    <div class="hb-mediacard__name">{{ $name }}</div>
    @if ($state === 'uploading')
        <div class="hb-mediacard__progress">
            <div class="hb-mediacard__track"><div class="hb-mediacard__fill" style="width: {{ $pct }}%;"></div></div>
            <div class="hb-mediacard__status">{{ $status ?? ('Uploading… ' . $pct . '%') }}</div>
        </div>
    @elseif ($state === 'error')
        <div class="hb-mediacard__statusrow">
            <span class="hb-mediacard__err">{{ $status ?? 'Upload failed' }}</span>
            <button type="button" class="hb-mediacard__retry">Retry</button>
        </div>
    @else
        <div class="hb-mediacard__meta">{{ $meta }}</div>
    @endif
</div>
