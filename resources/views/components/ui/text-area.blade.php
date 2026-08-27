@once
<style>
    .hb-textarea-wrap { display: flex; align-items: stretch; gap: 4px; position: relative; }
    .hb-textarea {
        resize: none;
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .hb-textarea--resizable { resize: vertical; }
    .hb-textarea::-webkit-scrollbar { width: 0; height: 0; display: none; }
</style>
@endonce

@props([
    'value' => '',
    'placeholder' => 'Enter description…',
    'disabled' => false,
    'resizable' => true,
    'rows' => 3,
    'width' => '176px',
    'height' => '80px',
])
<div class="hb-textarea-wrap">
    <textarea
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        @if ($disabled) disabled @endif
        {{ $attributes->merge(['class' => 'hb-textarea' . ($resizable ? ' hb-textarea--resizable' : ''), 'style' => "flex:0 0 {$width};min-height:{$height};"]) }}
    >{{ $value }}</textarea>
    <x-heisenberg::ui.custom-scrollbar container=".hb-textarea" axis="y" :smooth="false" />
</div>
