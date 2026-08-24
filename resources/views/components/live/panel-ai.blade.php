{{-- live/panel-ai — the AI tab, built to the Pencil reference
     (docs/design/ai-tab-reference.html). Same 240px middle-panel rail as
     live/panel-components-blocks; the Tools tab is carried over unchanged.

     Thinking block (collapsible, timed), Applied card ("APPLIED TO YOUR POST",
     fed by tool_use/live-build events), Quick inserts (canned follow-up chips),
     Composer (textarea + new-chat/model-select/send row), History (every
     finished turn POSTed to /editor/ai/conversations; the header's notepad
     opens live/ai/ai-history-dialog, which fires hb:ai-open-conversation back
     here to restore a thread and its model/`history` context). --}}
@once
<style>
    .hb-panel-ai { display: flex; flex-direction: column; width: 100%; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-panel-ai__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-panel-ai__content[hidden] { display: none; }

    .hb-ai-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-ai-scroll { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }

    .hb-ai-header { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: 10px; flex: none; }
    .hb-ai-header__row { display: flex; align-items: center; gap: var(--hb-space-2, 8px); }
    /* 2026-08-15: badge background cleared so the fill icons (sparkle-fill) sit on the panel
       directly. The original grey pill was only there because the regular (outline) sparkle
       needed contrast to read — the fill weight doesn't. */
    .hb-ai-header__badge { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; flex: none; }
    .hb-ai-header__badge-icon { display: inline-flex; width: 16px; height: 16px; color: var(--hb-accent); }
    .hb-ai-header__action.hb-iconbtn { background: transparent; border-color: transparent; }
    /* Header action buttons (history / settings) — bare icon only, no chrome. Scoped to this
       header so the rest of the editor's icon-buttons keep their resting frame. */
    .hb-ai-header__action.hb-iconbtn {
        background: transparent;
        border-color: transparent;
    }
    .hb-ai-header__action.hb-iconbtn:hover:not(:disabled) {
        background: var(--hb-surface-hover);
    }
    .hb-ai-header__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 600; color: var(--hb-text-primary); }
    .hb-ai-header__action { flex: none; }
    .hb-ai-header__action:first-of-type { margin-left: auto; }

    {{-- The transcript. Reference sets an 11px/15px body on both roles; the
         thread border-tops against the header like the reference's Response
         frame. --}}
    .hb-ai-thread { display: flex; flex-direction: column; gap: var(--hb-space-3, 12px); padding: var(--hb-space-3, 12px) 10px; border-top: 1px solid var(--hb-border); }
    .hb-ai-msg { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); width: 100%; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-ai-msg__role { font-size: var(--hb-fs-xs, 11px); font-weight: 600; color: var(--hb-text-muted); }
    .hb-ai-msg--assistant .hb-ai-msg__role { color: var(--hb-text-primary); }
    .hb-ai-msg__text {
        font-size: var(--hb-fs-xs, 11px); line-height: 15px;
        color: var(--hb-text-primary);
        margin: 0; white-space: pre-wrap; overflow-wrap: anywhere;
    }
    .hb-ai-msg--user .hb-ai-msg__text { color: var(--hb-text-muted); }
    .hb-ai-msg--error .hb-ai-msg__text, .hb-ai-msg--note.hb-ai-msg--error .hb-ai-msg__text { color: var(--hb-danger); }

    {{-- Assistant prose is rendered markdown — the renderer owns line breaking. --}}
    .hb-ai-msg--assistant .hb-ai-msg__text { white-space: normal; }
    .hb-ai-msg__text p { margin: 0 0 6px; }
    .hb-ai-msg__text > :last-child { margin-bottom: 0; }
    .hb-ai-msg__text ul, .hb-ai-msg__text ol { margin: 0 0 6px; padding-left: 16px; }
    .hb-ai-msg__text li { margin: 2px 0; }
    .hb-ai-msg__text code {
        font-family: var(--hb-font-mono, 'JetBrains Mono', monospace);
        font-size: 10px; padding: 0 3px; border-radius: 3px;
        background: var(--hb-bg-inset);
    }
    .hb-ai-msg__text a { color: inherit; text-decoration: underline; }
    .hb-ai-msg__text strong { font-weight: 600; }
    .hb-ai-msg__text .hb-ai-md-h { font-weight: 600; margin: 8px 0 4px; }

    {{-- Tiny Edit under the user bubble, trailing edge. --}}
    .hb-ai-msg__edit {
        align-self: flex-end;
        display: inline-flex; align-items: center; gap: 2px;
        border: 0; background: transparent; cursor: pointer; padding: 0;
        font-family: inherit; font-size: 9px; font-weight: 500;
        color: var(--hb-text-disabled);
    }
    .hb-ai-msg__edit .hb-icon { width: 10px; height: 10px; }
    .hb-ai-msg__edit:hover { color: var(--hb-text-muted); }

    {{-- Thinking block: hidden until the stream actually produces reasoning. --}}
    .hb-ai-think { display: flex; flex-direction: column; gap: 2px; width: 100%; }
    .hb-ai-think[hidden] { display: none; }
    .hb-ai-think__head {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; padding: 4px 6px; border: 0; cursor: pointer;
        background: var(--hb-bg-subtle); border-radius: var(--hb-radius-md, 5px);
        font-family: inherit;
    }
    .hb-ai-think__left { display: inline-flex; align-items: center; gap: var(--hb-space-1, 4px); color: var(--hb-text-muted); }
    .hb-ai-think__left .hb-icon { width: 12px; height: 12px; }
    .hb-ai-think__label { font-size: var(--hb-fs-xs, 11px); font-weight: 500; color: var(--hb-text-muted); }
    .hb-ai-think__chevron { display: inline-flex; color: var(--hb-text-muted); transition: transform .15s ease; }
    .hb-ai-think__chevron .hb-icon { width: 12px; height: 12px; }
    .hb-ai-think:not(.is-open) .hb-ai-think__chevron { transform: rotate(180deg); }
    .hb-ai-think__body { padding: 4px 8px 4px 12px; }
    .hb-ai-think:not(.is-open) .hb-ai-think__body { display: none; }
    .hb-ai-think__text {
        font-size: var(--hb-fs-xs, 11px); line-height: 15px; font-style: italic;
        color: var(--hb-text-muted); margin: 0;
        white-space: pre-wrap; overflow-wrap: anywhere;
    }

    {{-- Applied card — check-circle rows, one per tool run / build milestone. --}}
    .hb-ai-applied { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); width: 100%; padding: var(--hb-space-2, 8px); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg-subtle); }
    .hb-ai-applied[hidden] { display: none; }
    .hb-ai-applied__label, .hb-ai-suggest__label {
        font-size: 9px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
        color: var(--hb-text-muted);
    }
    .hb-ai-applied__item { display: flex; align-items: flex-start; gap: var(--hb-space-1, 4px); font-size: var(--hb-fs-xs, 11px); line-height: 15px; color: var(--hb-text-secondary); }
    .hb-ai-applied__item .hb-icon { width: 12px; height: 12px; color: var(--hb-success); flex: none; margin-top: 1px; }

    {{-- Quick inserts — success-soft chip pills. --}}
    .hb-ai-suggest { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); width: 100%; }
    .hb-ai-suggest[hidden] { display: none; }
    .hb-ai-suggest__row { display: flex; flex-wrap: wrap; gap: var(--hb-space-1, 4px); }
    .hb-ai-suggest__chip {
        display: inline-flex; align-items: center;
        border: 0; cursor: pointer; padding: 2px 8px;
        background: var(--hb-success-soft); border-radius: var(--hb-radius-lg, 8px);
        font-family: inherit; font-size: 9px; font-weight: 500; color: var(--hb-text-primary);
    }
    .hb-ai-suggest__chip:hover { filter: brightness(.97); }

    .hb-ai-msg__actions { display: flex; align-items: center; justify-content: flex-end; gap: var(--hb-space-3, 12px); width: 100%; }
    .hb-ai-msg__actions[hidden] { display: none; }
    .hb-ai-action { display: inline-flex; align-items: center; gap: var(--hb-space-1, 4px); border: 0; background: transparent; cursor: pointer; padding: 0; font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-text-secondary); }
    .hb-ai-action .hb-icon { width: 14px; height: 14px; color: var(--hb-text-muted); }

    .hb-ai-empty {
        padding: 32px 10px; text-align: center;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted);
    }
    .hb-ai-empty[hidden] { display: none; }

    {{-- Composer — muted well + textarea, 32px control row: new chat / model / send. --}}
    .hb-ai-composer { flex: none; display: flex; flex-direction: column; background: var(--hb-surface-active); border-top: 1px solid var(--hb-border); }
    .hb-ai-composer__input {
        flex: 1 1 auto; min-width: 0; width: 100%;
        border: 0; outline: 0; background: transparent;
        padding: var(--hb-space-1, 4px) var(--hb-space-2, 8px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px); line-height: 1.45;
        color: var(--hb-text-primary);
        /* Default height: ~3 visible lines (matches the rows="3" attribute below). Without
           this min-height, the textarea renders at rows="2" intrinsic ≈ 35-43px on a fresh
           tab — a sliver where the placeholder is barely legible and the click target is tiny.
           The autosize handler (panel-script) clamps to max(min-height, scrollHeight) so this
           also stops the box from shrinking back to a sliver after a short prompt is typed
           and then cleared. Grows to the cap then scrolls (scrollbar hidden — the well would
           look broken with a bar down its side). */
        min-height: 60px; box-sizing: border-box;
        resize: none; max-height: 160px; overflow-y: auto;
        scrollbar-width: none; -ms-overflow-style: none;
    }
    .hb-ai-composer__input::-webkit-scrollbar { width: 0; height: 0; display: none; }
    .hb-ai-composer__input::placeholder { color: var(--hb-text-muted); }
    .hb-ai-composer__row { display: flex; align-items: center; justify-content: space-between; gap: var(--hb-space-2, 8px); height: 32px; padding: 0 4px 4px 8px; }
    .hb-ai-composer__btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 24px; height: 24px; border: 0; border-radius: 14px;
        background: var(--hb-accent); color: var(--hb-accent-fg); cursor: pointer; flex: none;
    }
    .hb-ai-composer__btn:disabled { opacity: .4; cursor: default; }
    .hb-ai-composer__btn--stop { background: var(--hb-danger); }
    .hb-ai-composer__btn[hidden] { display: none; }
    .hb-ai-composer__spacer { flex: 1 1 auto; }
    {{-- The real ui/select; only its slot is sized here to sit like a model pill. --}}
    .hb-ai-model { flex: none; width: 150px; }
    .hb-ai-model[hidden] { display: none; }
    {{-- On the bottom row, so its menu must open UPWARD or it's clipped below the panel. --}}
    .hb-ai-model .hb-select__menu { top: auto; bottom: calc(100% + var(--hb-space-1, 4px)); }

    {{-- The block most recently landed pulses while the run is still going —
         the canvas-side half of "watchable building". --}}
    .hb-canvas [data-block].hb-ai-writing { outline: 2px solid var(--hb-editing-soft); outline-offset: 2px; animation: hb-ai-writing-pulse 1.2s ease-in-out infinite; }
    @keyframes hb-ai-writing-pulse { 50% { outline-color: transparent; } }

    .hb-panel-ai__toolsbody { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-panel-ai__grid { display: grid; grid-template-columns: 1fr 1fr; align-content: start; gap: 8px; padding: var(--hb-space-3, 12px); }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-ai]').forEach((root) => {
                if (root.__hbPanelAi) return;
                const tabs = root.querySelector('[data-hb-tablist]');
                const ai = root.querySelector('[data-hb-panel-ai-ai]');
                const tools = root.querySelector('[data-hb-panel-ai-tools]');
                tabs?.addEventListener('change', (event) => {
                    if (ai) ai.hidden = event.detail.index !== 0;
                    if (tools) tools.hidden = event.detail.index !== 1;
                });
                root.__hbPanelAi = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
{{-- The assistant script (SSE stream, tool_use handling, conversation history, composer) is a
     sibling partial rather than inline: this file crossed the 64KB ceiling Livewire's morph
     compiler imposes (tests/Editor/BladeFileSizeGuardTest.php), and a large inline <script> is
     what takes a Blade view there. One IIFE, so it moves as one piece. --}}
@include('heisenberg::components.live.ai.panel-script')
@endonce

@props(['streamUrl' => null, 'conversationsUrl' => null, 'suggestUrl' => null, 'modelOptions' => [], 'activeModel' => null, 'locale' => 'en', 'postId' => null])
@php
    $toolCards = [
        ['icon' => 'sparkle', 'label' => __('heisenberg::editor.panel_ai_tools.tool_generate_title')],
        ['icon' => 'file-text', 'label' => __('heisenberg::editor.panel_ai_tools.tool_write_summary')],
        ['icon' => 'magic-wand', 'label' => __('heisenberg::editor.panel_ai_tools.tool_improve_writing')],
        ['icon' => 'pencil-simple', 'label' => __('heisenberg::editor.panel_ai_tools.tool_fix_grammar')],
        ['icon' => 'sliders-horizontal', 'label' => __('heisenberg::editor.panel_ai_tools.tool_change_tone')],
        // "Generate Image" stays deliberately absent — see the old panel's note;
        // neither shipped adapter can produce an image.
        ['icon' => 'translate', 'label' => __('heisenberg::editor.panel_ai_tools.tool_translate')],
        ['icon' => 'trend-up', 'label' => __('heisenberg::editor.panel_ai_tools.tool_seo_optimize')],
    ];
@endphp
<div data-hb-panel-ai
    data-stream-url="{{ $streamUrl }}"
    data-conversations-url="{{ $conversationsUrl }}"
    data-suggest-url="{{ $suggestUrl }}"
    data-locale="{{ $locale }}"
    data-post-id="{{ $postId }}"
    data-msg-thinking="{{ __('heisenberg::editor.panel_ai_tools.ai_thinking') }}"
    data-msg-thinking-label="{{ __('heisenberg::editor.panel_ai_tools.ai_thinking_label') }}"
    data-msg-thought-for="{{ __('heisenberg::editor.panel_ai_tools.ai_thought_for') }}"
    data-msg-building="{{ __('heisenberg::editor.panel_ai_tools.ai_building') }}"
    data-msg-built="{{ __('heisenberg::editor.panel_ai_tools.ai_built') }}"
    data-msg-translated="{{ __('heisenberg::editor.panel_ai_tools.ai_translated') }}"
    data-msg-translate-append-refused="{{ __('heisenberg::editor.panel_ai_tools.ai_translate_append_refused') }}"
    data-msg-translate-mismatch="{{ __('heisenberg::editor.panel_ai_tools.ai_translate_mismatch') }}"
    data-msg-set-title="{{ __('heisenberg::editor.panel_ai_tools.ai_set_title') }}"
    data-msg-working="{{ __('heisenberg::editor.panel_ai_tools.ai_working_tool') }}"
    data-msg-network="{{ __('heisenberg::editor.ai.network_error', ['provider' => __('heisenberg::editor.ai.settings_title')]) }}"
    data-msg-role-you="{{ __('heisenberg::editor.panel_ai_tools.ai_role_you') }}"
    data-msg-role-assistant="{{ __('heisenberg::editor.panel_ai_tools.ai_role_assistant') }}"
    data-msg-stopped="{{ __('heisenberg::editor.panel_ai_tools.ai_stopped') }}"
    data-msg-empty-reply="{{ __('heisenberg::editor.panel_ai_tools.ai_empty_reply') }}"
    data-msg-truncated="{{ __('heisenberg::editor.panel_ai_tools.ai_truncated') }}"
    data-msg-length-limit="{{ __('heisenberg::editor.panel_ai_tools.ai_length_limit') }}"
    data-msg-history-error="{{ __('heisenberg::editor.panel_ai_tools.ai_history_error') }}"
    {{ $attributes->merge(['class' => 'hb-panel-ai']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_ai_tools.tab_ai')], ['label' => __('heisenberg::editor.panel_ai_tools.tab_tools')]]" :active-index="0" />

    <div class="hb-panel-ai__content" data-hb-panel-ai-ai>
        <div class="hb-ai-body">
        <div class="hb-ai-scroll" data-hb-ai-scroll>
            <div class="hb-ai-header">
                <div class="hb-ai-header__row">
                    <span class="hb-ai-header__badge" aria-hidden="true">
                        <span class="hb-ai-header__badge-icon">@include('heisenberg::components.ui.icon', ['name' => 'sparkle-fill', 'size' => 16])</span>
                    </span>
                    <span class="hb-ai-header__title">{{ __('heisenberg::editor.panel_ai_tools.ai_assistant') }}</span>
                    <x-ui.icon-button icon="notepad-fill" class="hb-ai-header__action"
                        :label="__('heisenberg::editor.panel_ai_tools.ai_history_open')" data-hb-ai-history-open />
                    <x-ui.icon-button icon="gear-fill" class="hb-ai-header__action"
                        :label="__('heisenberg::editor.ai.settings_open')" data-hb-ai-settings-open />
                </div>
            </div>

            <div class="hb-ai-thread" data-hb-ai-thread></div>
            <div class="hb-ai-empty" data-hb-ai-empty>{{ __('heisenberg::editor.panel_ai_tools.ai_empty') }}</div>
        </div>
            <x-ui.custom-scrollbar container="[data-hb-ai-scroll]" />
        </div>

        <div class="hb-ai-composer">
            <textarea class="hb-ai-composer__input" data-hb-ai-prompt rows="3"
                placeholder="{{ __('heisenberg::editor.panel_ai_tools.ai_prompt_ph') }}"></textarea>
            <div class="hb-ai-composer__row">
                <button type="button" class="hb-ai-composer__btn" data-hb-ai-new
                    aria-label="{{ __('heisenberg::editor.panel_ai_tools.ai_new_chat') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])
                </button>
                <span class="hb-ai-composer__spacer"></span>
                @if (! empty($modelOptions))
                    <x-ui.select class="hb-ai-model" data-hb-ai-model
                        :options="$modelOptions" :value="$activeModel"
                        :aria-label="__('heisenberg::editor.panel_ai_tools.ai_model_label')" />
                @endif
                <button type="button" class="hb-ai-composer__btn" data-hb-ai-send aria-label="{{ __('heisenberg::editor.common.send') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'arrow-up', 'size' => 14])
                </button>
                <button type="button" class="hb-ai-composer__btn hb-ai-composer__btn--stop" data-hb-ai-stop hidden
                    aria-label="{{ __('heisenberg::editor.panel_ai_tools.ai_stop') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'stop', 'size' => 12])
                </button>
            </div>
        </div>
    </div>

    {{-- A user turn (also the base for a bare note — role/edit stripped). --}}
    <template data-hb-ai-user-template>
        <div class="hb-ai-msg hb-ai-msg--user" data-hb-ai-msg>
            <span class="hb-ai-msg__role" data-hb-ai-msg-role></span>
            <p class="hb-ai-msg__text" data-hb-ai-text></p>
            <button type="button" class="hb-ai-msg__edit" data-hb-ai-edit>
                @include('heisenberg::components.ui.icon', ['name' => 'pencil-simple', 'size' => 10])
                {{ __('heisenberg::editor.panel_ai_tools.ai_edit') }}
            </button>
        </div>
    </template>

    {{-- An assistant turn: thinking block, prose, applied card, quick inserts,
         actions — each section hidden until the stream gives it something. --}}
    <template data-hb-ai-assistant-template>
        <div class="hb-ai-msg hb-ai-msg--assistant" data-hb-ai-msg>
            <span class="hb-ai-msg__role" data-hb-ai-msg-role></span>
            <div class="hb-ai-think" data-hb-ai-think hidden>
                <button type="button" class="hb-ai-think__head" data-hb-ai-think-head>
                    <span class="hb-ai-think__left">
                        @include('heisenberg::components.ui.icon', ['name' => 'brain', 'size' => 12])
                        <span class="hb-ai-think__label" data-hb-ai-think-label></span>
                    </span>
                    <span class="hb-ai-think__chevron" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'caret-up', 'size' => 12])</span>
                </button>
                <div class="hb-ai-think__body">
                    <p class="hb-ai-think__text" data-hb-ai-think-text></p>
                </div>
            </div>
            {{-- A div, not a p: assistant prose renders as markdown and may
                 contain lists/paragraphs, which are illegal inside <p>. --}}
            <div class="hb-ai-msg__text" data-hb-ai-text></div>
            <div class="hb-ai-applied" data-hb-ai-applied hidden>
                <span class="hb-ai-applied__label">{{ __('heisenberg::editor.panel_ai_tools.ai_applied_label') }}</span>
                <div data-hb-ai-applied-list></div>
                <template>
                    <div class="hb-ai-applied__item">
                        @include('heisenberg::components.ui.icon', ['name' => 'check-circle', 'size' => 12])
                        <span data-hb-ai-applied-text></span>
                    </div>
                </template>
            </div>
            {{-- Quick inserts are generated per conversation by the model, in the
                 editor's current language — filled in by JS after the turn, from
                 /editor/ai/suggest. Empty (and hidden) until then. --}}
            <div class="hb-ai-suggest" data-hb-ai-suggest hidden>
                <span class="hb-ai-suggest__label">{{ __('heisenberg::editor.panel_ai_tools.ai_quick_inserts') }}</span>
                <div class="hb-ai-suggest__row" data-hb-ai-suggest-row></div>
            </div>
            <div class="hb-ai-msg__actions" data-hb-ai-actions hidden>
                <button type="button" class="hb-ai-action" data-hb-ai-regenerate>
                    @include('heisenberg::components.ui.icon', ['name' => 'arrow-clockwise', 'size' => 14])
                    {{ __('heisenberg::editor.panel_ai_tools.ai_regenerate') }}
                </button>
            </div>
        </div>
    </template>

    <div class="hb-panel-ai__content" data-hb-panel-ai-tools hidden>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_ai_tools.search_tools')"
            data-hb-filter="[data-hb-panel-ai-tools]" data-hb-filter-item=".hb-panel-ai__grid > *" />
        <x-ui.category-head :label="__('heisenberg::editor.panel_ai_tools.category_writing')" />
        <div class="hb-panel-ai__toolsbody" data-hb-ai-tools-scroll>
            <div class="hb-panel-ai__grid">
                @foreach ($toolCards as $card)
                    <x-ui.tool-card :icon="$card['icon']" :label="$card['label']"
                        :data-hb-ai-suggest="$card['label']" />
                @endforeach
            </div>
            <x-ui.custom-scrollbar container="[data-hb-ai-tools-scroll]" />
        </div>
    </div>
</div>
