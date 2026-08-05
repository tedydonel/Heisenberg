{{-- live/toolbar/groups/format — inline formatting (rich-text blocks only): bold / italic /
     underline / strikethrough / link / code. Each toggles its active state on click. --}}
@props(['active' => []])
@php $fmts = [['bold', 'text-bolder-light', 'Bold'], ['italic', 'text-italic', 'Italic'], ['underline', 'text-underline', 'Underline'], ['strikethrough', 'text-strikethrough', 'Strikethrough']]; @endphp
<div class="hb-tb__group" data-tb-group="format">
    @foreach ($fmts as [$key, $icon, $label])
        @php $on = in_array($key, $active, true); @endphp
        <button type="button" class="hb-tb__btn{{ $on ? ' hb-tb__btn--on' : '' }}" data-tb-format="{{ $key }}" aria-pressed="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}">@include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 20])</button>
    @endforeach
    <button type="button" class="hb-tb__btn" data-tb-popover="link" aria-haspopup="true" aria-expanded="false" aria-label="Link">@include('heisenberg::components.ui.icon', ['name' => 'link', 'size' => 20])</button>
    <button type="button" class="hb-tb__btn" data-tb-format="code" aria-pressed="false" aria-label="Code">@include('heisenberg::components.ui.icon', ['name' => 'code', 'size' => 20])</button>
</div>
