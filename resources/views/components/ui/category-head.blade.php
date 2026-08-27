@once

<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-category-head]').forEach((head) => {
                if (head.__hbCategoryHead) return;
                head.addEventListener('click', () => {
                    const wasExpanded = head.getAttribute('aria-expanded') === 'true';
                    head.setAttribute('aria-expanded', wasExpanded ? 'false' : 'true');
                    const body = head.nextElementSibling;
                    if (body?.hasAttribute('data-hb-category-body')) body.hidden = wasExpanded;
                    head.dispatchEvent(new CustomEvent('toggle', { bubbles: true, detail: { collapsed: wasExpanded } }));
                });
                head.__hbCategoryHead = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props(['label' => '', 'collapsed' => false])
<button
    type="button"
    data-hb-category-head
    aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
    {{ $attributes->merge(['class' => 'hb-categoryhead']) }}
>
    <span class="hb-categoryhead__label">{{ $label }}</span>
    <span class="hb-categoryhead__chevron hb-categoryhead__chevron--expanded" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => 'caret-up', 'size' => 11])
    </span>
    <span class="hb-categoryhead__chevron hb-categoryhead__chevron--collapsed" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 11])
    </span>
</button>
