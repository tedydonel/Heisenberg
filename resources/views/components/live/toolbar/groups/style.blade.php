{{-- live/toolbar/groups/style — text colour (supports.color), save-as-block (always), align
     (supports.align). Colour + align gate independently; save is always available. --}}
@props(['hasColor' => false, 'hasAlign' => false])
<div class="hb-tb__group">
    @if ($hasColor)
        <button type="button" class="hb-tb__btn hb-tb__color" data-tb-popover="color" aria-haspopup="true" aria-expanded="false" aria-label="Text colour"><b>A</b><i></i></button>
    @endif
    <button type="button" class="hb-tb__btn" data-tb-action="save" aria-label="Save as block">@include('heisenberg::components.ui.icon', ['name' => 'floppy-disk-back-fill', 'size' => 20])</button>
    @if ($hasAlign)
        <button type="button" class="hb-tb__align" data-tb-popover="align" aria-haspopup="true" aria-expanded="false" aria-label="Align">@include('heisenberg::components.ui.icon', ['name' => 'text-align-right-light', 'size' => 20])<span class="hb-tb__cv">@include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 10])</span></button>
    @endif
</div>
