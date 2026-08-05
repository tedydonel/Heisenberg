{{-- ui/text-area — from Pencil Text Area (MewqO): 176x80 default, placeholder shown muted at
     fs-sm/1.4 line-height. Width/height are props — other panels reuse this same node at
     fill_container width and 56-64px heights, per their own descendant overrides. --}}
@once
<style>
    .hb-textarea {
        display: block;
        min-height: 80px;
        padding: 8px 10px;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        outline: 0;
        resize: none;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        line-height: 1.4;
        color: var(--hb-text-primary, #0A0A0A);
        transition: border-color .12s ease;
    }
    .hb-textarea::placeholder { color: var(--hb-text-muted, #9A9A9A); }
    .hb-textarea:hover:not(:disabled) { border-color: var(--hb-border-strong, #C8C8C8); }
    .hb-textarea:focus { border-color: var(--hb-border-focus, #000); }
    .hb-textarea:disabled { opacity: .5; }
    .hb-textarea--resizable { resize: vertical; }
</style>
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
