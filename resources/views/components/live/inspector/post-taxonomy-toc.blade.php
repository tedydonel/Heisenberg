
        {{-- Categories/Tags — ONE shared multi-select checklist widget for both (each is
             BelongsToMany via a real pivot, wired by the one wirePostTaxonomy()
             function below). Chevron is "down" (inline expansion, matching Featured Image/Summary
             above) rather than "right" (navigation to nowhere) now that there's real content.
             Checking a box POSTs an attach; unchecking DELETEs. The add-input above each list
             doubles as a filter over the already-loaded options (every category/tag is small in
             number and already server-rendered — nothing to remote-search, unlike Fonts) with
             Enter creating a new one when nothing matches. Both bodies render disabled until a
             real post id exists (data-hb-post-id, enabled by the script below on boot if $postId
             is already set, or on the hb:post-id event topbar fires after a new document's first
             save — PostCategoryController/PostTagController both require a persisted post). --}}
        {{-- Categories/Tags and Page layout are BLOG furniture: taxonomy organizes a listing an
             email never appears in, and the page padding belongs to the .hb-page sheet, while an
             email renders into EmailRenderer's fixed 600px shell (docs/email-system.md §5.3) where
             those sliders would move nothing. Hidden for an email document, the same gating
             Featured image / Discussion / Table of contents already carry in post-title-summary. --}}
        @if ($documentType !== 'email')
        <x-ui.disclosure-row icon="list-bullets" :label="__('heisenberg::editor.inspector.post_categories')" chevron="down" :expanded="false" persist-key="post-categories" />
        <div class="hb-post-taxonomy-body" data-hb-disclosure-body data-hb-post-taxonomy-field hidden
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-attach-url-template="{{ $postCategoryUrlTemplate }}"
            data-hb-index-url="{{ $categoriesIndexUrl }}"
            data-hb-create-response-key="category">
            <div class="hb-post-taxonomy-list-wrap" data-hb-post-taxonomy-list-wrap>
                <div class="hb-post-taxonomy-list-scroll" data-hb-post-taxonomy-list-scroll>
                    <div class="hb-post-taxonomy-list" data-hb-post-taxonomy-list>
                        @foreach ($categoryOptions as $option)
                            <x-ui.checkbox class="hb-post-taxonomy-item" :value="$option['value']"
                                :checked="in_array($option['value'], $postCategoryIds, true)" :label="$option['label']" />
                        @endforeach
                        <span class="hb-post-taxonomy-empty" data-hb-post-taxonomy-empty @if (count($categoryOptions)) hidden @endif>{{ __('heisenberg::editor.inspector.post_category_empty') }}</span>
                    </div>
                </div>
                <x-ui.custom-scrollbar container="[data-hb-post-taxonomy-list-scroll]" />
            </div>
            {{-- Clone target for a JS-added item (a newly created category) — `label=" "` (a single
                 space, not empty) is load-bearing: ui/checkbox only renders its `.hb-checkbox__label`
                 span at all when `$label !== ''`, and addItem() below needs that span to exist so it
                 can overwrite its textContent — the space is replaced before the node is ever
                 inserted, so it's never actually visible. --}}
            <template data-hb-post-taxonomy-item-template>
                <x-ui.checkbox class="hb-post-taxonomy-item" value="" label=" " />
            </template>
            <input type="text" class="hb-post-taxonomy-add-input" data-hb-post-taxonomy-add-input
                placeholder="{{ __('heisenberg::editor.inspector.post_category_add_ph') }}"
                autocomplete="off" spellcheck="false" @if ($postId === null) disabled @endif>
            <span class="hb-post-taxonomy-hint" data-hb-post-taxonomy-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        <x-ui.disclosure-row icon="tag" :label="__('heisenberg::editor.inspector.post_tags')" chevron="down" :expanded="false" persist-key="post-tags" />
        <div class="hb-post-taxonomy-body" data-hb-disclosure-body data-hb-post-taxonomy-field hidden
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-attach-url-template="{{ $postTagsUrlTemplate }}"
            data-hb-index-url="{{ $tagsIndexUrl }}"
            data-hb-create-response-key="tag">
            <div class="hb-post-taxonomy-list-wrap" data-hb-post-taxonomy-list-wrap>
                <div class="hb-post-taxonomy-list-scroll" data-hb-post-taxonomy-list-scroll>
                    <div class="hb-post-taxonomy-list" data-hb-post-taxonomy-list>
                        @foreach ($tagOptions as $option)
                            <x-ui.checkbox class="hb-post-taxonomy-item" :value="$option['value']"
                                :checked="in_array($option['value'], $postTagIds, true)" :label="$option['label']" />
                        @endforeach
                        <span class="hb-post-taxonomy-empty" data-hb-post-taxonomy-empty @if (count($tagOptions)) hidden @endif>{{ __('heisenberg::editor.inspector.post_tag_empty') }}</span>
                    </div>
                </div>
                <x-ui.custom-scrollbar container="[data-hb-post-taxonomy-list-scroll]" />
            </div>
            <template data-hb-post-taxonomy-item-template>
                <x-ui.checkbox class="hb-post-taxonomy-item" value="" label=" " />
            </template>
            <input type="text" class="hb-post-taxonomy-add-input" data-hb-post-taxonomy-add-input
                placeholder="{{ __('heisenberg::editor.inspector.post_tag_add_ph') }}"
                autocomplete="off" spellcheck="false" @if ($postId === null) disabled @endif>
            <span class="hb-post-taxonomy-hint" data-hb-post-taxonomy-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        {{-- Page layout (2026-08-03) — the whole page's X/Y padding, nothing else (no per-side
             overrides): two ui/slider controls writing --hb-page-padding-x/-y (34-canvas.css) live
             as the sliders move, saved debounced via PostSettingsController::updateLayout(). See
             live/canvas.blade.php for the matching server-rendered initial value (avoids a
             flash-of-default-padding before this section's JS boots). --}}
        <x-ui.disclosure-row icon="layout" :label="__('heisenberg::editor.inspector.post_page_layout')" chevron="down" />
        <div class="hb-post-layout-body" data-hb-disclosure-body data-hb-post-layout-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-layout-url-template="{{ $postLayoutUrlTemplate }}">
            <div class="hb-post-layout-row">
                <span class="hb-post-layout-row__label">{{ __('heisenberg::editor.inspector.post_layout_padding_x') }}</span>
                <x-ui.slider data-hb-post-layout-x :value="$postPagePaddingX" min="0" max="400" step="4" :disabled="$postId === null" />
                <span class="hb-post-layout-row__readout" data-hb-post-layout-x-readout>{{ $postPagePaddingX }}px</span>
            </div>
            <div class="hb-post-layout-row">
                <span class="hb-post-layout-row__label">{{ __('heisenberg::editor.inspector.post_layout_padding_y') }}</span>
                <x-ui.slider data-hb-post-layout-y :value="$postPagePaddingY" min="0" max="400" step="4" :disabled="$postId === null" />
                <span class="hb-post-layout-row__readout" data-hb-post-layout-y-readout>{{ $postPagePaddingY }}px</span>
            </div>
            <span class="hb-post-taxonomy-hint" data-hb-post-layout-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>
        @endif
      </div>

      {{-- Sibling of the scroll body, not a child of it — see the Block tab's tracks below. --}}
      <x-ui.custom-scrollbar container="[data-hb-inspector-post-body]" />

