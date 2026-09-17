@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-sidebar {
        display: flex;
        flex-direction: column;
        width: 100%;
        height: 100%;
        background: var(--hb-bg);
        border-right: 1px solid var(--hb-border);
        overflow: hidden;
    }
    .hb-sidebar__logo-zone {
        display: flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        height: 32px;
        padding: 0 var(--hb-space-2, 8px);
        border-bottom: 1px solid var(--hb-border);
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
        color: var(--hb-text-primary);
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

    .hb-editor--sidebar-collapsed .hb-sidebar__brand,
    .hb-editor--sidebar-collapsed .hb-navitem span:not(.hb-navitem__icon) {
        display: none;
    }
    .hb-editor--sidebar-collapsed .hb-navitem { justify-content: center; }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const PANEL_SELECTOR = {
            cb: '[data-hb-panel-cb]',
            seo: '[data-hb-panel-seo]',
            style: '[data-hb-panel-style]',
            ai: '[data-hb-panel-ai]',
            nav: '[data-hb-panel-nav]',
        };
        const showPanel = (panelKey, tabIndex) => {
            const selector = PANEL_SELECTOR[panelKey];
            if (!selector) return;
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
            document.dispatchEvent(new CustomEvent('hb:refresh'));
            requestAnimationFrame(() => document.dispatchEvent(new CustomEvent('hb:refresh')));
            const tablist = target.querySelector('[data-hb-tablist]');
            const tab = tablist?.querySelectorAll('[data-hb-tab]')[Number(tabIndex) || 0];
            if (!tab) return;
            if (tablist.__hbTablist) tablist.__hbTablist.activate(tab, false);
            else tab.click();
        };
        window.hbEditorShowPanel = showPanel;

        const NAV_STORE = 'hb-editor:active-nav';

        const activateNav = (btn, persist) => {
            const [panelKey, tabIndex] = (btn.dataset.hbNav || '').split(':');
            if (!PANEL_SELECTOR[panelKey]) return;

            document.querySelectorAll('[data-hb-nav]').forEach((other) => {
                other.classList.toggle('hb-navitem--active', other === btn);
                other.setAttribute('aria-current', other === btn ? 'true' : 'false');
            });

            if (persist) {
                try { localStorage.setItem(NAV_STORE, btn.dataset.hbNav || ''); } catch (e) { }
            }
            showPanel(panelKey, tabIndex);
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-nav]').forEach((btn) => {
                if (btn.__hbNavWired) return;
                btn.__hbNavWired = true;
                btn.addEventListener('click', () => activateNav(btn, true));
            });

            if (!document.__hbNavRestored) {
                document.__hbNavRestored = true;
                let stored = null;
                try { stored = localStorage.getItem(NAV_STORE); } catch (e) { }
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

@props(['documentType' => 'post'])
@php
    $navItems = [
        ['icon' => 'cube-fill', 'label' => __('heisenberg::editor.sidebar.nav_components'), 'panel' => 'cb', 'tab' => 0, 'active' => true],
        ['icon' => 'grid-four-fill', 'label' => __('heisenberg::editor.sidebar.nav_blocks'), 'panel' => 'cb', 'tab' => 1],
        ...($documentType !== 'email' ? [
            ['icon' => 'globe-fill', 'label' => __('heisenberg::editor.sidebar.nav_seo'), 'panel' => 'seo', 'tab' => 0],
            ['icon' => 'share-network-fill', 'label' => __('heisenberg::editor.sidebar.nav_socials'), 'panel' => 'seo', 'tab' => 1],
        ] : []),
        ['icon' => 'palette-fill', 'label' => __('heisenberg::editor.sidebar.nav_style'), 'panel' => 'style', 'tab' => 0],
        ['icon' => 'swatches-fill', 'label' => __('heisenberg::editor.sidebar.nav_themes'), 'panel' => 'style', 'tab' => 1],
        ['icon' => 'magic-wand-fill', 'label' => __('heisenberg::editor.sidebar.nav_ai'), 'panel' => 'ai', 'tab' => 0],
        ['icon' => 'wrench-fill', 'label' => __('heisenberg::editor.sidebar.nav_tools'), 'panel' => 'ai', 'tab' => 1],
    ];
@endphp
<aside {{ $attributes->merge(['class' => 'hb-sidebar']) }}>
    <div class="hb-sidebar__logo-zone">
        <img class="hb-sidebar__logo-mark" src="{{ route('heisenberg.editor.asset.logo') }}" alt="" aria-hidden="true">
        <span class="hb-sidebar__brand">Heisenberg</span>
    </div>
    <nav class="hb-sidebar__nav">
        @foreach ($navItems as $item)
            <x-heisenberg::ui.nav-item
                :icon="$item['icon']"
                :label="$item['label']"
                :active="$item['active'] ?? false"
                data-hb-nav="{{ $item['panel'] }}:{{ $item['tab'] }}"
            />
        @endforeach
    </nav>
</aside>
