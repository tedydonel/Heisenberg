{{-- live/toolbar/groups/action — AI assist (rich-text blocks) + the more (⋯) menu (always). --}}
@props(['richText' => true])
<div class="hb-tb__group">
    @if ($richText)
        <button type="button" class="hb-tb__ai" data-tb-popover="ai" aria-label="AI">@include('heisenberg::components.ui.icon', ['name' => 'sparkle', 'size' => 14])<span>AI</span></button>
    @endif
    <button type="button" class="hb-tb__btn" data-tb-popover="more" aria-label="More">@include('heisenberg::components.ui.icon', ['name' => 'dots-three', 'size' => 20])</button>
</div>
