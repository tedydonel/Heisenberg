@once

<script>
    (() => {
        const storageKey = (key) => 'hb:disclosure:' + key;
        const readPersisted = (key) => {
            try { return window.localStorage.getItem(storageKey(key)); } catch (e) { return null; }
        };
        const writePersisted = (key, expanded) => {
            try { window.localStorage.setItem(storageKey(key), expanded ? 'true' : 'false'); } catch (e) { }
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
    @if ($chevron !== 'none')
    <span class="hb-disclosure__chevron" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $chevron === 'down' ? 'caret-down' : 'caret-right', 'size' => 13])
    </span>
    @endif
</button>
