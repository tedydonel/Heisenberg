@php
    $size = is_numeric($size ?? null) ? (int) $size : 16;
    $svg = \Heisenberg\Editor\EditorIcon::svg((string) ($name ?? ''), $size);
@endphp
<span class="hb-icon" data-icon-name="{{ $name }}" style="display:inline-flex;align-items:center;justify-content:center;line-height:0;color:currentColor;">
    {!! $svg !!}
</span>
