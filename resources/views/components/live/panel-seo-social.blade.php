@once
<style>
    .hb-panel-seo { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-panel-seo__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-panel-seo__content[hidden] { display: none; }
    .hb-panel-seo__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; display: flex; flex-direction: column; }

    .hb-seo-field { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); padding: var(--hb-space-3, 12px); flex: none; }
    .hb-seo-field__row { display: flex; align-items: center; justify-content: space-between; }
    .hb-seo-field__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-secondary); }
    .hb-seo-field__label--muted { color: var(--hb-text-muted); }
    .hb-seo-field__count { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted); }

    .hb-seo-preview {
        display: flex;
        flex-direction: column;
        gap: var(--hb-space-1, 4px);
        padding: var(--hb-space-2, 8px);
        background: var(--hb-bg-subtle);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-seo-preview__crumb { font-size: var(--hb-fs-xs, 11px); color: var(--hb-editing); }
    .hb-seo-preview__title { font-size: var(--hb-fs-base, 13px); font-weight: 500; color: var(--hb-success); }
    .hb-seo-preview__desc { font-size: var(--hb-fs-xs, 11px); line-height: 1.4; color: var(--hb-text-secondary); }

    .hb-seo-checklist { display: flex; flex-direction: column; gap: 10px; padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); flex: none; }
    .hb-seo-checklist__empty { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted); }
    .hb-seo-checklist .hb-statuscheckrow { align-items: flex-start; }
    .hb-seo-checklist .hb-statuscheckrow__icon { margin-top: 1px; }
    .hb-seo-checklist .hb-statuscheckrow__text { font-size: var(--hb-fs-xs, 11px); line-height: 1.35; }

    .hb-seo-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: var(--hb-space-2, 8px); padding: 0 var(--hb-space-3, 12px); height: 32px; flex: none; }
    .hb-seo-toggle-row__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); }
    .hb-seo-toggles { display: flex; flex-direction: column; padding: var(--hb-space-2, 8px) 0; flex: none; }

    .hb-seo-dropzone {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: var(--hb-space-2, 8px);
        height: 100px;
        width: 100%;
        border: 0.6px solid var(--hb-border-strong);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg-subtle);
        cursor: pointer;
        padding: 0;
        font: inherit;
        appearance: none;
        -webkit-appearance: none;
    }
    .hb-seo-dropzone:focus-visible { outline: 2px solid var(--hb-border-focus); outline-offset: 2px; }
    .hb-seo-dropzone[hidden] { display: none; }
    .hb-seo-dropzone__icon { display: inline-flex; width: 24px; height: 24px; color: var(--hb-text-muted); }
    .hb-seo-dropzone__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted); }
    .hb-seo-dropzone-wrap { padding: var(--hb-space-3, 12px); flex: none; }
    .hb-seo-dropzone-preview { position: relative; height: 100px; width: 100%; border-radius: var(--hb-radius-md, 5px); overflow: hidden; background: var(--hb-bg-subtle); border: 1px solid var(--hb-border-strong); }
    .hb-seo-dropzone-preview[hidden] { display: none; }
    .hb-seo-dropzone-preview__img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .hb-seo-dropzone-preview__actions { position: absolute; top: 6px; right: 6px; display: flex; gap: 4px; }
    .hb-seo-dropzone-preview__btn { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border: 0; border-radius: 4px; background: rgba(10, 10, 10, .55); color: #fff; cursor: pointer; }
    .hb-seo-dropzone-preview__btn:hover { background: rgba(10, 10, 10, .75); }
    .hb-seo-dropzone-preview__btn--danger:hover { background: var(--hb-danger); }

    .hb-seo-social-preview { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: var(--hb-space-3, 12px); flex: none; }
    .hb-seo-divider { border: 0; border-top: 1px solid var(--hb-border); width: 100%; margin: 0; flex: none; }

    .hb-seo-score { display: flex; align-items: center; gap: var(--hb-space-2, 8px); padding: var(--hb-space-3, 12px); border-bottom: 1px solid var(--hb-border); flex: none; transition: opacity .12s ease; }
    .hb-seo-score--loading { opacity: .6; }
    .hb-seo-score:not([data-rating]) { --hb-seo-score-color: var(--hb-text-muted); }
    .hb-seo-score[data-rating="unsaved"] { --hb-seo-score-color: var(--hb-text-muted); }
    .hb-seo-score[data-rating="poor"] { --hb-seo-score-color: var(--hb-danger); }
    .hb-seo-score[data-rating="needs-work"] { --hb-seo-score-color: #C9862E; }
    .hb-seo-score[data-rating="good"] { --hb-seo-score-color: var(--hb-success); }
    .hb-seo-score[data-rating="excellent"] { --hb-seo-score-color: #17A567; }
    .hb-seo-score__ring {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        flex: none;
        border-radius: 999px;
        background: conic-gradient(var(--hb-seo-score-color, var(--hb-text-muted)) calc(var(--hb-seo-score-pct, 0) * 1%), var(--hb-bg-muted) 0);
    }
    .hb-seo-score__ring::before { content: ''; position: absolute; inset: 3px; border-radius: 999px; background: var(--hb-bg); }
    .hb-seo-score__value { position: relative; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 600; color: var(--hb-text-primary); }
    .hb-seo-score__info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .hb-seo-score__rating { display: inline-block; width: fit-content; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); font-weight: 600; color: var(--hb-seo-score-color, var(--hb-text-muted)); }
    .hb-seo-score__status { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted); }
    .hb-seo-score__status[hidden] { display: none; }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-seo]').forEach((root) => {
                if (root.__hbPanelSeo) return;
                const tabs = root.querySelector('[data-hb-tablist]');
                const seo = root.querySelector('[data-hb-panel-seo-seo]');
                const social = root.querySelector('[data-hb-panel-seo-social]');
                tabs?.addEventListener('change', (event) => {
                    if (seo) seo.hidden = event.detail.index !== 0;
                    if (social) social.hidden = event.detail.index !== 1;
                });
                root.__hbPanelSeo = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'postId' => null,
    'postTitle' => '',
    'postSlug' => '',
    'postSeo' => null,
    'seoAnalyzeUrlTemplate' => '',
])
@php
    $postSeo = $postSeo ?? [
        'meta_title' => '', 'meta_description' => '', 'og_title' => '', 'og_description' => '',
        'focus_keyphrase' => '', 'og_image' => '', 'canonical_url' => '',
        'robots_index' => true, 'robots_follow' => true, 'in_sitemap' => true,
    ];
    $hbSeoDisabled = $postId === null;
    $hbSeoRatingLabels = [
        'poor' => __('heisenberg::editor.panel_seo_social.score_rating_poor'),
        'needs-work' => __('heisenberg::editor.panel_seo_social.score_rating_needs_work'),
        'good' => __('heisenberg::editor.panel_seo_social.score_rating_good'),
        'excellent' => __('heisenberg::editor.panel_seo_social.score_rating_excellent'),
    ];
    $hbSeoPreviewTitle = trim((string) ($postSeo['meta_title'] ?? '')) ?: trim((string) $postTitle);
    $hbSeoPreviewDesc = trim((string) ($postSeo['meta_description'] ?? ''));
    $hbSeoPreviewSlug = trim((string) $postSlug);
@endphp
<div data-hb-panel-seo
    data-hb-post-id="{{ $postId ?? '' }}"
    data-hb-seo-analyze-url-template="{{ $seoAnalyzeUrlTemplate }}"
    data-hb-seo-seed="{{ json_encode($postSeo) }}"
    data-hb-seo-rating-labels="{{ json_encode($hbSeoRatingLabels) }}"
    data-hb-seo-save-first="{{ __('heisenberg::editor.panel_seo_social.score_save_first') }}"
    data-hb-seo-unavailable="{{ __('heisenberg::editor.panel_seo_social.score_unavailable') }}"
    data-hb-seo-preview-title-ph="{{ __('heisenberg::editor.panel_seo_social.seo_preview_title') }}"
    data-hb-seo-preview-desc-ph="{{ __('heisenberg::editor.panel_seo_social.seo_preview_desc') }}"
    data-hb-seo-preview-prefix="{{ __('heisenberg::editor.panel_seo_social.seo_url_slug_prefix') }}"
    data-hb-seo-url-placeholder="{{ __('heisenberg::editor.panel_seo_social.seo_url_slug_value') }}"
    {{ $attributes->merge(['class' => 'hb-panel-seo']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_seo_social.tab_seo')], ['label' => __('heisenberg::editor.panel_seo_social.tab_social')]]" :active-index="0" />

    <div class="hb-panel-seo__content" data-hb-panel-seo-seo>
        <div class="hb-panel-seo__scroll" data-hb-panel-seo-seo-scroll>

        <div class="hb-seo-score" data-hb-seo-score>
            <div class="hb-seo-score__ring" data-hb-seo-score-ring aria-hidden="true">
                <span class="hb-seo-score__value" data-hb-seo-score-value>—</span>
            </div>
            <div class="hb-seo-score__info">
                <span class="hb-seo-score__rating" data-hb-seo-score-rating>{{ $hbSeoDisabled ? __('heisenberg::editor.panel_seo_social.score_save_first') : '' }}</span>
                <span class="hb-seo-score__status" data-hb-seo-score-status hidden></span>
            </div>
        </div>

        <div class="hb-seo-field">
            <div class="hb-seo-field__row">
                <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_title_label') }}</span>
                <span class="hb-seo-field__count" data-hb-seo-count="meta_title">{{ mb_strlen((string) $postSeo['meta_title']) }}/60</span>
            </div>
            <x-ui.input data-hb-seo-field="meta_title" :value="$postSeo['meta_title']" :placeholder="__('heisenberg::editor.panel_seo_social.seo_title_ph')" width="100%" :disabled="$hbSeoDisabled" />
        </div>

        <div class="hb-seo-field">
            <div class="hb-seo-field__row">
                <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_meta_label') }}</span>
                <span class="hb-seo-field__count" data-hb-seo-count="meta_description">{{ mb_strlen((string) $postSeo['meta_description']) }}/160</span>
            </div>
            <x-ui.text-area data-hb-seo-field="meta_description" :value="$postSeo['meta_description']" :placeholder="__('heisenberg::editor.panel_seo_social.seo_meta_ph')" width="100%" height="64px" :disabled="$hbSeoDisabled" />
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_url_slug') }}</span>
            <x-ui.field data-hb-post-slug-input data-hb-current-slug="{{ $postSlug }}" :prefix="__('heisenberg::editor.panel_seo_social.seo_canonical_prefix')" :value="$postSlug" width="100%" :disabled="$hbSeoDisabled" />
        </div>

        <div class="hb-seo-field">
            <div class="hb-seo-preview">
                <span class="hb-seo-preview__crumb" data-hb-seo-preview-crumb>{{ str_replace(':slug', $hbSeoPreviewSlug !== '' ? $hbSeoPreviewSlug : __('heisenberg::editor.panel_seo_social.seo_url_slug_value'), __('heisenberg::editor.panel_seo_social.seo_url_slug_prefix')) }}</span>
                <span class="hb-seo-preview__title" data-hb-seo-preview-title>{{ $hbSeoPreviewTitle !== '' ? $hbSeoPreviewTitle : __('heisenberg::editor.panel_seo_social.seo_preview_title') }}</span>
                <span class="hb-seo-preview__desc" data-hb-seo-preview-desc>{{ $hbSeoPreviewDesc !== '' ? $hbSeoPreviewDesc : __('heisenberg::editor.panel_seo_social.seo_preview_desc') }}</span>
            </div>
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_focus_keyphrase') }}</span>
            <x-ui.input data-hb-seo-field="focus_keyphrase" :value="$postSeo['focus_keyphrase']" :placeholder="__('heisenberg::editor.panel_seo_social.seo_focus_keyphrase_ph')" width="100%" :disabled="$hbSeoDisabled" />
        </div>

        <div class="hb-seo-checklist" data-hb-seo-checklist>
            <div data-hb-seo-check-prototypes hidden>
                <x-ui.status-check-row status="pass" text="" data-hb-check-proto="pass" />
                <x-ui.status-check-row status="warn" text="" data-hb-check-proto="warn" />
                <x-ui.status-check-row status="fail" text="" data-hb-check-proto="fail" />
                <x-ui.status-check-row status="na" text="" data-hb-check-proto="na" />
            </div>
            <span class="hb-seo-checklist__empty" data-hb-seo-checklist-empty>{{ __('heisenberg::editor.panel_seo_social.checklist_empty') }}</span>
        </div>

        <hr class="hb-seo-divider">
        <div class="hb-seo-toggles">
            <div class="hb-seo-toggle-row">
                <span class="hb-seo-toggle-row__label">{{ __('heisenberg::editor.panel_seo_social.seo_index_label') }}</span>
                <x-ui.toggle data-hb-seo-field="robots_index" :on="(bool) $postSeo['robots_index']" name="seo-index" :disabled="$hbSeoDisabled" />
            </div>
            <div class="hb-seo-toggle-row">
                <span class="hb-seo-toggle-row__label">{{ __('heisenberg::editor.panel_seo_social.seo_sitemap_label') }}</span>
                <x-ui.toggle data-hb-seo-field="in_sitemap" :on="(bool) $postSeo['in_sitemap']" name="seo-sitemap" :disabled="$hbSeoDisabled" />
            </div>
            <div class="hb-seo-toggle-row">
                <span class="hb-seo-toggle-row__label">{{ __('heisenberg::editor.panel_seo_social.seo_follow_label') }}</span>
                <x-ui.toggle data-hb-seo-field="robots_follow" :on="(bool) $postSeo['robots_follow']" name="seo-follow" :disabled="$hbSeoDisabled" />
            </div>
        </div>
        <hr class="hb-seo-divider">

        <div class="hb-seo-field">
            <span class="hb-seo-field__label hb-seo-field__label--muted">{{ __('heisenberg::editor.panel_seo_social.seo_canonical') }}</span>
            <x-ui.input data-hb-seo-field="canonical_url" :value="$postSeo['canonical_url']" :placeholder="__('heisenberg::editor.panel_seo_social.seo_canonical_ph')" width="100%" :disabled="$hbSeoDisabled" />
        </div>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-panel-seo-seo-scroll]" />
    </div>

    <div class="hb-panel-seo__content" data-hb-panel-seo-social hidden>
        <div class="hb-panel-seo__scroll" data-hb-panel-seo-social-scroll>
        <div class="hb-seo-dropzone-wrap" data-hb-seo-og-image-field>
            <button type="button" class="hb-seo-dropzone" data-hb-seo-og-trigger aria-haspopup="dialog" aria-label="{{ __('heisenberg::editor.panel_seo_social.social_set_image') }}" @if ($hbSeoDisabled) disabled @endif @if ((string) $postSeo['og_image'] !== '') hidden @endif>
                <span class="hb-seo-dropzone__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'image', 'size' => 24])
                </span>
                <span class="hb-seo-dropzone__label">{{ __('heisenberg::editor.panel_seo_social.social_set_image') }}</span>
            </button>
            <div class="hb-seo-dropzone-preview" data-hb-seo-og-preview @if ((string) $postSeo['og_image'] === '') hidden @endif>
                <img class="hb-seo-dropzone-preview__img" data-hb-seo-og-img @if ((string) $postSeo['og_image'] !== '') src="{{ $postSeo['og_image'] }}" @endif alt="">
                <div class="hb-seo-dropzone-preview__actions">
                    <button type="button" class="hb-seo-dropzone-preview__btn" data-hb-seo-og-replace aria-label="{{ __('heisenberg::editor.inspector.post_featured_replace') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'arrows-clockwise', 'size' => 14])
                    </button>
                    <button type="button" class="hb-seo-dropzone-preview__btn hb-seo-dropzone-preview__btn--danger" data-hb-seo-og-remove aria-label="{{ __('heisenberg::editor.inspector.post_featured_remove') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 14])
                    </button>
                </div>
            </div>
            <input type="hidden" data-hb-seo-field="og_image" value="{{ $postSeo['og_image'] }}">
            @php
                $hbSeoOgSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
                $hbSeoOgUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
            @endphp
            <x-live.media.media-dialog
                data-hb-seo-og-dialog
                hidden
                :scrim="true"
                tab="library"
                accept="image/*"
                :title="__('heisenberg::editor.media.select_social_image')"
                :select-url="$hbSeoOgSelectUrl"
                :upload-url="$hbSeoOgUploadUrl"
            />
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.social_title') }}</span>
            <x-ui.input data-hb-seo-field="og_title" :value="$postSeo['og_title']" :placeholder="__('heisenberg::editor.panel_seo_social.social_title_ph')" width="100%" :disabled="$hbSeoDisabled" />
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.social_description') }}</span>
            <x-ui.text-area data-hb-seo-field="og_description" :value="$postSeo['og_description']" :placeholder="__('heisenberg::editor.panel_seo_social.social_description_ph')" width="100%" height="56px" :disabled="$hbSeoDisabled" />
        </div>

        <div class="hb-seo-social-preview">
            <x-ui.social-preview-row logo="facebook-logo-bold" :label="__('heisenberg::editor.panel_seo_social.social_facebook')" />
            <x-ui.social-preview-row logo="twitter-logo-bold" :label="__('heisenberg::editor.panel_seo_social.social_x')" />
            <x-ui.social-preview-row logo="linkedin-logo-bold" :label="__('heisenberg::editor.panel_seo_social.social_linkedin')" />
        </div>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-panel-seo-social-scroll]" />
    </div>
</div>

@once('hb-panel-seo-live')
<script>
    (() => {
        const hbSlugMarkers = () => Array.from(document.querySelectorAll('[data-hb-post-slug-input]'));
        const hbSlugInputEl = (marker) => (marker.matches('input') ? marker : marker.querySelector('input'));

        const hbSeoFieldEl = (marker) => (marker.matches('input, textarea') ? marker : marker.querySelector('input, textarea'));
        const hbSeoFieldValue = (marker) => {
            const el = hbSeoFieldEl(marker);
            if (!el) return '';
            return el.type === 'checkbox' ? el.checked : el.value;
        };
        const hbSeoSetFieldValue = (marker, value) => {
            const el = hbSeoFieldEl(marker);
            if (!el) return;
            if (el.type === 'checkbox') el.checked = !!value;
            else el.value = value == null ? '' : String(value);
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-panel-seo]').forEach((root) => {
                if (root.__hbPanelSeoLive) return;
                root.__hbPanelSeoLive = true;

                try { root.__hbSeoConfirmed = JSON.parse(root.dataset.hbSeoSeed || '{}') || {}; } catch (e) { root.__hbSeoConfirmed = {}; }
                let ratingLabels = {};
                try { ratingLabels = JSON.parse(root.dataset.hbSeoRatingLabels || '{}') || {}; } catch (e) { ratingLabels = {}; }

                const currentValues = () => {
                    const values = {};
                    root.querySelectorAll('[data-hb-seo-field]').forEach((marker) => {
                        values[marker.dataset.hbSeoField] = hbSeoFieldValue(marker);
                    });
                    return values;
                };

                const refreshCounts = () => {
                    root.querySelectorAll('[data-hb-seo-count]').forEach((el) => {
                        const key = el.dataset.hbSeoCount;
                        const marker = root.querySelector('[data-hb-seo-field="' + key + '"]');
                        const input = marker ? hbSeoFieldEl(marker) : null;
                        const len = input ? (input.value || '').length : 0;
                        const max = key === 'meta_description' ? 160 : 60;
                        el.textContent = len + '/' + max;
                    });
                };

                const refreshPreview = () => {
                    const titleMarker = root.querySelector('[data-hb-seo-field="meta_title"]');
                    const descMarker = root.querySelector('[data-hb-seo-field="meta_description"]');
                    const titleInput = titleMarker ? hbSeoFieldEl(titleMarker) : null;
                    const descInput = descMarker ? hbSeoFieldEl(descMarker) : null;
                    const slugMarker = document.querySelector('[data-hb-post-slug-input]');
                    const slugInput = slugMarker ? hbSlugInputEl(slugMarker) : null;
                    const docTitleEl = document.querySelector('[data-hb-title]');
                    const docTitle = docTitleEl ? (docTitleEl.tagName === 'INPUT' ? docTitleEl.value : (docTitleEl.textContent || '')).trim() : '';

                    const titleEl = root.querySelector('[data-hb-seo-preview-title]');
                    const descEl = root.querySelector('[data-hb-seo-preview-desc]');
                    const crumbEl = root.querySelector('[data-hb-seo-preview-crumb]');

                    const titleText = (titleInput && titleInput.value.trim()) || docTitle;
                    if (titleEl) titleEl.textContent = titleText || (root.dataset.hbSeoPreviewTitlePh || '');
                    const descText = descInput ? descInput.value.trim() : '';
                    if (descEl) descEl.textContent = descText || (root.dataset.hbSeoPreviewDescPh || '');
                    const slugText = (slugInput && slugInput.value.trim()) || (root.dataset.hbSeoUrlPlaceholder || '');
                    if (crumbEl) crumbEl.textContent = (root.dataset.hbSeoPreviewPrefix || '').replace(':slug', slugText);
                };

                const ogField = root.querySelector('[data-hb-seo-og-image-field]');
                const ogTrigger = ogField ? ogField.querySelector('[data-hb-seo-og-trigger]') : null;
                const ogPreview = ogField ? ogField.querySelector('[data-hb-seo-og-preview]') : null;
                const ogImg = ogField ? ogField.querySelector('[data-hb-seo-og-img]') : null;
                const ogReplace = ogField ? ogField.querySelector('[data-hb-seo-og-replace]') : null;
                const ogRemove = ogField ? ogField.querySelector('[data-hb-seo-og-remove]') : null;
                const ogDialog = ogField ? ogField.querySelector('[data-hb-seo-og-dialog]') : null;
                const ogMarker = ogField ? ogField.querySelector('[data-hb-seo-field="og_image"]') : null;

                const refreshOgPreview = () => {
                    if (!ogPreview || !ogImg || !ogTrigger || !ogMarker) return;
                    const url = ogMarker.value || '';
                    if (url) {
                        ogImg.src = url;
                        ogPreview.hidden = false;
                        ogTrigger.hidden = true;
                    } else {
                        ogImg.removeAttribute('src');
                        ogPreview.hidden = true;
                        ogTrigger.hidden = false;
                    }
                };

                const announcePending = () => {
                    const current = currentValues();
                    const diff = {};
                    let changed = false;
                    Object.keys(current).forEach((key) => {
                        if (current[key] !== root.__hbSeoConfirmed[key]) { diff[key] = current[key]; changed = true; }
                    });
                    document.dispatchEvent(new CustomEvent('hb:post-seo-change', { detail: { seo: changed ? diff : null } }));
                };

                const scoreEl = root.querySelector('[data-hb-seo-score]');
                const scoreValueEl = root.querySelector('[data-hb-seo-score-value]');
                const scoreRingEl = root.querySelector('[data-hb-seo-score-ring]');
                const scoreRatingEl = root.querySelector('[data-hb-seo-score-rating]');
                const scoreStatusEl = root.querySelector('[data-hb-seo-score-status]');
                const checklistEl = root.querySelector('[data-hb-seo-checklist]');
                const checklistEmptyEl = root.querySelector('[data-hb-seo-checklist-empty]');
                const checkProtoHost = root.querySelector('[data-hb-seo-check-prototypes]');

                const rowPrototype = (status) => {
                    if (!checkProtoHost) return null;
                    const proto = checkProtoHost.querySelector('[data-hb-check-proto="' + status + '"]');
                    return proto ? proto.cloneNode(true) : null;
                };

                const renderChecklist = (checks) => {
                    if (!checklistEl) return;
                    checklistEl.querySelectorAll('[data-hb-seo-check-row]').forEach((row) => row.remove());
                    const list = Array.isArray(checks) ? checks : [];
                    if (checklistEmptyEl) checklistEmptyEl.hidden = list.length > 0;
                    const seenKeys = new Set();
                    const merged = list.filter((check) => {
                        const group = check && check.group;
                        if (!group) return true;
                        const key = group + ' ' + check.status + ' ' + check.message;
                        if (seenKeys.has(key)) return false;
                        seenKeys.add(key);
                        return true;
                    });
                    merged.forEach((check) => {
                        const status = ['pass', 'warn', 'fail', 'na'].indexOf(check && check.status) !== -1 ? check.status : 'pass';
                        const row = rowPrototype(status);
                        if (!row) return;
                        row.removeAttribute('data-hb-check-proto');
                        row.setAttribute('data-hb-seo-check-row', '');
                        const textEl = row.querySelector('.hb-statuscheckrow__text');
                        if (textEl) textEl.textContent = (check && check.message) || '';
                        checklistEl.appendChild(row);
                    });
                };

                const showUnsaved = () => {
                    if (scoreEl) scoreEl.dataset.rating = 'unsaved';
                    if (scoreValueEl) scoreValueEl.textContent = '—';
                    if (scoreRingEl) scoreRingEl.style.setProperty('--hb-seo-score-pct', '0');
                    if (scoreRatingEl) scoreRatingEl.textContent = root.dataset.hbSeoSaveFirst || '';
                    if (scoreStatusEl) scoreStatusEl.hidden = true;
                    renderChecklist([]);
                };
                const showUnavailable = () => {
                    if (scoreStatusEl) { scoreStatusEl.hidden = false; scoreStatusEl.textContent = root.dataset.hbSeoUnavailable || ''; }
                };
                const renderScore = (data) => {
                    const score = Math.max(0, Math.min(100, Math.round(Number(data && data.score) || 0)));
                    const rating = ['poor', 'needs-work', 'good', 'excellent'].indexOf(data && data.rating) !== -1 ? data.rating : 'poor';
                    if (scoreEl) scoreEl.dataset.rating = rating;
                    if (scoreValueEl) scoreValueEl.textContent = String(score);
                    if (scoreRingEl) scoreRingEl.style.setProperty('--hb-seo-score-pct', String(score));
                    if (scoreRatingEl) scoreRatingEl.textContent = ratingLabels[rating] || rating;
                    if (scoreStatusEl) scoreStatusEl.hidden = true;
                    renderChecklist(data && data.checks);
                };

                let analyzeSeq = 0;
                let analyzeTimer = null;
                const analyzeParams = () => {
                    const values = currentValues();
                    const slugMarker = document.querySelector('[data-hb-post-slug-input]');
                    const slugInput = slugMarker ? hbSlugInputEl(slugMarker) : null;
                    const robots = (values.robots_index === false ? 'noindex' : 'index') + ', ' + (values.robots_follow === false ? 'nofollow' : 'follow');
                    const params = new URLSearchParams();
                    params.set('o_meta_title', values.meta_title || '');
                    params.set('o_meta_description', values.meta_description || '');
                    params.set('o_focus_keyphrase', values.focus_keyphrase || '');
                    params.set('o_slug', (slugInput && slugInput.value) || '');
                    params.set('o_canonical', values.canonical_url || '');
                    params.set('o_robots', robots);
                    params.set('o_og_image', values.og_image || '');
                    return params;
                };
                const runAnalyze = () => {
                    clearTimeout(analyzeTimer);
                    analyzeTimer = null;
                    const postId = root.dataset.hbPostId || '';
                    const template = root.dataset.hbSeoAnalyzeUrlTemplate || '';
                    if (!postId || !template) { showUnsaved(); return; }
                    const seq = ++analyzeSeq;
                    if (scoreEl) scoreEl.classList.add('hb-seo-score--loading');
                    const url = template.replace('__ID__', encodeURIComponent(postId)) + '?' + analyzeParams().toString();
                    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http'))))
                        .then((data) => { if (seq === analyzeSeq) renderScore(data); })
                        .catch(() => { if (seq === analyzeSeq) showUnavailable(); })
                        .finally(() => { if (seq === analyzeSeq && scoreEl) scoreEl.classList.remove('hb-seo-score--loading'); });
                };
                const scheduleAnalyze = () => {
                    if (!root.dataset.hbPostId) { showUnsaved(); return; }
                    clearTimeout(analyzeTimer);
                    analyzeTimer = setTimeout(runAnalyze, 800);
                };

                const applyOgSelection = (file) => {
                    if (!ogMarker) return;
                    const url = file ? (file.thumbnail_url || file.url || '') : '';
                    ogMarker.value = url;
                    if (ogImg) ogImg.alt = (file && file.original_name) || '';
                    refreshOgPreview();
                    announcePending();
                    scheduleAnalyze();
                };
                const openOgDialog = (returnEl) => { if (ogDialog && typeof ogDialog.hbOpen === 'function') ogDialog.hbOpen(returnEl); };
                if (ogTrigger) ogTrigger.addEventListener('click', () => openOgDialog(ogTrigger));
                if (ogReplace) ogReplace.addEventListener('click', () => openOgDialog(ogReplace));
                if (ogRemove) ogRemove.addEventListener('click', () => applyOgSelection(null));
                if (ogDialog) ogDialog.addEventListener('hb:media-select', (event) => applyOgSelection(event.detail));

                root.addEventListener('input', (event) => {
                    const marker = event.target.closest && event.target.closest('[data-hb-seo-field]');
                    if (!marker) return;
                    refreshCounts();
                    refreshPreview();
                    announcePending();
                    scheduleAnalyze();
                });
                root.addEventListener('change', (event) => {
                    const marker = event.target.closest && event.target.closest('[data-hb-seo-field]');
                    if (!marker) return;
                    announcePending();
                    scheduleAnalyze();
                });
                document.addEventListener('hb:doc-title', () => { refreshPreview(); scheduleAnalyze(); });
                document.addEventListener('input', (event) => {
                    if (event.target.closest && event.target.closest('[data-hb-post-slug-input]')) { refreshPreview(); scheduleAnalyze(); }
                });

                document.addEventListener('hb:post-saved', (event) => {
                    const post = event.detail && event.detail.post;
                    if (!post) return;
                    if (post.id != null) root.dataset.hbPostId = String(post.id);
                    if (post.seo) {
                        root.__hbSeoConfirmed = post.seo;
                        root.querySelectorAll('[data-hb-seo-field]').forEach((marker) => {
                            const key = marker.dataset.hbSeoField;
                            if (key in root.__hbSeoConfirmed) hbSeoSetFieldValue(marker, root.__hbSeoConfirmed[key]);
                        });
                        refreshOgPreview();
                        refreshCounts();
                        refreshPreview();
                    }
                    runAnalyze();
                });
                document.addEventListener('hb:post-seo-rejected', () => announcePending());

                document.addEventListener('hb:post-id', (event) => {
                    if (event && event.detail && event.detail.id != null) root.dataset.hbPostId = String(event.detail.id);
                    root.querySelectorAll('[data-hb-seo-field]').forEach((marker) => {
                        const el = hbSeoFieldEl(marker);
                        if (el) el.disabled = false;
                    });
                    if (ogTrigger) ogTrigger.disabled = false;
                    runAnalyze();
                });

                new MutationObserver(() => {
                    if (!root.hidden && !root.__hbSeoAnalyzedOnce) {
                        root.__hbSeoAnalyzedOnce = true;
                        runAnalyze();
                    }
                }).observe(root, { attributes: true, attributeFilter: ['hidden'] });

                refreshOgPreview();
                refreshCounts();
                refreshPreview();
                if (!root.dataset.hbPostId) {
                    showUnsaved();
                } else if (!root.hidden) {
                    root.__hbSeoAnalyzedOnce = true;
                    runAnalyze();
                }
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
