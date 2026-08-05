{{-- ui/category-head — from Pencil Category Head Cmp (R6fIG3): 28px row, uppercase eyebrow label +
     chevron. Source shows the expanded instance (caret-up); collapsed flips to caret-down, the standard
     pairing. Click flips the `collapsed` visual state, dispatches a 'toggle' CustomEvent (bubbles, with
     `detail.collapsed` = the state being left), AND — same contract as ui/disclosure-row — toggles a
     sibling [data-hb-category-body] element's `hidden` via the global `.hb-editor [hidden]` rule
     (01-reset.css). Any caller opts in by marking its content sibling with that attribute; nothing
     else to wire. The 'toggle' event still fires regardless, for anything that wants to react beyond
     show/hide. --}}
@once
<style>
    .hb-categoryhead {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 28px;
        padding: 0 12px;
        border: 0;
        background: transparent;
        cursor: pointer;
        flex: none;
    }
    .hb-categoryhead__label {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 600;
        letter-spacing: .8px;
        text-transform: uppercase;
        color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-categoryhead__chevron { display: inline-flex; width: 11px; height: 11px; color: var(--hb-text-muted, #9A9A9A); }
    .hb-categoryhead__chevron--collapsed { display: none; }
    .hb-categoryhead[aria-expanded="false"] .hb-categoryhead__chevron--expanded { display: none; }
    .hb-categoryhead[aria-expanded="false"] .hb-categoryhead__chevron--collapsed { display: inline-flex; }
</style>
<script>
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
