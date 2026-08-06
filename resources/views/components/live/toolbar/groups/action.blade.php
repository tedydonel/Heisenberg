{{-- live/toolbar/groups/action — the more (⋯) menu. The AI-assist trigger lives here
     when the AI backend lands; it was removed rather than shipped dead (a visible,
     clickable button that opened nothing). --}}
@props(['richText' => true])
<div class="hb-tb__group">
    <button type="button" class="hb-tb__btn" data-tb-popover="more" aria-label="{{ __('heisenberg::editor.block_toolbar.more') }}">@include('heisenberg::components.ui.icon', ['name' => 'dots-three', 'size' => 20])</button>
</div>
