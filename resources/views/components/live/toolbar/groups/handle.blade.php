@props(['blockType' => 'Text'])
@php
    $hbTbIcons = app(\Heisenberg\Services\BlockRegistryService::class)->registry()['blocks'] ?? [];
    $hbTbFillIcon = function (string $icon): string {
        $base = \Heisenberg\Editor\EditorIcon::resolveSlug($icon);
        if ($base === null) return 'text-t';
        return \Heisenberg\Editor\EditorIcon::svg($base . '-fill') !== \Heisenberg\Editor\EditorIcon::svg($base)
            ? $base . '-fill'
            : $base;
    };
@endphp
<div class="hb-tb__group hb-tb__group--handle">
    <button type="button" class="hb-tb__btn hb-tb__btn--drag" data-tb-action="drag" aria-label="Drag">@include('heisenberg::components.ui.icon', ['name' => 'dots-six-vertical', 'size' => 16])</button>
    <button type="button" class="hb-tb__btn" data-tb-action="select-parent" aria-label="Select parent">@include('heisenberg::components.ui.icon', ['name' => 'arrow-elbow-left-up', 'size' => 20])</button>
    <button type="button" class="hb-tb__btn" data-tb-action="move-up" aria-label="Move up">@include('heisenberg::components.ui.icon', ['name' => 'arrow-up', 'size' => 20])</button>
    <button type="button" class="hb-tb__btn" data-tb-action="move-down" aria-label="Move down">@include('heisenberg::components.ui.icon', ['name' => 'arrow-down', 'size' => 20])</button>
    <button type="button" class="hb-tb__pill hb-tb__pill--type" data-tb-popover="type" aria-haspopup="true" aria-expanded="false" aria-label="Block type: {{ $blockType }}">
        <span class="hb-tb__tic" aria-hidden="true" data-tb-type-icon-default>@include('heisenberg::components.ui.icon', ['name' => 'text-t-fill', 'size' => 14])</span>
        @foreach ($hbTbIcons as $hbTbContract)
            <span class="hb-tb__tic" aria-hidden="true" data-tb-type-icon="{{ $hbTbContract['name'] ?? '' }}" hidden>@include('heisenberg::components.ui.icon', ['name' => $hbTbFillIcon((string) ($hbTbContract['icon'] ?? '')), 'size' => 14])</span>
        @endforeach
    </button>
</div>
