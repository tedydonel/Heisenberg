{{-- live/sidebar — logo zone (bottom border) + vertical nav list of 8
     ui/nav-item instances, reused as-is (not duplicated). "Components" carries the real active-state
     override from the source, which is what corrected ui/nav-item's active styling.

     Flagged discrepancy, not silently resolved: the source node is literally 180px, but the document's
     own $sidebar-w variable is 240, and the real page composition (bTaeD) instances this component at
     200px. Three different numbers. Used the concrete node's own value (180) since that's what this
     specific component instance is built at — still needs confirming which is authoritative.

     Collapse behavior is our own addition (no collapsed state exists in the design); the icon-rail
     width (52px) and label-hiding treatment are judgment calls. Toggled by the topbar's "Toggle left panel" button via
     a class on the closest .hb-editor ancestor (see live/topbar's script) — vanilla JS, same reasoning
     as elsewhere: Alpine isn't loaded on this page yet. --}}
@once
<style>
    .hb-sidebar {
        display: flex;
        flex-direction: column;
        width: 100%;
        height: 100%;
        background: var(--hb-bg, #fff);
        border-right: 1px solid var(--hb-border, #E4E4E4);
        overflow: hidden;
    }
    .hb-sidebar__logo-zone {
        display: flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        height: 32px;
        padding: 0 var(--hb-space-2, 8px);
        border-bottom: 1px solid var(--hb-border, #E4E4E4);
        flex: none;
    }
    .hb-sidebar__logo-mark {
        width: 26px;
        height: 26px;
        border-radius: var(--hb-radius-sm, 3px);
        flex: none;
        display: block;
    }
    .hb-sidebar__brand {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-base, 13px);
        font-weight: 600;
        letter-spacing: -.2px;
        color: var(--hb-text-primary, #0A0A0A);
        white-space: nowrap;
    }
    .hb-sidebar__nav {
        display: flex;
        flex-direction: column;
        gap: 1px;
        width: 100%;
        padding: var(--hb-space-2, 8px);
        overflow: hidden;
    }

    /* Collapsed width itself comes from the grid track (--hb-editor-sidebar-w in 20-shell.css,
       responsive per breakpoint) — .hb-sidebar just follows it via width:100%. Only the
       icons-only treatment lives here. */
    .hb-editor--sidebar-collapsed .hb-sidebar__brand,
    .hb-editor--sidebar-collapsed .hb-navitem span:not(.hb-navitem__icon) {
        display: none;
    }
    .hb-editor--sidebar-collapsed .hb-navitem { justify-content: center; }
</style>
<script>
    {{-- Nav → middle-panel switcher. Our own addition — the design shows each screen's static
         state, not a live switcher; this is new interaction wiring on request. Each nav item carries
         data-hb-nav="<panel-key>:<tab-index>"; clicking it hides every panel root, shows the matching
         one, and activates its internal panel-tabs tab via the tablist's own exposed activate() API
         (see ui/partials/tablist-script) so each panel's existing show/hide-content listener fires
         exactly as if the user had clicked that tab directly. --}}
    (() => {
        const PANEL_SELECTOR = {
            cb: '[data-hb-panel-cb]',
            seo: '[data-hb-panel-seo]',
            style: '[data-hb-panel-style]',
            ai: '[data-hb-panel-ai]',
            {{-- Navigator (List View | Outline) — not a rail item; opened by the topbar Layers button
                 via window.hbEditorShowPanel('nav', 0), and hidden again when a rail item switches away. --}}
            nav: '[data-hb-panel-nav]',
        };
        // Show one left child panel (hiding the rest) and activate its tab. Shared with the topbar
        // Layers button so the Navigator opens through the exact same swap the rail items use.
        const showPanel = (panelKey, tabIndex) => {
            const selector = PANEL_SELECTOR[panelKey];
            if (!selector) return;
            // Choosing a panel MEANS "show me that panel" — if the panel area is
            // collapsed, reopen it here instead of making the user find the
            // topbar toggle. Below 1024px the one-open-at-a-time rule applies,
            // same as the topbar's own toggles.
            const shell = document.querySelector('.hb-editor');
            if (shell && shell.classList.contains('hb-editor--panel-collapsed')) {
                if (window.hbSetPanelCollapsed) window.hbSetPanelCollapsed(shell, 'panel', false);
                else shell.classList.remove('hb-editor--panel-collapsed');
                if (window.matchMedia('(max-width: 1023px)').matches && window.hbSetPanelCollapsed) {
                    ['sidebar', 'inspector'].forEach((key) => window.hbSetPanelCollapsed(shell, key, true));
                }
            }
            Object.values(PANEL_SELECTOR).forEach((sel) => {
                const panel = document.querySelector(sel);
                if (panel) panel.hidden = true;
            });
            const target = document.querySelector(selector);
            if (!target) return;
            target.hidden = false;
            // The panel just switched from [hidden] to visible — anything inside it that scrolls
            // (ui/custom-scrollbar) booted while it measured zero and needs to recheck now. Fired
            // once immediately (usually enough — reading a geometry property forces a synchronous
            // layout) and once more a frame later, since [hidden]-attribute-driven reveals haven't
            // reliably settled layout by the immediate read in every engine tested.
            document.dispatchEvent(new CustomEvent('hb:refresh'));
            requestAnimationFrame(() => document.dispatchEvent(new CustomEvent('hb:refresh')));
            const tablist = target.querySelector('[data-hb-tablist]');
            const tab = tablist?.querySelectorAll('[data-hb-tab]')[Number(tabIndex) || 0];
            if (!tab) return;
            if (tablist.__hbTablist) tablist.__hbTablist.activate(tab, false);
            else tab.click();
        };
        window.hbEditorShowPanel = showPanel;

        // The chosen panel survives a refresh: each nav click stores its
        // "<panel>:<tab>" key, and boot() replays it once so the editor reopens
        // where it was left instead of always resetting to Components.
        const NAV_STORE = 'hb-editor:active-nav';

        const activateNav = (btn, persist) => {
            const [panelKey, tabIndex] = (btn.dataset.hbNav || '').split(':');
            if (!PANEL_SELECTOR[panelKey]) return;

            document.querySelectorAll('[data-hb-nav]').forEach((other) => {
                other.classList.toggle('hb-navitem--active', other === btn);
                other.setAttribute('aria-current', other === btn ? 'true' : 'false');
            });

            if (persist) {
                try { localStorage.setItem(NAV_STORE, btn.dataset.hbNav || ''); } catch (e) { /* private mode */ }
            }
            showPanel(panelKey, tabIndex);
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-nav]').forEach((btn) => {
                if (btn.__hbNavWired) return;
                btn.__hbNavWired = true;
                btn.addEventListener('click', () => activateNav(btn, true));
            });

            // Restore once, after the rail is wired. Restoring must not reopen a
            // deliberately collapsed panel area, so the collapse state is stashed
            // and put back around the showPanel call.
            if (!document.__hbNavRestored) {
                document.__hbNavRestored = true;
                let stored = null;
                try { stored = localStorage.getItem(NAV_STORE); } catch (e) { /* private mode */ }
                const btn = stored ? document.querySelector('[data-hb-nav="' + stored.replace(/"/g, '\\"') + '"]') : null;
                if (btn && stored !== 'cb:0') {
                    const shell = document.querySelector('.hb-editor');
                    const wasCollapsed = !!(shell && shell.classList.contains('hb-editor--panel-collapsed'));
                    activateNav(btn, false);
                    if (wasCollapsed && shell) {
                        if (window.hbSetPanelCollapsed) window.hbSetPanelCollapsed(shell, 'panel', true);
                        else shell.classList.add('hb-editor--panel-collapsed');
                    }
                }
            }
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@php
    $navItems = [
        ['icon' => 'cube-fill', 'label' => __('heisenberg::editor.sidebar.nav_components'), 'panel' => 'cb', 'tab' => 0, 'active' => true],
        ['icon' => 'grid-four-fill', 'label' => __('heisenberg::editor.sidebar.nav_blocks'), 'panel' => 'cb', 'tab' => 1],
        ['icon' => 'globe-fill', 'label' => __('heisenberg::editor.sidebar.nav_seo'), 'panel' => 'seo', 'tab' => 0],
        ['icon' => 'share-network-fill', 'label' => __('heisenberg::editor.sidebar.nav_socials'), 'panel' => 'seo', 'tab' => 1],
        ['icon' => 'palette-fill', 'label' => __('heisenberg::editor.sidebar.nav_style'), 'panel' => 'style', 'tab' => 0],
        ['icon' => 'swatches-fill', 'label' => __('heisenberg::editor.sidebar.nav_themes'), 'panel' => 'style', 'tab' => 1],
        ['icon' => 'magic-wand-fill', 'label' => __('heisenberg::editor.sidebar.nav_ai'), 'panel' => 'ai', 'tab' => 0],
        ['icon' => 'wrench-fill', 'label' => __('heisenberg::editor.sidebar.nav_tools'), 'panel' => 'ai', 'tab' => 1],
    ];
@endphp
<aside {{ $attributes->merge(['class' => 'hb-sidebar']) }}>
    <div class="hb-sidebar__logo-zone">
        <img class="hb-sidebar__logo-mark" src="/heisenberg-assets/editor-logo.svg" alt="" aria-hidden="true">
        <span class="hb-sidebar__brand">Heisenberg</span>
    </div>
    <nav class="hb-sidebar__nav">
        @foreach ($navItems as $item)
            <x-ui.nav-item
                :icon="$item['icon']"
                :label="$item['label']"
                :active="$item['active'] ?? false"
                data-hb-nav="{{ $item['panel'] }}:{{ $item['tab'] }}"
            />
        @endforeach
    </nav>
</aside>
