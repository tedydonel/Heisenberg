
        @if ($documentType !== 'email')
        <x-heisenberg::ui.disclosure-row icon="list-bullets" :label="__('heisenberg::editor.inspector.post_categories')" chevron="down" :expanded="false" persist-key="post-categories" />
        <div class="hb-post-taxonomy-body" data-hb-disclosure-body data-hb-post-taxonomy-field hidden
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-attach-url-template="{{ $postCategoryUrlTemplate }}"
            data-hb-index-url="{{ $categoriesIndexUrl }}"
            data-hb-create-response-key="category">
            <div class="hb-post-taxonomy-list-wrap" data-hb-post-taxonomy-list-wrap>
                <div class="hb-post-taxonomy-list-scroll" data-hb-post-taxonomy-list-scroll>
                    <div class="hb-post-taxonomy-list" data-hb-post-taxonomy-list>
                        @foreach ($categoryOptions as $option)
                            <x-heisenberg::ui.checkbox class="hb-post-taxonomy-item" :value="$option['value']"
                                :checked="in_array($option['value'], $postCategoryIds, true)" :label="$option['label']" />
                        @endforeach
                        <span class="hb-post-taxonomy-empty" data-hb-post-taxonomy-empty @if (count($categoryOptions)) hidden @endif>{{ __('heisenberg::editor.inspector.post_category_empty') }}</span>
                    </div>
                </div>
                <x-heisenberg::ui.custom-scrollbar container="[data-hb-post-taxonomy-list-scroll]" />
            </div>
            <template data-hb-post-taxonomy-item-template>
                <x-heisenberg::ui.checkbox class="hb-post-taxonomy-item" value="" label=" " />
            </template>
            <input type="text" class="hb-post-taxonomy-add-input" data-hb-post-taxonomy-add-input
                placeholder="{{ __('heisenberg::editor.inspector.post_category_add_ph') }}"
                autocomplete="off" spellcheck="false" @if ($postId === null) disabled @endif>
            <span class="hb-post-taxonomy-hint" data-hb-post-taxonomy-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        <x-heisenberg::ui.disclosure-row icon="tag" :label="__('heisenberg::editor.inspector.post_tags')" chevron="down" :expanded="false" persist-key="post-tags" />
        <div class="hb-post-taxonomy-body" data-hb-disclosure-body data-hb-post-taxonomy-field hidden
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-attach-url-template="{{ $postTagsUrlTemplate }}"
            data-hb-index-url="{{ $tagsIndexUrl }}"
            data-hb-create-response-key="tag">
            <div class="hb-post-taxonomy-list-wrap" data-hb-post-taxonomy-list-wrap>
                <div class="hb-post-taxonomy-list-scroll" data-hb-post-taxonomy-list-scroll>
                    <div class="hb-post-taxonomy-list" data-hb-post-taxonomy-list>
                        @foreach ($tagOptions as $option)
                            <x-heisenberg::ui.checkbox class="hb-post-taxonomy-item" :value="$option['value']"
                                :checked="in_array($option['value'], $postTagIds, true)" :label="$option['label']" />
                        @endforeach
                        <span class="hb-post-taxonomy-empty" data-hb-post-taxonomy-empty @if (count($tagOptions)) hidden @endif>{{ __('heisenberg::editor.inspector.post_tag_empty') }}</span>
                    </div>
                </div>
                <x-heisenberg::ui.custom-scrollbar container="[data-hb-post-taxonomy-list-scroll]" />
            </div>
            <template data-hb-post-taxonomy-item-template>
                <x-heisenberg::ui.checkbox class="hb-post-taxonomy-item" value="" label=" " />
            </template>
            <input type="text" class="hb-post-taxonomy-add-input" data-hb-post-taxonomy-add-input
                placeholder="{{ __('heisenberg::editor.inspector.post_tag_add_ph') }}"
                autocomplete="off" spellcheck="false" @if ($postId === null) disabled @endif>
            <span class="hb-post-taxonomy-hint" data-hb-post-taxonomy-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        <x-heisenberg::ui.disclosure-row icon="layout" :label="__('heisenberg::editor.inspector.post_page_layout')" chevron="down" />
        <div class="hb-post-layout-body" data-hb-disclosure-body data-hb-post-layout-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-layout-url-template="{{ $postLayoutUrlTemplate }}">
            <div class="hb-post-layout-row">
                <span class="hb-post-layout-row__label">{{ __('heisenberg::editor.inspector.post_layout_padding_x') }}</span>
                <x-heisenberg::ui.slider data-hb-post-layout-x :value="$postPagePaddingX" min="0" max="400" step="4" :disabled="$postId === null" />
                <span class="hb-post-layout-row__readout" data-hb-post-layout-x-readout>{{ $postPagePaddingX }}px</span>
            </div>
            <div class="hb-post-layout-row">
                <span class="hb-post-layout-row__label">{{ __('heisenberg::editor.inspector.post_layout_padding_y') }}</span>
                <x-heisenberg::ui.slider data-hb-post-layout-y :value="$postPagePaddingY" min="0" max="400" step="4" :disabled="$postId === null" />
                <span class="hb-post-layout-row__readout" data-hb-post-layout-y-readout>{{ $postPagePaddingY }}px</span>
            </div>
            <span class="hb-post-taxonomy-hint" data-hb-post-layout-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>
        @endif
      </div>

      <x-heisenberg::ui.custom-scrollbar container="[data-hb-inspector-post-body]" />

