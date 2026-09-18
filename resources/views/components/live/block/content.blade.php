@props([
    'blockTitle' => 'Block',
    'settings' => [],
    'classes' => [],
])
<div class="hb-blockcontent">
    <x-heisenberg::ui.panel-section :title="$blockTitle" collapsible>
        @foreach ($settings as $field)
            <div class="hb-icol">
                <span class="hb-ilbl">{{ $field['label'] ?? '' }}</span>
                @if (($field['type'] ?? 'text') === 'select')
                    <x-heisenberg::ui.select :options="$field['options'] ?? []" :value="$field['value'] ?? ''"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="select" />
                @elseif (($field['type'] ?? 'text') === 'textarea')
                    <x-heisenberg::ui.text-area :value="$field['value'] ?? ''" width="100%"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="text" />
                @elseif (($field['type'] ?? 'text') === 'number')
                    <x-heisenberg::ui.number-stepper :value="$field['value'] ?? 0"
                        :min="$field['min'] ?? null" :max="$field['max'] ?? null" :step="$field['step'] ?? 1"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="number" />
                @elseif (($field['type'] ?? 'text') === 'media')
                    {{-- Media-attribute control (e.g. the image block's `url`): a preview + Choose button that opens the media library dialog, exactly like the Post tab's Featured Image field. The value itself is written through the same setAttribute path as every other control — see media-field-behavior. --}}
                    <div class="hb-mediafield" data-hb-media-field data-hb-media-attribute="{{ $field['key'] ?? '' }}">
                        <button type="button" class="hb-mediafield__trigger" data-hb-media-trigger aria-haspopup="dialog">
                            <span class="hb-mediafield__icon" aria-hidden="true">
                                @include('heisenberg::components.ui.icon', ['name' => 'image', 'size' => 28])
                            </span>
                            <span class="hb-mediafield__label">{{ __('heisenberg::editor.media.choose_image') }}</span>
                        </button>
                        <div class="hb-mediafield__preview" data-hb-media-preview hidden title="{{ __('heisenberg::editor.media.replace_image') }}">
                            <img class="hb-mediafield__img" data-hb-media-img alt="">
                            <button type="button" class="hb-mediafield__remove" data-hb-media-remove aria-label="{{ __('heisenberg::editor.media.remove_image') }}" hidden>
                                @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 14])
                            </button>
                        </div>
                        @php
                            $hbMediaSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
                            $hbMediaUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
                        @endphp
                        <x-heisenberg::live.media.media-dialog
                            data-hb-media-dialog
                            hidden
                            :scrim="true"
                            tab="library"
                            accept="image/*"
                            :title="__('heisenberg::editor.media.select_image')"
                            :select-url="$hbMediaSelectUrl"
                            :upload-url="$hbMediaUploadUrl"
                        />
                    </div>
                @else
                    <x-heisenberg::ui.input :value="$field['value'] ?? ''"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="{{ $field['type'] ?? 'text' }}" />
                @endif
            </div>
        @endforeach
    </x-heisenberg::ui.panel-section>

    <x-heisenberg::ui.panel-section title="General" collapsible>
        <div class="hb-irow hb-irow--top">
            <div class="hb-icol">
                <span class="hb-ilbl">Anchor</span>
                <x-heisenberg::ui.input value="" placeholder="section-anchor"
                    data-hb-control="anchor" data-hb-control-kind="attributes" data-hb-control-type="text" />
                <span class="hb-ihint">Links and the table of contents jump to this anchor.</span>
                <span class="hb-ihint hb-ihint--warning" data-hb-anchor-warning hidden>Another block already uses this anchor.</span>
            </div>
            <div class="hb-icol">
                <span class="hb-ilbl">Title</span>
                <x-heisenberg::ui.input value="" placeholder="Tooltip text"
                    data-hb-control="titleAttr" data-hb-control-kind="attributes" data-hb-control-type="text" />
            </div>
        </div>
                <div class="hb-icol">
            <span class="hb-ilbl">Class</span>
            <div class="hb-classchips" data-hb-control="extraClasses" data-hb-control-kind="attributes" data-hb-control-type="chips">
                <div class="hb-chips" data-hb-chip-list>
                    @foreach ($classes as $cls)
                        <x-heisenberg::ui.chip :label="$cls" />
                    @endforeach
                </div>
                <input type="text" class="hb-classchips__input" placeholder="Add class…" aria-label="Add class" data-hb-chip-input>
            </div>
                        <span data-hb-chip-prototype hidden aria-hidden="true"><x-heisenberg::ui.chip label="" /></span>
        </div>
    </x-heisenberg::ui.panel-section>
</div>
@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .hb-chips:empty { display: none; }
    .hb-classchips { display: flex; flex-direction: column; gap: 6px; }
    .hb-classchips__input {
        width: 100%;
        height: 30px;
        box-sizing: border-box;
        padding: 0 10px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary);
        transition: border-color .12s ease;
    }
    .hb-classchips__input:focus { outline: none; border-color: var(--hb-border-focus); }
    .hb-ihint {
        display: block;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 10px;
        line-height: 1.4;
        color: var(--hb-text-muted);
    }
    .hb-ihint--warning { color: var(--hb-danger); }
    .hb-ihint[hidden] { display: none; }
    [data-hb-control="anchor"].hb-input--warning { border-color: var(--hb-danger); }
    .hb-mediafield { display: flex; flex-direction: column; gap: 6px; }
    .hb-mediafield__trigger {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
        width: 100%; padding: 14px 0; box-sizing: border-box;
        border: 1px dashed var(--hb-border-strong); border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg-subtle); color: var(--hb-text-muted); font: inherit; cursor: pointer;
    }
    .hb-mediafield__trigger:hover { color: var(--hb-text-primary); border-color: var(--hb-border-focus); }
    .hb-mediafield__trigger[hidden] { display: none; }
    .hb-mediafield__preview {
        position: relative; height: 94px; width: 100%;
        border-radius: var(--hb-radius-md, 5px); overflow: hidden;
        background: var(--hb-bg-subtle); border: 1px solid var(--hb-border-strong);
    }
    .hb-mediafield__preview[hidden] { display: none; }
    .hb-mediafield__img { width: 100%; height: 100%; object-fit: cover; display: block; cursor: pointer; }
    .hb-mediafield__remove {
        position: absolute; top: 6px; right: 6px;
        display: inline-flex; align-items: center; justify-content: center;
        width: 24px; height: 24px; border: 0; border-radius: 4px;
        background: rgba(10, 10, 10, .55); color: #fff; cursor: pointer;
    }
    .hb-mediafield__remove:hover { background: var(--hb-danger); }
    .hb-mediafield__remove[hidden] { display: none; }
</style>
@endonce
@include('heisenberg::components.live.inspector.media-field-behavior')
