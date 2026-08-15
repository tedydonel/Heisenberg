{{-- ui/section — inspector section:
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
