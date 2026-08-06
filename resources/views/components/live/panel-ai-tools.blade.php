{{-- live/panel-ai-tools — Middle panel, 240px (same
     240-vs-260 note as live/panel-components-blocks).
     Ai tab: header (badge + title, new small assembly), subtitle, 4 ui/suggestion-row instances (icon
     colors vary per row in the source — editing/success/accent/danger — passed through iconColor,
     which that atom already exposed for exactly this), a response card (new — bg-subtle box with an
     Insert/Regenerate action pair, no existing atom matches this shape), and a prompt input bar with a
     circular send button.
     Tools tab: search + "Writing" category head + an 8-card grid, all real ui/tool-card instances. --}}
@once
<style>
    .hb-panel-ai { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg, #fff); border-right: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-panel-ai__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-panel-ai__content[hidden] { display: none; }

    {{-- Scrolls independently of .hb-ai-prompt-wrap below, which stays pinned to the bottom of the
         panel (its own flex:none + normal doc flow after this) rather than scrolling away with it.
         Two-layer scroll shell inside it (see live/panel-components-blocks.blade.php's own note) —
         .hb-ai-body is just the flex slot + position:relative anchor, .hb-ai-scroll is the actual
         JS-tracked scroll region the scrollbar's `container` targets. --}}
    .hb-ai-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-ai-scroll { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }

    .hb-ai-header { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: 10px; flex: none; }
    .hb-ai-header__row { display: flex; align-items: center; gap: var(--hb-space-2, 8px); }
    .hb-ai-header__badge { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 14px; background: var(--hb-bg-inset, #EEEEEE); flex: none; }
    .hb-ai-header__badge-icon { display: inline-flex; width: 16px; height: 16px; color: var(--hb-accent, #000); }
    .hb-ai-header__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 600; color: var(--hb-text-primary, #0A0A0A); }
    .hb-ai-header__sub { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); line-height: 1.4; color: var(--hb-text-secondary, #5A5A5A); }

    .hb-ai-section { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); padding: 10px; border-top: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-ai-section__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 600; color: var(--hb-text-secondary, #5A5A5A); }

    .hb-ai-response { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: var(--hb-space-3, 12px); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg-subtle, #FAFAFA); }
    .hb-ai-response__text { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); line-height: 1.4; color: var(--hb-text-secondary, #5A5A5A); }
    .hb-ai-response__actions { display: flex; align-items: center; gap: var(--hb-space-3, 12px); }
    .hb-ai-action { display: inline-flex; align-items: center; gap: var(--hb-space-1, 4px); border: 0; background: transparent; cursor: pointer; padding: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; }
    .hb-ai-action__icon { display: inline-flex; width: 14px; height: 14px; }
    .hb-ai-action--insert { color: var(--hb-accent, #000); }
    .hb-ai-action--insert .hb-ai-action__icon { color: var(--hb-accent, #000); }
    .hb-ai-action--regenerate { color: var(--hb-text-secondary, #5A5A5A); }
    .hb-ai-action--regenerate .hb-ai-action__icon { color: var(--hb-text-muted, #9A9A9A); }

    .hb-ai-prompt-wrap { padding: 10px; flex: none; display: flex; }
    .hb-ai-prompt-bar {
        display: flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        height: 32px;
        padding: 0 var(--hb-space-1, 4px) 0 var(--hb-space-3, 12px);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-lg, 8px);
        background: var(--hb-bg, #fff);
    }
    .hb-ai-prompt-bar__input {
        flex: 1 1 auto;
        min-width: 0;
        border: 0;
        outline: 0;
        padding: 0;
        background: transparent;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-ai-prompt-bar__input::placeholder { color: var(--hb-text-muted, #9A9A9A); }
    .hb-ai-prompt-bar__send { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border: 0; border-radius: 14px; background: var(--hb-accent, #000); color: var(--hb-accent-fg, #fff); cursor: pointer; flex: none; }

    {{-- Same two-layer shell once more: .hb-panel-ai__toolsbody is the flex slot + position:relative
         anchor, .hb-panel-ai__grid is now purely the CSS grid (no longer also the scroll container
         AND a grid item's sibling at once — the scrollbar bar used to be an actual grid cell). --}}
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
@endonce

@php
    $suggestions = [
        ['icon' => 'text-t', 'iconColor' => 'var(--hb-editing, #3D68F5)', 'label' => __('heisenberg::editor.panel_ai_tools.sug_write_intro')],
        ['icon' => 'magic-wand', 'iconColor' => 'var(--hb-success, #3BD186)', 'label' => __('heisenberg::editor.panel_ai_tools.sug_improve_paragraph')],
        ['icon' => 'check-circle', 'iconColor' => 'var(--hb-accent, #000)', 'label' => __('heisenberg::editor.panel_ai_tools.sug_fix_grammar')],
        ['icon' => 'sparkle', 'iconColor' => 'var(--hb-danger, #D4191A)', 'label' => __('heisenberg::editor.panel_ai_tools.sug_generate_title')],
    ];
    $toolCards = [
        ['icon' => 'sparkle', 'label' => __('heisenberg::editor.panel_ai_tools.tool_generate_title')],
        ['icon' => 'file-text', 'label' => __('heisenberg::editor.panel_ai_tools.tool_write_summary')],
        ['icon' => 'magic-wand', 'label' => __('heisenberg::editor.panel_ai_tools.tool_improve_writing')],
        ['icon' => 'pencil-simple', 'label' => __('heisenberg::editor.panel_ai_tools.tool_fix_grammar')],
        ['icon' => 'sliders-horizontal', 'label' => __('heisenberg::editor.panel_ai_tools.tool_change_tone')],
        ['icon' => 'image', 'label' => __('heisenberg::editor.panel_ai_tools.tool_generate_image')],
        ['icon' => 'translate', 'label' => __('heisenberg::editor.panel_ai_tools.tool_translate')],
        ['icon' => 'trend-up', 'label' => __('heisenberg::editor.panel_ai_tools.tool_seo_optimize')],
    ];
@endphp
<div data-hb-panel-ai {{ $attributes->merge(['class' => 'hb-panel-ai']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_ai_tools.tab_ai')], ['label' => __('heisenberg::editor.panel_ai_tools.tab_tools')]]" :active-index="0" />

    <div class="hb-panel-ai__content" data-hb-panel-ai-ai>
        <div class="hb-ai-body">
        <div class="hb-ai-scroll" data-hb-ai-scroll>
            <div class="hb-ai-header">
                <div class="hb-ai-header__row">
                    <span class="hb-ai-header__badge" aria-hidden="true">
                        <span class="hb-ai-header__badge-icon">@include('heisenberg::components.ui.icon', ['name' => 'sparkle', 'size' => 16])</span>
                    </span>
                    <span class="hb-ai-header__title">{{ __('heisenberg::editor.panel_ai_tools.ai_assistant') }}</span>
                </div>
                <p class="hb-ai-header__sub">{{ __('heisenberg::editor.panel_ai_tools.ai_subtitle') }}</p>
            </div>

            <div class="hb-ai-section">
                <span class="hb-ai-section__title">{{ __('heisenberg::editor.panel_ai_tools.ai_suggestions') }}</span>
                @foreach ($suggestions as $s)
                    <x-ui.suggestion-row :icon="$s['icon']" :icon-color="$s['iconColor']" :label="$s['label']" />
                @endforeach
            </div>

            <div class="hb-ai-section">
                <span class="hb-ai-section__title">{{ __('heisenberg::editor.panel_ai_tools.ai_result') }}</span>
                <div class="hb-ai-response">
                    <p class="hb-ai-response__text">{{ __('heisenberg::editor.panel_ai_tools.ai_response_demo') }}</p>
                    <div class="hb-ai-response__actions">
                        <button type="button" class="hb-ai-action hb-ai-action--insert">
                            <span class="hb-ai-action__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus-circle', 'size' => 14])</span>
                            {{ __('heisenberg::editor.panel_ai_tools.ai_insert') }}
                        </button>
                        <button type="button" class="hb-ai-action hb-ai-action--regenerate">
                            <span class="hb-ai-action__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'arrow-clockwise', 'size' => 14])</span>
                            {{ __('heisenberg::editor.panel_ai_tools.ai_regenerate') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
            <x-ui.custom-scrollbar container="[data-hb-ai-scroll]" />
        </div>

        <div class="hb-ai-prompt-wrap">
            <div class="hb-ai-prompt-bar">
                <input type="text" class="hb-ai-prompt-bar__input" placeholder="{{ __('heisenberg::editor.panel_ai_tools.ai_prompt_ph') }}">
                <button type="button" class="hb-ai-prompt-bar__send" aria-label="{{ __('heisenberg::editor.common.send') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'arrow-up', 'size' => 14])
                </button>
            </div>
        </div>
    </div>

    <div class="hb-panel-ai__content" data-hb-panel-ai-tools hidden>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_ai_tools.search_tools')"
            data-hb-filter="[data-hb-panel-ai-tools]" data-hb-filter-item=".hb-panel-ai__grid > *" />
        <x-ui.category-head :label="__('heisenberg::editor.panel_ai_tools.category_writing')" />
        <div class="hb-panel-ai__toolsbody" data-hb-ai-tools-scroll>
            <div class="hb-panel-ai__grid">
                @foreach ($toolCards as $card)
                    <x-ui.tool-card :icon="$card['icon']" :label="$card['label']" />
                @endforeach
            </div>
            <x-ui.custom-scrollbar container="[data-hb-ai-tools-scroll]" />
        </div>
    </div>
</div>
