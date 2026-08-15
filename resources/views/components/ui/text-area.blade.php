{{-- ui/text-area — 176x80 default, placeholder shown muted at
     fs-sm/1.4 line-height. Width/height are props — other panels reuse this same node at
     fill_container width and 56-64px heights, per their own descendant overrides. --}}
@once

@endonce

@props([
    'value' => '',
    'placeholder' => 'Enter description…',
    'disabled' => false,
    'resizable' => false,
    'rows' => 3,
    'width' => '176px',
    'height' => '80px',
])
<textarea
    rows="{{ $rows }}"
    placeholder="{{ $placeholder }}"
    @if ($disabled) disabled @endif
    {{ $attributes->merge(['class' => 'hb-textarea' . ($resizable ? ' hb-textarea--resizable' : ''), 'style' => "width:{$width};min-height:{$height};"]) }}
>{{ $value }}</textarea>
