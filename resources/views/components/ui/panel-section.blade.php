{{-- ui/section — inspector section (from the .pen section frames in LtsDN/cQkqr/wt2Gk):
     a $space-3-padded block with a 13px/600 secondary-colored title and an optional
     right-hand action slot, divided from the previous section by a top hairline.
     `collapsible` adds a caret and hides the body (Content/Advanced tabs use it; the
     Style tab's sections don't). Distinct from ui/category-head, which is the small
     uppercase eyebrow used by the inserter.

     Vanilla JS, not Alpine: Alpine is not loaded on /editor (no @vite, no Alpine script,
     no @livewireScripts, and /editor renders no Livewire component — see live/sidebar's
     own note making this same call). A prior commit added x-data/x-on/x-show here anyway,
     which left the caret permanently dead; this is the fix, in the same
     @once + document-delegated-listener idiom used everywhere else in the editor. A single
     delegated click listener (not a per-node boot()) is used deliberately — this component's
     consumers (the Block-tab panels) render many instances up front and toggle their
     visibility rather than being rebuilt, but a delegated listener needs no re-wiring
     either way, so it's the simplest correct option. --}}
@once
<style>
    .hb-section { display: flex; flex-direction: column; gap: 10px; padding: var(--hb-space-3, 12px); border-top: 1px solid var(--hb-border, #E4E4E4); }
    .hb-section:first-child { border-top: 0; }
    .hb-section__head { display: flex; align-items: center; justify-content: space-between; gap: var(--hb-space-2, 8px); min-height: 20px; }
    .hb-section__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 600; color: var(--hb-text-secondary, #5A5A5A); }
    .hb-section__right { display: inline-flex; align-items: center; gap: 4px; color: var(--hb-text-muted, #9A9A9A); }
    .hb-section__caret { display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; padding: 0; border: 0; background: none; cursor: pointer; color: var(--hb-text-muted, #9A9A9A); }
    .hb-section__caret svg { transition: transform .12s ease; }
    .hb-section__body { display: flex; flex-direction: column; gap: 10px; }
    /* Author `display:flex` beats the UA stylesheet's `[hidden] { display: none }` at equal
       specificity (author always wins over user-agent origin) — Alpine's x-show used to hide this
       via an inline style instead, which always wins regardless. Now that it's a plain `hidden`
       attribute toggle, this needs the same explicit override already used elsewhere in the
       editor for the same reason (e.g. inspector.blade.php's .hb-inspector__block-content[hidden]). */
    .hb-section__body[hidden] { display: none; }
</style>
<script>
    (() => {
        const boot = () => {
            if (document.__hbSectionWired) return;
            document.__hbSectionWired = true;
            document.addEventListener('click', (event) => {
                const caret = event.target.closest('[data-hb-section-toggle]');
                if (!caret) return;
                const section = caret.closest('[data-hb-section]');
                const body = section ? section.querySelector('[data-hb-section-body]') : null;
                if (!body) return;
                const open = caret.getAttribute('aria-expanded') !== 'true';
                caret.setAttribute('aria-expanded', open ? 'true' : 'false');
                body.hidden = !open;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
    })();
</script>
@endonce

@props(['title' => '', 'collapsible' => false, 'open' => true])
<section data-hb-section {{ $attributes->merge(['class' => 'hb-section']) }}>
    <div class="hb-section__head">
        <span class="hb-section__title">{{ $title }}</span>
        @if ($collapsible || isset($action))
            <span class="hb-section__right">
                @isset($action){{ $action }}@endisset
                @if ($collapsible)
                    <button type="button" class="hb-section__caret" data-hb-section-toggle aria-expanded="{{ $open ? 'true' : 'false' }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 12])
                    </button>
                @endif
            </span>
        @endif
    </div>
    <div class="hb-section__body" data-hb-section-body @if ($collapsible && ! $open) hidden @endif>{{ $slot }}</div>
</section>
