{{-- live/panel-seo-social — Middle panel, 240px (same
     240-vs-260 note as live/panel-components-blocks).
     SEO tab: title/meta-description/URL-slug/focus-keyphrase fields (ui/input, ui/text-area — both
     reused at their real sourced widths/heights now that those are props), a search-result preview
     (new, no existing atom matches this exact 3-line breadcrumb/title/description block), a 5-row
     checklist (ui/status-check-row, 3 pass + 2 fail exactly as sourced), 3 indexing toggles
     (ui/toggle — Index/Sitemap on, Follow off, matching the source's own descendant overrides), and a
     canonical URL field.
     Social tab: an image drop zone (new — visually close to but shorter than ui/tool-card's dropzone
     use elsewhere, not force-reused), title/description fields, and 3 ui/social-preview-row instances
     (Facebook/X/LinkedIn — X and LinkedIn needed their -bold icon weight fetched and vendored, same as
     Facebook's was previously). --}}
@once
<style>
    .hb-panel-seo { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg, #fff); border-right: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-panel-seo__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-panel-seo__content[hidden] { display: none; }
    /* Two-layer scroll shell — see live/panel-components-blocks.blade.php's own note on why the
       scrollbar's `container` can't be the same element the bar itself is positioned inside. */
    .hb-panel-seo__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; display: flex; flex-direction: column; }

    .hb-seo-field { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); padding: var(--hb-space-3, 12px); flex: none; }
    .hb-seo-field__row { display: flex; align-items: center; justify-content: space-between; }
    .hb-seo-field__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-seo-field__label--muted { color: var(--hb-text-muted, #9A9A9A); }
    .hb-seo-field__count { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }

    .hb-seo-preview {
        display: flex;
        flex-direction: column;
        gap: var(--hb-space-1, 4px);
        padding: var(--hb-space-2, 8px);
        background: var(--hb-bg-subtle, #FAFAFA);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-seo-preview__crumb { font-size: var(--hb-fs-xs, 11px); color: var(--hb-editing, #3D68F5); }
    .hb-seo-preview__title { font-size: var(--hb-fs-base, 13px); font-weight: 500; color: var(--hb-success, #3BD186); }
    .hb-seo-preview__desc { font-size: var(--hb-fs-xs, 11px); line-height: 1.4; color: var(--hb-text-secondary, #5A5A5A); }

    .hb-seo-checklist { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); flex: none; }

    .hb-seo-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: var(--hb-space-2, 8px); padding: 0 var(--hb-space-3, 12px); height: 32px; flex: none; }
    .hb-seo-toggle-row__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-seo-toggles { display: flex; flex-direction: column; padding: var(--hb-space-2, 8px) 0; flex: none; }

    .hb-seo-dropzone {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: var(--hb-space-2, 8px);
        height: 100px;
        width: 100%;
        border: 0.6px solid var(--hb-border-strong, #C8C8C8);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg-subtle, #FAFAFA);
    }
    .hb-seo-dropzone__icon { display: inline-flex; width: 24px; height: 24px; color: var(--hb-text-muted, #9A9A9A); }
    .hb-seo-dropzone__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted, #9A9A9A); }
    .hb-seo-dropzone-wrap { padding: var(--hb-space-3, 12px); flex: none; }

    .hb-seo-social-preview { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: var(--hb-space-3, 12px); flex: none; }
    .hb-seo-divider { border: 0; border-top: 1px solid var(--hb-border, #E4E4E4); width: 100%; margin: 0; flex: none; }
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

@php
    $checklist = [
        ['status' => 'pass', 'text' => __('heisenberg::editor.panel_seo_social.checklist_keyphrase_title')],
        ['status' => 'pass', 'text' => __('heisenberg::editor.panel_seo_social.checklist_title_length')],
        ['status' => 'pass', 'text' => __('heisenberg::editor.panel_seo_social.checklist_slug_keyphrase')],
        ['status' => 'fail', 'text' => __('heisenberg::editor.panel_seo_social.checklist_meta_missing')],
        ['status' => 'fail', 'text' => __('heisenberg::editor.panel_seo_social.checklist_density_low')],
    ];
@endphp
<div data-hb-panel-seo {{ $attributes->merge(['class' => 'hb-panel-seo']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_seo_social.tab_seo')], ['label' => __('heisenberg::editor.panel_seo_social.tab_social')]]" :active-index="0" />

    <div class="hb-panel-seo__content" data-hb-panel-seo-seo>
        <div class="hb-panel-seo__scroll" data-hb-panel-seo-seo-scroll>
        <div class="hb-seo-field">
            <div class="hb-seo-field__row">
                <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_title_label') }}</span>
                <span class="hb-seo-field__count">0/60</span>
            </div>
            <x-ui.input :placeholder="__('heisenberg::editor.panel_seo_social.seo_title_ph')" width="100%" />
        </div>

        <div class="hb-seo-field">
            <div class="hb-seo-field__row">
                <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_meta_label') }}</span>
                <span class="hb-seo-field__count">0/160</span>
            </div>
            <x-ui.text-area :placeholder="__('heisenberg::editor.panel_seo_social.seo_meta_ph')" width="100%" height="64px" />
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_url_slug') }}</span>
            <x-ui.field :prefix="__('heisenberg::editor.panel_seo_social.seo_canonical_prefix')" :value="__('heisenberg::editor.panel_seo_social.seo_url_slug_value')" width="100%" />
        </div>

        <div class="hb-seo-field">
            <div class="hb-seo-preview">
                <span class="hb-seo-preview__crumb">{{ str_replace(':slug', __('heisenberg::editor.panel_seo_social.seo_url_slug_value'), __('heisenberg::editor.panel_seo_social.seo_url_slug_prefix')) }}</span>
                <span class="hb-seo-preview__title">{{ __('heisenberg::editor.panel_seo_social.seo_preview_title') }}</span>
                <span class="hb-seo-preview__desc">{{ __('heisenberg::editor.panel_seo_social.seo_preview_desc') }}</span>
            </div>
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.seo_focus_keyphrase') }}</span>
            <x-ui.input :placeholder="__('heisenberg::editor.panel_seo_social.seo_focus_keyphrase_ph')" width="100%" />
        </div>

        <div class="hb-seo-checklist">
            @foreach ($checklist as $item)
                <x-ui.status-check-row :status="$item['status']" :text="$item['text']" />
            @endforeach
        </div>

        <hr class="hb-seo-divider">
        <div class="hb-seo-toggles">
            <div class="hb-seo-toggle-row">
                <span class="hb-seo-toggle-row__label">{{ __('heisenberg::editor.panel_seo_social.seo_index_label') }}</span>
                <x-ui.toggle :on="true" name="seo-index" />
            </div>
            <div class="hb-seo-toggle-row">
                <span class="hb-seo-toggle-row__label">{{ __('heisenberg::editor.panel_seo_social.seo_sitemap_label') }}</span>
                <x-ui.toggle :on="true" name="seo-sitemap" />
            </div>
            <div class="hb-seo-toggle-row">
                <span class="hb-seo-toggle-row__label">{{ __('heisenberg::editor.panel_seo_social.seo_follow_label') }}</span>
                <x-ui.toggle :on="false" name="seo-follow" />
            </div>
        </div>
        <hr class="hb-seo-divider">

        <div class="hb-seo-field">
            <span class="hb-seo-field__label hb-seo-field__label--muted">{{ __('heisenberg::editor.panel_seo_social.seo_canonical') }}</span>
            <x-ui.input :placeholder="__('heisenberg::editor.panel_seo_social.seo_canonical_ph')" width="100%" />
        </div>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-panel-seo-seo-scroll]" />
    </div>

    <div class="hb-panel-seo__content" data-hb-panel-seo-social hidden>
        <div class="hb-panel-seo__scroll" data-hb-panel-seo-social-scroll>
        <div class="hb-seo-dropzone-wrap">
            <div class="hb-seo-dropzone">
                <span class="hb-seo-dropzone__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'image', 'size' => 24])
                </span>
                <span class="hb-seo-dropzone__label">{{ __('heisenberg::editor.panel_seo_social.social_set_image') }}</span>
            </div>
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.social_title') }}</span>
            <x-ui.input :placeholder="__('heisenberg::editor.panel_seo_social.social_title_ph')" width="100%" />
        </div>

        <div class="hb-seo-field">
            <span class="hb-seo-field__label">{{ __('heisenberg::editor.panel_seo_social.social_description') }}</span>
            <x-ui.text-area :placeholder="__('heisenberg::editor.panel_seo_social.social_description_ph')" width="100%" height="56px" />
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
