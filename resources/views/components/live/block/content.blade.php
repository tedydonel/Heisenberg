{{-- live/block/content — Block.content (cQkqr). Driven by the block contract's
     `attributes`: controls whose control.section is `settings` render in the block's
     own titled section, `general` render in the General section (id / title / class).
     Shown here for a heading-like block; the same structure fills from any contract. --}}
@props([
    'blockTitle' => 'Block',
    'settings' => [],
    'classes' => [],
])
<div class="hb-blockcontent">
    <x-ui.panel-section :title="$blockTitle" collapsible>
        @foreach ($settings as $field)
            <div class="hb-icol">
                <span class="hb-ilbl">{{ $field['label'] ?? '' }}</span>
                @if (($field['type'] ?? 'text') === 'select')
                    <x-ui.select :options="$field['options'] ?? []" :value="$field['value'] ?? ''"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="select" />
                @elseif (($field['type'] ?? 'text') === 'textarea')
                    <x-ui.text-area :value="$field['value'] ?? ''" width="100%"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="text" />
                @elseif (($field['type'] ?? 'text') === 'number')
                    <x-ui.number-stepper :value="$field['value'] ?? 0"
                        :min="$field['min'] ?? null" :max="$field['max'] ?? null" :step="$field['step'] ?? 1"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="number" />
                @else
                    <x-ui.input :value="$field['value'] ?? ''"
                        data-hb-control="{{ $field['key'] ?? '' }}" data-hb-control-kind="attributes" data-hb-control-type="{{ $field['type'] ?? 'text' }}" />
                @endif
            </div>
        @endforeach
    </x-ui.panel-section>

    {{-- General — the three contract attributes every block declares under control.section
         "general": `anchor` (the element id), `titleAttr` (the title tooltip) and `extraClasses`
         (extra classes on the block root). All three are already consumed by both shipped
         contracts' render.template, so the data path existed end to end; these three inputs were
         simply never attached to it (docs/inspector-composition.md §3). Wired 2026-08-04.

         Unlike the settings section above, this is NOT built from the contract — the three keys
         are fixed, because `render.template` references them by name. A contract that renames
         them loses this section rather than getting a renamed one.

         Labelled "Anchor" rather than "Id": the field IS the HTML id attribute, but "Id" read as
         internal/technical and the field went undiscovered — authors reach for it through the
         TOC dialog's "jump to" language, not through "id". The hint line ties the two together.
         data-hb-anchor-warning is presentation-only (see inspector.blade.php's anchor-specific
         input/focusout listeners, just below handleControlEvent): a duplicate-id nudge and a
         gentle blur-time normalize to the server's `/^[A-Za-z][\w-]*$/` anchor shape, neither of
         which touch the model write path above. --}}
    <x-ui.panel-section title="General" collapsible>
        <div class="hb-irow hb-irow--top">
            <div class="hb-icol">
                <span class="hb-ilbl">Anchor</span>
                <x-ui.input value="" placeholder="section-anchor"
                    data-hb-control="anchor" data-hb-control-kind="attributes" data-hb-control-type="text" />
                <span class="hb-ihint">Links and the table of contents jump to this anchor.</span>
                <span class="hb-ihint hb-ihint--warning" data-hb-anchor-warning hidden>Another block already uses this anchor.</span>
            </div>
            <div class="hb-icol">
                <span class="hb-ilbl">Title</span>
                <x-ui.input value="" placeholder="Tooltip text"
                    data-hb-control="titleAttr" data-hb-control-kind="attributes" data-hb-control-type="text" />
            </div>
        </div>
        {{-- `extraClasses` is a single space-separated string in the model (contract type
             "string", sanitize "text") presented as chips (contract control type "chips").
             syncControls rebuilds the chip list from that string; Enter in the input appends,
             a chip's close button removes. See inspector.blade.php's chips handling. --}}
        <div class="hb-icol">
            <span class="hb-ilbl">Class</span>
            <div class="hb-classchips" data-hb-control="extraClasses" data-hb-control-kind="attributes" data-hb-control-type="chips">
                <div class="hb-chips" data-hb-chip-list>
                    @foreach ($classes as $cls)
                        <x-ui.chip :label="$cls" />
                    @endforeach
                </div>
                <input type="text" class="hb-classchips__input" placeholder="Add class…" aria-label="Add class" data-hb-chip-input>
            </div>
            {{-- Prototype cloned per chip, so the rendered markup stays 1:1 with ui/chip rather
                 than being hand-rolled in JS.

                 Deliberately a hidden real element and NOT a <template>: ui/chip carries its own
                 @once <style>/<script>, and the loop above is the page's only other chip render
                 — over a prop nothing passes, so it never executes. A <template> here would
                 therefore be the FIRST chip render on /editor, putting the @once blocks inside
                 template.content, where browsers neither apply the CSS nor run the script. Every
                 chip on the page would render unstyled. This is the same trap
                 ui/theme-preset-card hit; there the fix was ordering, here it is
                 avoiding <template> altogether.

                 It sits OUTSIDE .hb-classchips so chipValues() — which scans [data-hb-chip]
                 within the host — never counts the prototype as a real class. --}}
            <span data-hb-chip-prototype hidden aria-hidden="true"><x-ui.chip label="" /></span>
        </div>
    </x-ui.panel-section>
</div>
@once
<style>
    .hb-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .hb-chips:empty { display: none; }
    .hb-classchips { display: flex; flex-direction: column; gap: 6px; }
    .hb-classchips__input {
        width: 100%;
        height: 30px;
        box-sizing: border-box;
        padding: 0 10px;
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg, #fff);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary, #0A0A0A);
        transition: border-color .12s ease;
    }
    .hb-classchips__input:focus { outline: none; border-color: var(--hb-border-focus, #000); }
    .hb-ihint {
        display: block;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 10px;
        line-height: 1.4;
        color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-ihint--warning { color: var(--hb-danger, #D4191A); }
    /* Author rules always beat the UA [hidden] default (different cascade origin, so
       specificity can't settle it) — the explicit override here is the same fix this file's
       own .hb-chips:empty neighbours rely on elsewhere in the panel. */
    .hb-ihint[hidden] { display: none; }
    [data-hb-control="anchor"].hb-input--warning { border-color: var(--hb-danger, #D4191A); }
</style>
@endonce
