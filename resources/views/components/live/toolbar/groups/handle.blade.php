{{-- live/toolbar/groups/handle — always present: drag, select-parent, move up/down, and the
     block-type switcher pill (opens the type dropdown). --}}
@props(['blockType' => 'Text'])
<div class="hb-tb__group hb-tb__group--handle">
    <button type="button" class="hb-tb__btn hb-tb__btn--drag" data-tb-action="drag" aria-label="Drag">@include('heisenberg::components.ui.icon', ['name' => 'dots-six-vertical', 'size' => 16])</button>
    <button type="button" class="hb-tb__btn" data-tb-action="select-parent" aria-label="Select parent">@include('heisenberg::components.ui.icon', ['name' => 'arrow-elbow-left-up', 'size' => 20])</button>
    <button type="button" class="hb-tb__btn" data-tb-action="move-up" aria-label="Move up">@include('heisenberg::components.ui.icon', ['name' => 'arrow-up', 'size' => 20])</button>
    <button type="button" class="hb-tb__btn" data-tb-action="move-down" aria-label="Move down">@include('heisenberg::components.ui.icon', ['name' => 'arrow-down', 'size' => 20])</button>
    <button type="button" class="hb-tb__pill hb-tb__pill--type" data-tb-popover="type" aria-haspopup="true" aria-expanded="false" aria-label="Block type: {{ $blockType }}">@include('heisenberg::components.ui.icon', ['name' => 'text-t', 'size' => 14])<span class="hb-tb__cv">@include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 12])</span></button>
</div>
