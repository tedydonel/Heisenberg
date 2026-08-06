{{-- ui/disclosure-row — a row pattern repeated 7x in that one panel —
     icon + label left, chevron right, optional top border. Two chevron directions carry different
     semantics in the source: "down" = collapsible section header (this component toggles a
     sibling [data-hb-disclosure-body] closed/open, chevron flips) — every Post-tab row now uses
     this (Featured image, Summary, Categories, Tags, Discussion, Page layout all have real
     content to expand into as of 2026-08-03); "right" = navigation row to a destination screen
     that doesn't exist in this app, kept available for a future row that genuinely needs one.

     `persistKey` (optional, 2026-08-03): opts a "down" row into remembering the user's own
     open/closed choice across page loads via localStorage — the `expanded` prop only decides the
     FIRST-ever visit's state (and every load until localStorage actually has something written to
     it); once the user toggles it, that choice wins from then on. Plain client-side state, nothing
     server-persisted — consistent with this app's no-Livewire/no-backend-round-trip-for-UI-state
     posture elsewhere (e.g. ui/custom-scrollbar, ui/panel-tabs). --}}
@once
<style>
    .hb-disclosure {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        box-sizing: border-box;
        border: 0;
        background: transparent;
        cursor: pointer;
        text-align: left;
    }
    .hb-disclosure--border { border-top: 1px solid var(--hb-border, #E4E4E4); }
    .hb-disclosure__left { display: inline-flex; align-items: center; gap: 10px; min-width: 0; }
    .hb-disclosure__icon { display: inline-flex; width: 15px; height: 15px; color: var(--hb-text-secondary, #5A5A5A); flex: none; }
    .hb-disclosure__label {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 13px;
        font-weight: 500;
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-disclosure__chevron { display: inline-flex; width: 13px; height: 13px; color: var(--hb-text-muted, #9A9A9A); flex: none; transition: transform .12s ease; }
    .hb-disclosure:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-disclosure[aria-expanded="false"] .hb-disclosure__chevron { transform: rotate(-90deg); }
</style>
<script>
    (() => {
        const storageKey = (key) => 'hb:disclosure:' + key;
        // Private-browsing Safari (and similar) throws on localStorage access rather than just
        // no-opping — every call is wrapped so a row with no persistence support degrades to
        // exactly the old expanded-prop-only behavior instead of breaking the click handler.
        const readPersisted = (key) => {
            try { return window.localStorage.getItem(storageKey(key)); } catch (e) { return null; }
        };
        const writePersisted = (key, expanded) => {
            try { window.localStorage.setItem(storageKey(key), expanded ? 'true' : 'false'); } catch (e) { /* ignore */ }
        };

        const setState = (row, body, expanded) => {
            row.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (body?.hasAttribute('data-hb-disclosure-body')) body.hidden = !expanded;
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-disclosure]').forEach((row) => {
                if (row.__hbDisclosure) return;
                const body = row.nextElementSibling;
                const persistKey = row.dataset.hbPersistKey || null;

                if (persistKey) {
                    const stored = readPersisted(persistKey);
                    if (stored !== null) setState(row, body, stored === 'true');
                }

                row.addEventListener('click', () => {
                    const expanded = row.getAttribute('aria-expanded') === 'true';
                    setState(row, body, !expanded);
                    if (persistKey) writePersisted(persistKey, !expanded);
                });
                row.__hbDisclosure = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'icon' => 'circle',
    'label' => '',
    'chevron' => 'right',
    'border' => true,
    'expanded' => true,
    'height' => 32,
    'persistKey' => null,
])
<button
    type="button"
    @if ($chevron === 'down') data-hb-disclosure aria-expanded="{{ $expanded ? 'true' : 'false' }}" @if ($persistKey) data-hb-persist-key="{{ $persistKey }}" @endif @endif
    {{ $attributes->merge(['class' => 'hb-disclosure' . ($border ? ' hb-disclosure--border' : ''), 'style' => "height:{$height}px;padding:0 var(--hb-space-3, 12px);"]) }}
>
    <span class="hb-disclosure__left">
        <span class="hb-disclosure__icon" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 15])
        </span>
        <span class="hb-disclosure__label">{{ $label }}</span>
    </span>
    <span class="hb-disclosure__chevron" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $chevron === 'down' ? 'caret-down' : 'caret-right', 'size' => 13])
    </span>
</button>
