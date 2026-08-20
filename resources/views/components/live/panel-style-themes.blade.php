{{-- live/panel-style-themes — Middle panel, 240px (same
     240-vs-260 note as live/panel-components-blocks).
     Style tab: 5 token-editor sections (Colors/Radius/Spacing/Fonts/Font sizes), each a real repeated
     row pattern from the source — Colors uses ui/swatch + ui/input (name only); Radius/Spacing/Font
     sizes use two ui/input fields (name+value) + a remove icon. Fonts is the one exception to that
     name+value shape (2026-08-04): a font's family (e.g. "Rubik") is already a human-readable name,
     so a second free-text nickname field was pure redundancy — it's just ui/combobox (family) alone,
     and the family value doubles as the token's label (see collectTheme()/buildRow() below). Widths
     differ per section in the source itself (not my choice) — Radius/Fonts/Font-sizes: name=70,
     value=fill; Spacing: name=fill, value=80. Font sizes is the last section and has no border-bottom
     + a wider padding (space-4 not space-3) in the source — kept as-is, not treated as a copy-paste
     oversight. A "Save to Themes" bar follows it (our own addition, not in the design).
     Rows are real ThemeRepository data — every field reads from and writes back to
     GET/PUT /editor/theme (ThemeController). The single visible text field
     per row (Fonts excepted) edits the token's human `label`; its machine `name` (the `--hb-t-*` CSS
     variable, kebab-case, must stay stable so nothing referencing it silently breaks) rides along in
     data-hb-token-name and is generated once, at Add time. Colors' swatch opens the app's OWN color
     picker (live/pickers/color-picker, the same component the per-block Fill/Stroke panels use) in a
     floating popup — not a native <input type=color> — so editing a theme colour looks and behaves
     like editing any other colour in the editor; the token's current hex rides in data-hb-token-color
     on the row (the picker only ever hands back a flat hex via its `colorchange` event, even if its
     Gradient tab is poked, so a theme token can never accidentally become an actual gradient string).
     Fonts' family field is ui/combobox (2026-08-03, a search-first sibling of ui/select — see its own
     docblock for why it's a separate component): typing dispatches a `search` event this file listens
     for, fetches GET /editor/fonts (FontController::search, the vendored Google Fonts catalog, not a
     static list), and calls the combobox's own replaceOptions() — no bespoke dropdown widget. Any edit
     (typing, add, remove, swatch, font pick, preset/saved-theme apply) schedules one debounced PUT of
     the whole theme; ThemeRepository re-validates everything server-side regardless of client-side
     care. "Save to Themes" snapshots the CURRENT in-DOM theme (not the last-saved-to-disk one, so an
     unsaved edit is never silently dropped) under a user-typed name via POST /editor/themes
     (SavedThemeController) — distinct from the single active theme PUT above.
     Themes tab: search field pinned above one shared scroll region holding two card grids — "Your
     themes" (live, user-saved, 2026-08-03: server-seeded from SavedThemeRepository, refreshed
     client-side after every save/delete, each card deletable) above the original "Presets" (search +
     "Presets" category head + a 6-card grid, the source's actual named palettes: Default/Midnight/
     Sunset/Ocean/Forest/Blush). Clicking a PRESET overwrites the first 3 color rows' values (a preset
     only ever ships 3 colors) — it doesn't invent extra semantic roles the source palettes don't
     define. Clicking a SAVED theme fully replaces all 5 token sections (it has real data for all of
     them) and switches back to the Style tab so the result is visible, then saves as the active theme. --}}
@once
@include('heisenberg::components.live.panel-style-themes.script-style-themes')
@endonce

@props([
    // ThemeRepository::load()'s shape — colors/fontSizes/spaces/radii/fonts, each a list of
    // {name,label,value} (fonts also carry family/weights). Real saved data, not fixtures
    // (2026-08-03) — see EditorController::sharedViewData().
    'theme' => [],
    // SavedThemeRepository::all()'s shape — list of {name, theme} (2026-08-03).
    'savedThemes' => [],
    'themeUpdateUrl' => '',
    'fontsSearchUrl' => '',
    'themesStoreUrl' => '',
    'themesDestroyUrl' => '',
    // FontCatalogService's popular-head, as [{value,label}] — seeds the Fonts field's ui/select so
    // it never opens empty before a keystroke (2026-08-03).
    'fontOptions' => [],
])

@php
    $colors = $theme['colors'] ?? [];
    $radii = $theme['radii'] ?? [];
    $spacings = $theme['spaces'] ?? [];
    $fonts = $theme['fonts'] ?? [];
    $fontSizes = $theme['fontSizes'] ?? [];
    $themePresets = [
        ['label' => 'Default', 'colors' => ['#FFFFFF', '#000000', '#0A0A0A']],
        ['label' => 'Midnight', 'colors' => ['#12141C', '#5B8DEF', '#E8EAF0']],
        ['label' => 'Sunset', 'colors' => ['#FFF6ED', '#E8703A', '#3A2A1E']],
        ['label' => 'Ocean', 'colors' => ['#EFF6FB', '#1AA7A0', '#12324A']],
        ['label' => 'Forest', 'colors' => ['#F1F5EE', '#5B8C3E', '#23331F']],
        ['label' => 'Blush', 'colors' => ['#FDF1F4', '#D65F86', '#401A2B']],
    ];
    // Vanilla JS in this panel reads these via JSON.parse(root.dataset.hbPanelStyleStrings)
    // to render the "Update :name" / "Save to Themes" labels on the save bar without needing
    // a locale-aware runtime. The same pattern is used by the navigator and comments panels
    // (see data-hb-nav-strings / data-hb-comments-strings).
    $hbPanelStyleStrings = [
        'save_to_themes' => __('heisenberg::editor.panel_style_themes.save_to_themes'),
        'update_theme' => __('heisenberg::editor.panel_style_themes.update_theme'),
        'update_theme_aria' => __('heisenberg::editor.panel_style_themes.update_theme_aria'),
    ];
@endphp
<div data-hb-panel-style
    data-hb-theme-update-url="{{ $themeUpdateUrl }}"
    data-hb-fonts-search-url="{{ $fontsSearchUrl }}"
    data-hb-themes-store-url="{{ $themesStoreUrl }}"
    data-hb-themes-destroy-url="{{ $themesDestroyUrl }}"
    data-hb-panel-style-strings="{{ json_encode($hbPanelStyleStrings, JSON_UNESCAPED_SLASHES) }}"
    {{ $attributes->merge(['class' => 'hb-panel-style']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_style_themes.tab_style')], ['label' => __('heisenberg::editor.panel_style_themes.tab_themes')]]" :active-index="0" />

    <div class="hb-panel-style__content" data-hb-panel-style-style>
        {{-- Two-layer scroll shell (same reasoning as the Themes tab's own [data-hb-themes-scroll]
             below, and live/panel-components-blocks.blade.php's [data-hb-panel-cb-scroll]): the
             scrollbar's `container` must be an element that is NOT also the scrollbar bar's own
             direct parent-for-positioning-purposes conflated with content — this inner wrapper is
             purely the JS-tracked scroll region; the outer [data-hb-panel-style-style] is just the
             flex slot + position:relative anchor the scrollbar bar itself sits in. --}}
        <div class="hb-panel-style__scroll" data-hb-style-scroll>
        <div class="hb-token-section" data-hb-token-section-body="colors">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_colors') }}</span>
            @foreach ($colors as $c)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="colors" data-hb-token-name="{{ $c['name'] }}" data-hb-token-color="{{ $c['value'] }}">
                    <x-ui.swatch :color="$c['value']" size="26" data-hb-token-swatch />
                    <x-ui.input :value="$c['label']" width="100%" data-hb-token-field="label" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="colors">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_color') }}</span>
            </button>
        </div>
        <template data-hb-token-template="colors">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="colors" data-hb-token-name="" data-hb-token-color="#000000">
                <x-ui.swatch color="#000000" size="26" data-hb-token-swatch />
                <x-ui.input value="" width="100%" data-hb-token-field="label" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section" data-hb-token-section-body="radii">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_radius') }}</span>
            @foreach ($radii as $r)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="radii" data-hb-token-name="{{ $r['name'] }}">
                    <x-ui.input :value="$r['label']" width="70px" data-hb-token-field="label" />
                    <x-ui.input :value="$r['value']" width="100%" data-hb-token-field="value" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="radii">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_radius') }}</span>
            </button>
        </div>
        <template data-hb-token-template="radii">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="radii" data-hb-token-name="">
                <x-ui.input value="" width="70px" data-hb-token-field="label" />
                <x-ui.input value="" width="100%" data-hb-token-field="value" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section" data-hb-token-section-body="spaces">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_spacing') }}</span>
            @foreach ($spacings as $s)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="spaces" data-hb-token-name="{{ $s['name'] }}">
                    <x-ui.input :value="$s['label']" width="100%" data-hb-token-field="label" />
                    <x-ui.input :value="$s['value']" width="80px" data-hb-token-field="value" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="spaces">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_spacing') }}</span>
            </button>
        </div>
        <template data-hb-token-template="spaces">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="spaces" data-hb-token-name="">
                <x-ui.input value="" width="100%" data-hb-token-field="label" />
                <x-ui.input value="" width="80px" data-hb-token-field="value" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section" data-hb-token-section-body="fonts">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_fonts') }}</span>
            @foreach ($fonts as $f)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="fonts" data-hb-token-name="{{ $f['name'] }}" data-hb-token-weights="{{ json_encode($f['weights'] ?? [400]) }}">
                    <x-ui.combobox :options="$fontOptions" :value="$f['family']"
                        :placeholder="__('heisenberg::editor.panel_style_themes.select_font_ph')"
                        style="width:100%;" data-hb-token-field="family" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="fonts">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_font') }}</span>
            </button>
        </div>
        <template data-hb-token-template="fonts">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="fonts" data-hb-token-name="" data-hb-token-weights="[400]">
                <x-ui.combobox :options="$fontOptions" value=""
                    :placeholder="__('heisenberg::editor.panel_style_themes.select_font_ph')"
                    style="width:100%;" data-hb-token-field="family" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section hb-token-section--last" data-hb-token-section-body="fontSizes">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_font_sizes') }}</span>
            @foreach ($fontSizes as $fs)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="fontSizes" data-hb-token-name="{{ $fs['name'] }}">
                    <x-ui.input :value="$fs['label']" width="70px" data-hb-token-field="label" />
                    <x-ui.input :value="$fs['value']" width="100%" data-hb-token-field="value" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="fontSizes">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_size') }}</span>
            </button>
        </div>
        <template data-hb-token-template="fontSizes">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="fontSizes" data-hb-token-name="">
                <x-ui.input value="" width="70px" data-hb-token-field="label" />
                <x-ui.input value="" width="100%" data-hb-token-field="value" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-savebar" data-hb-theme-savebar>
            <button type="button" class="hb-token-add" data-hb-theme-save-open>
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'bookmark', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.save_to_themes') }}</span>
            </button>
            <div class="hb-token-saveform" data-hb-theme-saveform hidden>
                <x-ui.input placeholder="{{ __('heisenberg::editor.panel_style_themes.save_theme_name_ph') }}" width="100%" data-hb-theme-save-name />
                <button type="button" class="hb-token-saveform__confirm" data-hb-theme-save-confirm aria-label="{{ __('heisenberg::editor.panel_style_themes.save_theme_confirm_aria') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])
                </button>
                <button type="button" class="hb-token-saveform__cancel" data-hb-theme-save-cancel aria-label="{{ __('heisenberg::editor.common.cancel') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 13])
                </button>
            </div>
            <span class="hb-token-saveform__error" data-hb-theme-save-error hidden></span>
        </div>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-style-scroll]" />
    </div>

    <div class="hb-panel-style__content" data-hb-panel-style-themes hidden>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_style_themes.search_themes')"
            data-hb-filter="[data-hb-panel-style-themes]" data-hb-filter-item="[data-hb-saved-theme], [data-hb-theme-preset]" />

        <div class="hb-panel-style__scroll" data-hb-themes-scroll>
            <x-ui.category-head :label="__('heisenberg::editor.panel_style_themes.category_your_themes')" />
            <div class="hb-panel-style__grid" data-hb-saved-themes-grid>
                @foreach ($savedThemes as $saved)
                    <div class="hb-themepresetcard-wrap" data-hb-saved-theme data-hb-saved-theme-name="{{ $saved['name'] }}" data-hb-saved-theme-payload="{{ json_encode($saved['theme']) }}">
                        <x-ui.theme-preset-card :colors="array_slice(array_column($saved['theme']['colors'] ?? [], 'value'), 0, 3)" :label="$saved['name']" />
                        <button type="button" class="hb-saved-theme-delete" data-hb-saved-theme-delete aria-label="{{ __('heisenberg::editor.panel_style_themes.delete_theme_aria') }}">
                            @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 12])
                        </button>
                    </div>
                @endforeach
                <span class="hb-token-add__label" data-hb-saved-themes-empty @if (count($savedThemes)) hidden @endif>{{ __('heisenberg::editor.panel_style_themes.no_saved_themes') }}</span>
            </div>

            <x-ui.category-head :label="__('heisenberg::editor.panel_style_themes.category_presets')" />
            <div class="hb-panel-style__grid">
                @foreach ($themePresets as $preset)
                    <x-ui.theme-preset-card :colors="$preset['colors']" :label="$preset['label']"
                        data-hb-theme-preset :data-hb-theme-preset-colors="json_encode($preset['colors'])" />
                @endforeach
            </div>

            {{-- Deliberately AFTER the unconditional Presets loop above, not before it: content
                 inside <template> is inert (browsers never apply styles/scripts from it), so if
                 ui/theme-preset-card's own @once <style> block fired for the FIRST time in here —
                 which it would, whenever $savedThemes is empty, since this would otherwise be the
                 first instance of the component on the page — that stylesheet would never actually
                 reach the live document and every real card (Presets included) would render
                 unstyled. Presets is never empty, so putting it first guarantees @once always fires
                 from a real, rendered card. --}}
            <template data-hb-saved-theme-template>
                <div class="hb-themepresetcard-wrap" data-hb-saved-theme data-hb-saved-theme-name="" data-hb-saved-theme-payload="">
                    <x-ui.theme-preset-card :colors="['#FFFFFF', '#FFFFFF', '#FFFFFF']" label="" />
                    <button type="button" class="hb-saved-theme-delete" data-hb-saved-theme-delete aria-label="{{ __('heisenberg::editor.panel_style_themes.delete_theme_aria') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 12])
                    </button>
                </div>
            </template>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-themes-scroll]" />
    </div>

    <div class="hb-style-popup" data-hb-token-colorpicker-popup hidden>
        <x-live.pickers.color-picker mode="fill" value="#000000" />
    </div>
</div>
