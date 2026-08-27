      <div class="hb-inspector__post-body" data-hb-inspector-post-body>
        <div class="hb-post-title">
            <span class="hb-post-title__eyebrow">{{ __('heisenberg::editor.inspector.post_title_eyebrow') }}</span>
            <span class="hb-post-title__row">
                <span class="hb-post-title__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'note', 'size' => 15])
                </span>
                <input type="text" class="hb-post-title__input" data-hb-title value="{{ $postTitle }}" placeholder="{{ __('heisenberg::editor.inspector.post_title_placeholder') }}" aria-label="{{ __('heisenberg::editor.inspector.post_title_label') }}">
            </span>
        </div>

        @if ($documentType !== 'email')
        <x-heisenberg::ui.disclosure-row icon="image" :label="__('heisenberg::editor.inspector.post_featured_image')" chevron="down" />
        <div class="hb-post-dropzone-wrap" data-hb-disclosure-body data-hb-featured-field
            @if ((string) $postFeaturedImageUrlTemplate !== '') data-hb-featured-image-update-url-template="{{ $postFeaturedImageUrlTemplate }}" @endif>
            <button type="button" class="hb-post-dropzone" data-hb-featured-trigger aria-haspopup="dialog" aria-label="{{ __('heisenberg::editor.inspector.post_featured_set') }}" @if ($postFeaturedImage !== null) hidden @endif>
                <span class="hb-post-dropzone__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'image', 'size' => 28])
                </span>
                <span class="hb-post-dropzone__label">{{ __('heisenberg::editor.inspector.post_featured_set') }}</span>
            </button>
            <div class="hb-post-dropzone-preview" data-hb-featured-preview @if ($postFeaturedImage === null) hidden @endif>
                <img class="hb-post-dropzone-preview__img" data-hb-featured-img
                    @if ($postFeaturedImage !== null) src="{{ $postFeaturedImage['url'] }}" @if (! empty($postFeaturedImage['alt'])) alt="{{ $postFeaturedImage['alt'] }}" @else alt="" @endif @endif>
                <div class="hb-post-dropzone-preview__actions">
                    <button type="button" class="hb-post-dropzone-preview__btn" data-hb-featured-replace aria-label="{{ __('heisenberg::editor.inspector.post_featured_replace') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'arrows-clockwise', 'size' => 14])
                    </button>
                    <button type="button" class="hb-post-dropzone-preview__btn hb-post-dropzone-preview__btn--danger" data-hb-featured-remove aria-label="{{ __('heisenberg::editor.inspector.post_featured_remove') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 14])
                    </button>
                </div>
            </div>
            <input type="hidden" data-hb-featured-image-id value="{{ $postFeaturedImage['id'] ?? '' }}">
            <input type="hidden" data-hb-featured-image-url value="{{ $postFeaturedImage['url'] ?? '' }}">
            @php
                $hbFeaturedSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
                $hbFeaturedUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
            @endphp
            <x-heisenberg::live.media.media-dialog
                data-hb-featured-dialog
                hidden
                :scrim="true"
                tab="library"
                accept="image/*"
                :title="__('heisenberg::editor.media.select_featured_image')"
                :select-url="$hbFeaturedSelectUrl"
                :upload-url="$hbFeaturedUploadUrl"
            />
        </div>
        @endif

        <x-heisenberg::ui.disclosure-row icon="file-text" :label="__('heisenberg::editor.inspector.post_summary')" chevron="down" />
        <div data-hb-disclosure-body>
            @php $hbStatusRow = collect($postMeta)->firstWhere('key', 'status'); @endphp
            @php $hbUrlRow = collect($postMeta)->firstWhere('key', 'url'); @endphp
            @php $hbScheduledNow = ($hbStatusRow['raw'] ?? 'draft') === 'scheduled'; @endphp
            @php $hbPublishedDisplay = $postPublishedAt ? date('M j, Y, h:i A', strtotime($postPublishedAt)) : null; @endphp
            @php $hbScheduleDisplay = $postScheduledAt ? date('M j, Y, h:i A', strtotime($postScheduledAt)) : null; @endphp
            <div class="hb-post-meta" data-hb-post-meta>
                @foreach ($postMeta as $row)
                    <div class="hb-post-meta__row @if ($row['key'] === 'publish') hb-post-publish-row @endif"
                        @if ($row['key'] === 'publish') data-hb-post-publish-row @if ($hbScheduledNow) hidden @endif @endif>
                        <span class="hb-post-meta__label">{{ $row['label'] }}</span>
                        @if ($row['key'] === 'status')
                            <button type="button" class="hb-post-meta__value hb-post-meta__value--btn"
                                data-hb-post-status data-hb-post-popup-trigger="status"
                                aria-haspopup="listbox" aria-expanded="false"
                                data-hb-current-status="{{ $row['raw'] ?? 'draft' }}"
                                data-hb-transitions="{{ json_encode($postStatusTransitions ?? []) }}"
                                data-hb-status-labels="{{ json_encode($postStatusLabels ?? []) }}"
                                data-hb-status-pending-hint="{{ __('heisenberg::editor.inspector.summary_status_pending_hint') }}"
                                @if ($postId === null) disabled title="{{ __('heisenberg::editor.inspector.summary_status_save_first') }}" @endif>
                                {{ $row['value'] }}
                            </button>
                        @elseif ($row['key'] === 'url')
                            <button type="button" class="hb-post-meta__value hb-post-meta__value--btn"
                                data-hb-post-popup-trigger="slug" aria-haspopup="dialog" aria-expanded="false"
                                data-hb-slug-pending-hint="{{ __('heisenberg::editor.inspector.summary_slug_pending_hint') }}"
                                @if ($postId === null) disabled @endif>
                                {{ $row['value'] }}
                            </button>
                        @elseif ($row['key'] === 'publish')
                            <button type="button" class="hb-post-meta__value hb-post-meta__value--btn"
                                data-hb-post-popup-trigger="publish" aria-haspopup="dialog" aria-expanded="false"
                                data-hb-current-published-at="{{ $postPublishedAt ?? '' }}"
                                data-hb-immediately-label="{{ __('heisenberg::editor.inspector.summary_immediately') }}"
                                data-hb-publish-pending-hint="{{ __('heisenberg::editor.inspector.summary_publish_pending_hint') }}"
                                @if ($postId === null) disabled @endif>
                                {{ $hbPublishedDisplay ?? __('heisenberg::editor.inspector.summary_immediately') }}
                            </button>
                        @else
                            <span class="hb-post-meta__value" @if (!empty($row['key'])) data-hb-post-meta-value="{{ $row['key'] }}" @endif>{{ $row['value'] }}</span>
                        @endif
                    </div>
                @endforeach
                <div class="hb-post-meta__row hb-post-schedule-row" data-hb-post-schedule-row
                    @if (! $hbScheduledNow) hidden @endif>
                    <span class="hb-post-meta__label">{{ __('heisenberg::editor.inspector.summary_schedule_label') }}</span>
                    <button type="button" class="hb-post-meta__value hb-post-meta__value--btn"
                        data-hb-post-popup-trigger="schedule" aria-haspopup="dialog" aria-expanded="false"
                        data-hb-current-scheduled-at="{{ $postScheduledAt ?? '' }}"
                        @if ($postId === null) disabled @endif>
                        {{ $hbScheduleDisplay ?? '—' }}
                    </button>
                </div>
            </div>

            <div class="hb-post-popup" data-hb-post-popup="status" hidden>
                <div class="hb-pop hb-post-pop" data-hb-post-status-menu>
                    <div class="hb-varmenu__list" data-hb-post-status-list role="listbox" aria-label="{{ $hbStatusRow['label'] ?? '' }}">
                        @foreach (($hbStatusRow['options'] ?? []) as $hbStatusOption)
                            <button type="button" class="hb-vmi{{ ($hbStatusOption['value'] ?? null) === ($hbStatusRow['raw'] ?? 'draft') ? ' hb-vmi--on' : '' }}"
                                data-hb-post-status-option="{{ $hbStatusOption['value'] }}" role="option"
                                aria-selected="{{ ($hbStatusOption['value'] ?? null) === ($hbStatusRow['raw'] ?? 'draft') ? 'true' : 'false' }}">
                                <span class="hb-vmi__l">
                                    <span class="hb-vmi__check" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])</span>
                                    <span class="hb-vmi__name">{{ $hbStatusOption['label'] }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="hb-post-popup" data-hb-post-popup="slug" hidden>
                <div class="hb-pop hb-post-pop hb-post-slugpop" data-hb-post-slug-input data-hb-current-slug="{{ $hbUrlRow['raw'] ?? '' }}">
                    <span class="hb-post-slugpop__label">{{ __('heisenberg::editor.inspector.summary_slug_label') }}</span>
                    <input type="text" class="hb-post-slugpop__input"
                        value="{{ $hbUrlRow['raw'] ?? '' }}"
                        placeholder="{{ __('heisenberg::editor.inspector.summary_slug_placeholder') }}"
                        aria-label="{{ __('heisenberg::editor.inspector.summary_slug_label') }}"
                        autocomplete="off" spellcheck="false"
                        @if ($postId === null) disabled @endif>
                </div>
            </div>

            <div class="hb-post-popup" data-hb-post-popup="publish" hidden>
                <div class="hb-pop hb-post-pop">
                    <x-heisenberg::ui.date-picker data-hb-post-published-input :value="$postPublishedAt ?? ''" />
                </div>
            </div>

            <div class="hb-post-popup" data-hb-post-popup="schedule" hidden>
                <div class="hb-pop hb-post-pop">
                    <x-heisenberg::ui.date-picker data-hb-post-schedule-input :value="$postScheduledAt ?? ''" />
                </div>
            </div>

            <hr class="hb-post-divider">
            <div class="hb-post-toggles">
                <div class="hb-post-toggle-row">
                    <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_pending_review') }}</span>
                    <x-heisenberg::ui.toggle :on="$postPendingReview" name="post-pending-review" />
                </div>
                @if ($documentType !== 'email')
                <div class="hb-post-toggle-row">
                    <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_stick_top') }}</span>
                    <x-heisenberg::ui.toggle :on="$postStickToTop" name="post-stick-top" />
                </div>
                @endif
            </div>
            <hr class="hb-post-divider">
            <x-heisenberg::ui.disclosure-row icon="arrow-counter-clockwise" :label="__('heisenberg::editor.revisions.title')" chevron="none"
                data-hb-revisions-open
                :data-hb-post-id="$postId ?? ''"
                :data-hb-revisions-url-template="$postRevisionsUrlTemplate" />
            <div class="hb-post-trash-row" data-hb-post-trash-row>
                <button type="button" class="hb-post-trash" data-hb-post-trash
                    data-hb-post-id="{{ $postId ?? '' }}"
                    data-hb-trash-url-template="{{ $postTrashUrlTemplate }}"
                    data-hb-editor-index-url="{{ $documentType === 'email' ? route('heisenberg.editor.email.new') : route('heisenberg.editor.index') }}"
                    data-hb-confirm-label="{{ __('heisenberg::editor.inspector.post_move_trash_confirm') }}"
                    data-hb-msg-network="{{ __('heisenberg::editor.topbar.save_network') }}"
                    @if ($postId === null) disabled title="{{ __('heisenberg::editor.inspector.post_move_trash_save_first') }}" @endif>
                    <span class="hb-post-trash__icon" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 15])
                    </span>
                    <span class="hb-post-trash__label" data-hb-post-trash-label>{{ __('heisenberg::editor.inspector.post_move_trash') }}</span>
                </button>
                <button type="button" class="hb-post-trash-cancel" data-hb-post-trash-cancel hidden>{{ __('heisenberg::editor.common.cancel') }}</button>
            </div>
        </div>
        <x-heisenberg::live.revisions-dialog />

        <x-heisenberg::ui.disclosure-row icon="translate" :label="__('heisenberg::editor.inspector.post_translations')" chevron="down" />
        <div class="hb-post-translations-body" data-hb-disclosure-body data-hb-post-translations-field>
            <div class="hb-post-translations-list" data-hb-post-translations-list>
                @if ($postTranslations === null)
                    @foreach ($contentLocales as $hbLocale)
                        <button type="button" class="hb-post-translation-row" data-hb-translation-row data-hb-translation-locale="{{ $hbLocale }}">
                            <span class="hb-post-translation-row__locale">{{ __('heisenberg::editor.locales.' . $hbLocale) }}</span>
                            <span class="hb-post-translation-row__chip">{{ __('heisenberg::editor.inspector.post_translations_needs_save') }}</span>
                        </button>
                    @endforeach
                @else
                    @foreach ($postTranslations as $row)
                        @php
                            if ($row['complete']) {
                                $hbSummary = __('heisenberg::editor.inspector.post_translations_complete');
                            } else {
                                $hbParts = [];
                                if (! $row['title']) {
                                    $hbParts[] = __('heisenberg::editor.inspector.post_translations_title_missing');
                                }
                                if ($row['blocks_total'] > 0) {
                                    $hbParts[] = str_replace(
                                        [':done', ':total'],
                                        [$row['blocks_translated'], $row['blocks_total']],
                                        __('heisenberg::editor.inspector.post_translations_blocks_progress')
                                    );
                                }
                                $hbSummary = $hbParts === [] ? __('heisenberg::editor.inspector.post_translations_in_progress') : implode(' · ', $hbParts);
                            }
                        @endphp
                        <button type="button" class="hb-post-translation-row" data-hb-translation-row data-hb-translation-locale="{{ $row['locale'] }}">
                            <span class="hb-post-translation-row__locale">{{ __('heisenberg::editor.locales.' . $row['locale']) }}</span>
                            <span class="hb-post-translation-row__chip @if ($row['complete']) hb-post-translation-row__chip--complete @endif">{{ $hbSummary }}</span>
                        </button>
                    @endforeach
                @endif
            </div>
        </div>

        @if ($documentType !== 'email')
        <x-heisenberg::ui.disclosure-row icon="chat-circle" :label="__('heisenberg::editor.inspector.post_discussion')" chevron="down" />
        <div class="hb-post-discussion-body" data-hb-disclosure-body data-hb-post-discussion-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-discussion-url-template="{{ $postDiscussionUrlTemplate }}">
            <div class="hb-post-toggle-row">
                <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_allow_comments') }}</span>
                <x-heisenberg::ui.toggle data-hb-post-allow-comments :on="$postAllowComments" name="post-allow-comments" :disabled="$postId === null" />
            </div>
            <span class="hb-post-taxonomy-hint" data-hb-post-discussion-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>
        @endif

        @if ($documentType !== 'email')
        <x-heisenberg::ui.disclosure-row icon="list-numbers" :label="__('heisenberg::editor.toc.title')" chevron="down" />
        <div class="hb-post-toc-body" data-hb-disclosure-body data-hb-post-toc-field>
            <span class="hb-post-toc-summary" data-hb-post-toc-summary>
                {{ count($postTocEntries) > 0
                    ? str_replace(':count', (string) count($postTocEntries), __('heisenberg::editor.toc.summary_count'))
                    : __('heisenberg::editor.toc.summary_empty') }}
            </span>
            <button type="button" class="hb-post-toc-edit" data-hb-toc-open aria-haspopup="dialog"
                data-hb-post-id="{{ $postId ?? '' }}"
                data-hb-toc-url-template="{{ $postTocUrlTemplate }}"
                data-hb-toc-entries="{{ json_encode($postTocEntries) }}">
                {{ __('heisenberg::editor.toc.edit') }}
            </button>
        </div>
        <x-heisenberg::live.toc-dialog />
        @endif

