{{-- Shared behavior for the tab family (ui/tabs, ui/panel-tabs, ui/sub-tabs, ui/segmented): click
     switches the active tab, ArrowLeft/ArrowRight/Home/End navigate with automatic activation
     (standard WAI-ARIA tablist pattern), each switch dispatches a bubbling 'change' event with the
     new index. Named @once key so it's safe to @include from every tab-family component without
     emitting the script more than once per page. --}}
@once('hb-tablist-core')
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-tablist]').forEach((root) => {
                if (root.__hbTablist) return;
                const tabs = () => Array.from(root.querySelectorAll('[data-hb-tab]'));

                const activate = (tab, focus = true) => {
                    tabs().forEach((t) => t.setAttribute('aria-selected', 'false'));
                    tab.setAttribute('aria-selected', 'true');
                    if (focus) tab.focus();
                    const index = tabs().indexOf(tab);
                    root.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { index, value: tab.dataset.hbTab } }));
                    // Whatever this switch just revealed (its own [hidden] toggle happens in the
                    // listener that owns that, e.g. panel-style-themes.blade.php's own 'change'
                    // handler) may contain a scroll region that booted while hidden and measured
                    // zero — nudge every custom-scrollbar on the page to recheck its real size now.
                    // Twice: immediately, and once more next frame in case the [hidden] toggle above
                    // hasn't fully settled layout by the time the immediate one reads geometry.
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    requestAnimationFrame(() => document.dispatchEvent(new CustomEvent('hb:refresh')));
                };

                tabs().forEach((tab) => {
                    tab.addEventListener('click', () => activate(tab, false));
                    tab.addEventListener('keydown', (event) => {
                        const list = tabs();
                        const i = list.indexOf(tab);
                        if (event.key === 'ArrowRight') { event.preventDefault(); activate(list[(i + 1) % list.length]); }
                        else if (event.key === 'ArrowLeft') { event.preventDefault(); activate(list[(i - 1 + list.length) % list.length]); }
                        else if (event.key === 'Home') { event.preventDefault(); activate(list[0]); }
                        else if (event.key === 'End') { event.preventDefault(); activate(list[list.length - 1]); }
                    });
                });

                root.__hbTablist = { activate };
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
