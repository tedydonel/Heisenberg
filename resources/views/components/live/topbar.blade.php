@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-topbar {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 32px;
        background: var(--hb-bg);
        border-bottom: 1px solid var(--hb-border);
    }
    .hb-topbar__zone { display: flex; align-items: center; gap: 2px; height: 100%; }
    .hb-topbar__zone--left { padding: 0 10px; }
    .hb-topbar__zone--center {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }
    .hb-topbar__zone--right { padding: 2px var(--hb-space-3, 12px); }
    .hb-topbar__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: transparent;
        color: var(--hb-text-muted);
        cursor: pointer;
    }
    .hb-topbar__btn:hover { background: var(--hb-surface-hover); color: var(--hb-text-secondary); }
    .hb-topbar__btn:focus-visible { outline: 2px solid var(--hb-border-focus); outline-offset: -2px; }
    .hb-topbar__btn:disabled { opacity: .35; cursor: default; pointer-events: none; }
    .hb-topbar__btn--sm { width: 26px; height: 26px; }
    .hb-topbar__icon { display: inline-flex; width: 14px; height: 14px; }
    .hb-topbar__icon--sm { width: 13px; height: 13px; }
    .hb-topbar__save {
        display: inline-flex;
        align-items: center;
        height: 100%;
        padding: var(--hb-space-1, 4px) var(--hb-space-3, 12px);
        border: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: var(--hb-accent);
        color: var(--hb-accent-fg);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        font-weight: 600;
        cursor: pointer;
    }
    .hb-topbar__save:hover { background: var(--hb-accent-hover); }
    .hb-topbar__save[aria-busy="true"] { opacity: .6; cursor: default; }
    .hb-topbar__devsel { position: relative; display: inline-flex; align-items: center; }
    .hb-topbar__device .hb-dev { display: none; }
    .hb-topbar__device[data-device="desktop"] .hb-dev--desktop,
    .hb-topbar__device[data-device="tablet"] .hb-dev--tablet,
    .hb-topbar__device[data-device="mobile"] .hb-dev--mobile { display: inline-flex; }
    .hb-topbar__device[data-device="tablet"], .hb-topbar__device[data-device="mobile"] { color: var(--hb-text-primary); }
    .hb-topbar__devsel-menu {
        position: absolute; top: calc(100% + 5px); right: 0; z-index: 60;
        width: max-content; padding: 4px;
        background: var(--hb-bg); border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px); box-shadow: var(--hb-shadow-lg, 3px 4px 4px rgba(0, 0, 0, .1));
        display: flex; flex-direction: column; gap: 2px;
    }
    .hb-topbar__devsel-menu[hidden] { display: none; }
    .hb-topbar__devsel-opt {
        display: inline-flex; align-items: center; gap: 8px; height: 28px; padding: 0 8px;
        border: 0; background: none; border-radius: var(--hb-radius-sm, 3px);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 400;
        color: var(--hb-text-secondary); text-align: left; cursor: pointer;
        white-space: nowrap;
    }
    .hb-topbar__devsel-opt > span { display: inline; }
    .hb-topbar__devsel-opt svg { width: 15px; height: 15px; flex: none; }
    .hb-topbar__devsel-opt:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-topbar__devsel-opt.is-on { background: var(--hb-surface-hover); color: var(--hb-text-primary); font-weight: 500; }
    .hb-topbar__exportsel { position: relative; display: inline-flex; align-items: center; }
    .hb-topbar__langsel { position: relative; display: inline-flex; align-items: center; }
    .hb-topbar__lang { width: auto; padding: 0 6px; gap: 5px; }
    .hb-topbar__lang-label {
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px);
        font-weight: 500; white-space: nowrap; max-width: 90px; overflow: hidden; text-overflow: ellipsis;
    }
    .hb-topbar__langsel-menu {
        position: absolute; top: calc(100% + 5px); right: 0; z-index: 60;
        width: max-content; min-width: 180px; padding: 4px;
        background: var(--hb-bg); border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px); box-shadow: var(--hb-shadow-lg, 3px 4px 4px rgba(0, 0, 0, .1));
        display: flex; flex-direction: column; gap: 2px;
    }
    .hb-topbar__langsel-menu[hidden] { display: none; }
    .hb-topbar__langsel-opt {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        min-height: 28px; padding: 4px 8px; width: 100%; cursor: pointer;
        border: 0; background: none; border-radius: var(--hb-radius-sm, 3px);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 400;
        color: var(--hb-text-secondary); text-align: left; white-space: nowrap;
    }
    .hb-topbar__langsel-opt:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-topbar__langsel-opt.is-on { color: var(--hb-text-primary); font-weight: 500; }
    .hb-topbar__langsel-opt__check { width: 12px; height: 12px; flex: none; color: var(--hb-accent); display: inline-flex; visibility: hidden; }
    .hb-topbar__langsel-opt.is-on .hb-topbar__langsel-opt__check { visibility: visible; }
</style>
@include('heisenberg::components.live.topbar.script')
@endonce

@props([
    'postId' => null,
    'contentVersion' => 0,
    'homeLocale' => 'en',
    'contentLocales' => ['en', 'fr'],
    'contentLocaleLabels' => [],
    'postTitleByLocale' => [],
    'documentType' => 'post',
    'emailPreviewUrlTemplate' => '',
    'emailExportUrlTemplate' => '',
])
@php
    $leftButtons = [
        ['icon' => 'house-fill', 'label' => __('heisenberg::editor.topbar.aria_home'), 'toggle' => null, 'tip' => 'aria_home'],
        null,
        ['icon' => 'list', 'label' => __('heisenberg::editor.topbar.aria_menu'), 'toggle' => 'sidebar', 'tip' => 'aria_menu'],
        ['icon' => 'sidebar-simple', 'label' => __('heisenberg::editor.topbar.aria_panel_left'), 'toggle' => 'panel', 'tip' => 'aria_panel_left'],
    ];
    $centerButtons = [
        ['icon' => 'arrow-counter-clockwise', 'label' => __('heisenberg::editor.topbar.aria_undo'), 'undo' => true, 'fullscreen' => false, 'layers' => false, 'preview' => false],
        ['icon' => 'arrow-clockwise', 'label' => __('heisenberg::editor.topbar.aria_redo'), 'redo' => true, 'fullscreen' => false, 'layers' => false, 'preview' => false],
        null,
        ['icon' => 'arrows-out', 'label' => __('heisenberg::editor.topbar.aria_fullscreen'), 'fullscreen' => true, 'layers' => false, 'preview' => false],
        ['icon' => 'stack', 'label' => __('heisenberg::editor.topbar.aria_layers'), 'fullscreen' => false, 'layers' => true, 'preview' => false],
    ];
    $rightButtons = [
        ['icon' => 'moon', 'label' => __('heisenberg::editor.topbar.aria_theme'), 'theme' => true],
        ['icon' => 'arrow-square-out', 'label' => __('heisenberg::editor.topbar.aria_preview'), 'theme' => false, 'preview' => true],
        ['icon' => 'translate', 'label' => __('heisenberg::editor.topbar.aria_post_language'), 'lang' => true],
        ['icon' => 'device-mobile', 'label' => __('heisenberg::editor.topbar.aria_device'), 'device' => true],
    ];
    if ($documentType === 'email') {
        array_splice($rightButtons, 2, 0, [[
            'icon' => 'download-simple', 'label' => __('heisenberg::editor.topbar.aria_email_export'), 'export' => true,
        ]]);
    }
    $hbCurrentLocale = $homeLocale;
    $hbCurrentLocaleLabel = $contentLocaleLabels[$hbCurrentLocale] ?? __('heisenberg::editor.locales.' . $hbCurrentLocale);
    $deviceLabels = [
        'desktop' => __('heisenberg::editor.topbar.device_desktop'),
        'tablet'  => __('heisenberg::editor.topbar.device_tablet'),
        'mobile'  => __('heisenberg::editor.topbar.device_mobile'),
    ];
    $devices = [
        'desktop' => ['desktop', $deviceLabels['desktop']],
        'tablet'  => ['device-tablet', $deviceLabels['tablet']],
        'mobile'  => ['device-mobile', $deviceLabels['mobile']],
    ];
@endphp
<div {{ $attributes->merge(['class' => 'hb-topbar']) }}
    data-hb-post-id="{{ $postId ?? '' }}"
    data-hb-content-version="{{ $contentVersion ?? 0 }}"
    data-hb-msg-conflict="{{ __('heisenberg::editor.topbar.save_conflict') }}"
    data-hb-msg-invalid="{{ __('heisenberg::editor.topbar.save_invalid') }}"
    data-hb-msg-network="{{ __('heisenberg::editor.topbar.save_network') }}"
    data-hb-save-url="{{ route('heisenberg.editor.posts.store') }}"
    data-hb-update-url-template="{{ route('heisenberg.editor.posts.store') }}/__ID__"
    data-hb-preview-store-url="{{ route('heisenberg.editor.preview.store') }}"
    data-hb-preview-show-url="{{ route('heisenberg.editor.preview') }}"
    data-hb-preview-post-url-template="{{ route('heisenberg.editor.index') }}/__ID__/preview"
    data-hb-document-type="{{ $documentType }}"
    data-hb-email-preview-url-template="{{ $emailPreviewUrlTemplate }}"
    data-hb-email-export-url-template="{{ $emailExportUrlTemplate }}"
    data-hb-locale-labels="{{ json_encode($contentLocaleLabels) }}"
    data-hb-title-by-locale="{{ json_encode($postTitleByLocale) }}"
    data-hb-editor-url-template="{{ $documentType === 'email'
        ? route('heisenberg.editor.email.show', ['post' => '__ID__'])
        : route('heisenberg.editor.show', ['post' => '__ID__']) }}"
>
    <div class="hb-topbar__zone hb-topbar__zone--left">
        @foreach ($leftButtons as $btn)
            @if (is_null($btn))
                <x-heisenberg::ui.divider orientation="vertical" style="width:1px;height:16px;" />
            @else
                <button
                    type="button"
                    class="hb-topbar__btn"
                    aria-label="{{ $btn['label'] }}"
                    @if ($btn['toggle'] ?? null) data-hb-toggle="{{ $btn['toggle'] }}" @endif
                >
                    <span class="hb-topbar__icon" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 14])
                    </span>
                </button>
            @endif
        @endforeach
    </div>

    <div class="hb-topbar__zone hb-topbar__zone--center">
        @foreach ($centerButtons as $btn)
            @if (is_null($btn))
                <x-heisenberg::ui.divider orientation="vertical" style="width:1px;height:16px;" />
            @else
                <button type="button" class="hb-topbar__btn" aria-label="{{ $btn['label'] }}"
                    @if ($btn['undo'] ?? false) data-hb-undo disabled @endif
                    @if ($btn['redo'] ?? false) data-hb-redo disabled @endif
                    @if ($btn['fullscreen'] ?? false) data-hb-fullscreen @endif
                    @if ($btn['layers'] ?? false) data-hb-layers @endif
                    @if ($btn['preview'] ?? false) data-hb-preview @endif
                >
                    <span class="hb-topbar__icon" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 14])
                    </span>
                </button>
            @endif
        @endforeach
    </div>

    <div class="hb-topbar__zone hb-topbar__zone--right">
        @foreach ($rightButtons as $btn)
            @if ($btn['device'] ?? false)
                <div class="hb-topbar__devsel">
                    <button type="button" class="hb-topbar__btn hb-topbar__btn--sm hb-topbar__device" data-hb-device-toggle data-device="desktop" aria-haspopup="listbox" aria-expanded="false" aria-label="{{ $btn['label'] }}">
                        @foreach ($devices as $dev => $meta)
                            <span class="hb-topbar__icon hb-topbar__icon--sm hb-dev hb-dev--{{ $dev }}" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => $meta[0], 'size' => 13])</span>
                        @endforeach
                    </button>
                    <div class="hb-topbar__devsel-menu" role="listbox" hidden>
                        @foreach ($devices as $dev => $meta)
                            <button type="button" class="hb-topbar__devsel-opt @if ($dev === 'desktop') is-on @endif" role="option" aria-selected="{{ $dev === 'desktop' ? 'true' : 'false' }}" data-device="{{ $dev }}" data-hb-device-opt>
                                @include('heisenberg::components.ui.icon', ['name' => $meta[0], 'size' => 15])<span>{{ $meta[1] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @elseif ($btn['export'] ?? false)
                <div class="hb-topbar__exportsel">
                    <button type="button" class="hb-topbar__btn hb-topbar__btn--sm" data-hb-export-toggle
                        aria-haspopup="true" aria-expanded="false" aria-label="{{ $btn['label'] }}"
                        @if (($postId ?? null) === null) disabled @endif>
                        <span class="hb-topbar__icon hb-topbar__icon--sm" aria-hidden="true">
                            @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 13])
                        </span>
                    </button>
                    <div class="hb-topbar__devsel-menu hb-topbar__exportsel-menu" role="menu" hidden>
                        <button type="button" class="hb-topbar__devsel-opt" role="menuitem" data-hb-export-item data-format="html">{{ __('heisenberg::editor.topbar.export_html') }}</button>
                        <button type="button" class="hb-topbar__devsel-opt" role="menuitem" data-hb-export-item data-format="eml">{{ __('heisenberg::editor.topbar.export_eml') }}</button>
                    </div>
                </div>
            @elseif ($btn['lang'] ?? false)
                <div class="hb-topbar__langsel">
                    <button type="button" class="hb-topbar__btn hb-topbar__btn--sm hb-topbar__lang" data-hb-lang-toggle
                        aria-haspopup="listbox" aria-expanded="false" aria-label="{{ $btn['label'] }}">
                        <span class="hb-topbar__icon hb-topbar__icon--sm" aria-hidden="true">
                            @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 13])
                        </span>
                        <span class="hb-topbar__lang-label" data-hb-lang-current-label>{{ $hbCurrentLocaleLabel }}</span>
                    </button>
                    <div class="hb-topbar__langsel-menu" role="listbox" hidden>
                        @foreach ($contentLocales as $hbLocale)
                            <button type="button" class="hb-topbar__langsel-opt @if ($hbLocale === $hbCurrentLocale) is-on @endif" role="option"
                                aria-selected="{{ $hbLocale === $hbCurrentLocale ? 'true' : 'false' }}"
                                data-hb-lang-option data-locale="{{ $hbLocale }}">
                                <span>{{ $contentLocaleLabels[$hbLocale] ?? __('heisenberg::editor.locales.' . $hbLocale) }}</span>
                                <span class="hb-topbar__langsel-opt__check" aria-hidden="true">
                                    @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 12])
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                <button
                    type="button"
                    class="hb-topbar__btn hb-topbar__btn--sm"
                    aria-label="{{ $btn['label'] }}"
                    @if ($btn['theme'] ?? false) data-hb-theme-toggle @endif
                    @if ($btn['preview'] ?? false) data-hb-preview @endif
                >
                    <span class="hb-topbar__icon hb-topbar__icon--sm" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 13])
                    </span>
                </button>
            @endif
        @endforeach
        <x-heisenberg::ui.divider orientation="vertical" style="width:1px;height:14px;" />
        <button type="button" class="hb-topbar__save">{{ __('heisenberg::editor.common.save') }}</button>
        <button type="button" class="hb-topbar__btn" aria-label="{{ __('heisenberg::editor.topbar.aria_panel_right') }}" data-hb-toggle="inspector">
            <span class="hb-topbar__icon" aria-hidden="true" style="transform:rotate(180deg);">
                @include('heisenberg::components.ui.icon', ['name' => 'sidebar-simple', 'size' => 14])
            </span>
        </button>
    </div>
</div>
