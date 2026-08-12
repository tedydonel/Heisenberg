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
        <x-ui.disclosure-row icon="image" :label="__('heisenberg::editor.inspector.post_featured_image')" chevron="down" />
        <div class="hb-post-dropzone-wrap" data-hb-disclosure-body data-hb-featured-field
            @if ((string) $postFeaturedImageUrlTemplate !== '') data-hb-featured-image-update-url-template="{{ $postFeaturedImageUrlTemplate }}" @endif>
            {{-- A real focusable control (native <button>, not a bare div) — opens the media
                 dialog below. Hidden once an image is picked, swapping for the preview block. --}}
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
            {{-- Hidden inputs are the documented client-side mirror of the post's featured image
                 (the same Post::featuredImage BelongsTo that the preview page now reads). Seeded
                 from EditorController::show() on first render so a reload shows the existing pick,
                 not an empty dropzone. The script below PUTs every change to the post's
                 featured-image endpoint, so the pick survives reloads — the same posture as the
                 discussion/layout rows adjacent to this one. See featured-image script below. --}}
            <input type="hidden" data-hb-featured-image-id value="{{ $postFeaturedImage['id'] ?? '' }}">
            <input type="hidden" data-hb-featured-image-url value="{{ $postFeaturedImage['url'] ?? '' }}">
            @php
                $hbFeaturedSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
                $hbFeaturedUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
            @endphp
            <x-live.media.media-dialog
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

        <x-ui.disclosure-row icon="file-text" :label="__('heisenberg::editor.inspector.post_summary')" chevron="down" />
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
                                @if ($postId === null) disabled title="{{ __('heisenberg::editor.inspector.summary_status_save_first') }}" @endif>
                                {{ $row['value'] }}
                            </button>
                        @elseif ($row['key'] === 'url')
                            <button type="button" class="hb-post-meta__value hb-post-meta__value--btn"
                                data-hb-post-popup-trigger="slug" aria-haspopup="dialog" aria-expanded="false"
                                @if ($postId === null) disabled @endif>
                                {{ $row['value'] }}
                            </button>
                        @elseif ($row['key'] === 'publish')
                            <button type="button" class="hb-post-meta__value hb-post-meta__value--btn"
                                data-hb-post-popup-trigger="publish" aria-haspopup="dialog" aria-expanded="false"
                                data-hb-current-published-at="{{ $postPublishedAt ?? '' }}"
                                data-hb-immediately-label="{{ __('heisenberg::editor.inspector.summary_immediately') }}"
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
                    <x-ui.date-picker data-hb-post-published-input :value="$postPublishedAt ?? ''" />
                </div>
            </div>

            <div class="hb-post-popup" data-hb-post-popup="schedule" hidden>
                <div class="hb-pop hb-post-pop">
                    <x-ui.date-picker data-hb-post-schedule-input :value="$postScheduledAt ?? ''" />
                </div>
            </div>

            <hr class="hb-post-divider">
            <div class="hb-post-toggles">
                <div class="hb-post-toggle-row">
                    <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_pending_review') }}</span>
                    <x-ui.toggle :on="$postPendingReview" name="post-pending-review" />
                </div>
                <div class="hb-post-toggle-row">
                    <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_stick_top') }}</span>
                    <x-ui.toggle :on="$postStickToTop" name="post-stick-top" />
                </div>
            </div>
            <hr class="hb-post-divider">
            {{-- Revisions — opens the history dialog (live/revisions-dialog.blade.php). Lives
                 INSIDE the Summary body (2026-08-08), just above Move to trash. The row carries
                 the URL template + current post id; hb:post-id updates it after a new document's
                 first save, same contract as the taxonomy bodies below. --}}
            <x-ui.disclosure-row icon="arrow-counter-clockwise" :label="__('heisenberg::editor.revisions.title')" chevron="right"
                data-hb-revisions-open
                :data-hb-post-id="$postId ?? ''"
                :data-hb-revisions-url-template="$postRevisionsUrlTemplate" />
            <button type="button" class="hb-post-trash">
                <span class="hb-post-trash__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 15])
                </span>
                <span class="hb-post-trash__label">{{ __('heisenberg::editor.inspector.post_move_trash') }}</span>
            </button>
        </div>
        <x-live.revisions-dialog />

        {{-- Translations (docs/content-translation.md §5, Wave T2a) — one row per configured
             locale (postTranslations, TranslationStatusService::statuses()): locale display name,
             a status chip, and a status-dependent action (Create translation / Open / Update from
             source). Below Summary per the design doc, its own disclosure — Summary itself is
             untouched. `postTranslations === null` is the /editor blank-document + never-saved
             state: nothing to translate yet, so the body renders a muted hint instead of rows,
             same "needs save" posture as Discussion/Categories/Tags. Wiring in
             wirePostTranslations below. --}}
        <x-ui.disclosure-row icon="translate" :label="__('heisenberg::editor.inspector.post_translations')" chevron="down" />
        <div class="hb-post-translations-body" data-hb-disclosure-body data-hb-post-translations-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-translations-url-template="{{ $postTranslationsUrlTemplate }}"
            data-hb-editor-url-template="{{ $postEditorUrlTemplate }}"
            data-hb-translations-confirm-label="{{ __('heisenberg::editor.inspector.post_translations_update_confirm') }}"
            data-hb-translations-updated-label="{{ __('heisenberg::editor.inspector.post_translations_update_success') }}">
            @if ($postTranslations === null)
                <span class="hb-post-taxonomy-hint" data-hb-post-translations-hint>{{ __('heisenberg::editor.inspector.post_translations_needs_save') }}</span>
            @else
                <div class="hb-post-translations-list" data-hb-post-translations-list>
                    @foreach ($postTranslations as $row)
                        <div class="hb-post-translation-row" data-hb-translation-row
                            data-hb-translation-locale="{{ $row['locale'] }}"
                            data-hb-translation-status="{{ $row['status'] }}"
                            data-hb-translation-post-id="{{ $row['post_id'] ?? '' }}">
                            <span class="hb-post-translation-row__locale">{{ __('heisenberg::editor.locales.' . $row['locale']) }}</span>
                            <span class="hb-post-translation-row__chip hb-post-translation-row__chip--{{ $row['status'] }}">
                                {{ __('heisenberg::editor.inspector.post_translations_status_' . $row['status']) }}
                            </span>
                            <span class="hb-post-translation-row__actions">
                                @if ($row['status'] === 'source')
                                    <span class="hb-post-translation-row__marker">{{ __('heisenberg::editor.inspector.post_translations_this_post') }}</span>
                                @else
                                    @if ($row['status'] === 'missing')
                                        <button type="button" class="hb-post-translation-btn" data-hb-translation-create>{{ __('heisenberg::editor.inspector.post_translations_create') }}</button>
                                    @else
                                        <button type="button" class="hb-post-translation-btn" data-hb-translation-open>{{ __('heisenberg::editor.inspector.post_translations_open') }}</button>
                                    @endif
                                    @if ($row['status'] === 'outdated')
                                        <button type="button" class="hb-post-translation-btn hb-post-translation-btn--danger" data-hb-translation-update>{{ __('heisenberg::editor.inspector.post_translations_update') }}</button>
                                    @endif
                                @endif
                            </span>
                        </div>
                        <span class="hb-post-translation-note" data-hb-translation-note hidden></span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Discussion (2026-08-03; moved up beside Summary 2026-08-08 — it is post-level
             metadata like the rows above, not taxonomy) — a single Allow-comments toggle. A
             plain per-post override, nullable in the DB (null = comments allowed), same
             disabled-until-saved posture as Categories/Tags below. See
             PostSettingsController::updateDiscussion(); wiring in wirePostDiscussion below. --}}
        @if ($documentType !== 'email')
        <x-ui.disclosure-row icon="chat-circle" :label="__('heisenberg::editor.inspector.post_discussion')" chevron="down" />
        <div class="hb-post-discussion-body" data-hb-disclosure-body data-hb-post-discussion-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-discussion-url-template="{{ $postDiscussionUrlTemplate }}">
            <div class="hb-post-toggle-row">
                <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_allow_comments') }}</span>
                <x-ui.toggle data-hb-post-allow-comments :on="$postAllowComments" name="post-allow-comments" :disabled="$postId === null" />
            </div>
            <span class="hb-post-taxonomy-hint" data-hb-post-discussion-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>
        @endif

        {{-- Table of contents (2026-08-10) — the AUTHORED counterpart to the tableOfContents
             capability's `source: "headings"` render-time derivation (docs/post-template-schema.md,
             `source: "entries"`). This row only shows a summary + Edit trigger; the whole editing
             surface (add/reorder/remove entries, Load from headings, Save) lives in
             live/toc-dialog.blade.php's modal, which the Edit button opens with the post's current
             entries handed over as JSON (data-hb-toc-entries) — same "read off the opener" contract
             live/revisions-dialog uses for its own url template + post id. The summary text is
             rendered server-side from EditorController::show()'s postTocEntries and kept live by the
             dialog's own script after every save (see toc-dialog's applySaved()). --}}
        @if ($documentType !== 'email')
        <x-ui.disclosure-row icon="list-numbers" :label="__('heisenberg::editor.toc.title')" chevron="down" />
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
        <x-live.toc-dialog />
        @endif

