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
