<div class="hb-pop hb-moremenu" data-hb-moremenu>
    <div class="hb-varmenu__list">
        <button type="button" class="hb-vmi" data-more-action="duplicate">
            <span class="hb-vmi__l">
                <span class="hb-vmi__ic">@include('heisenberg::components.ui.icon', ['name' => 'copy', 'size' => 16])</span>
                <span class="hb-vmi__name">{{ __('heisenberg::editor.block_toolbar.duplicate') }}</span>
            </span>
        </button>
        <button type="button" class="hb-vmi hb-vmi--danger" data-more-action="delete">
            <span class="hb-vmi__l">
                <span class="hb-vmi__ic">@include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 16])</span>
                <span class="hb-vmi__name">{{ __('heisenberg::editor.block_toolbar.delete') }}</span>
            </span>
        </button>
    </div>
</div>
