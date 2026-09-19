@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-sidebar {
        display: flex;
        flex-direction: column;
        width: 180px !important;
        max-width: 180px !important;
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
        max-width: 180px;
        padding: var(--hb-space-2, 8px);
        overflow: hidden;
    }

</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const PANEL_SELECTOR = {
            cb: '[data-hb-panel-cb]',
            seo: '[data-hb-panel-seo]',
            style: '[data-hb-panel-style]',
            ai: '[data-hb-panel-ai]',
            vars: '[data-hb-panel-email-variables]',
            nav: '[data-hb-panel-nav]',
        };
        const showPanel = (panelKey, tabIndex) => {
            const selector = PANEL_SELECTOR[panelKey];
            if (!selector) return;
            const shell = document.querySelector('.hb-editor');
            if (shell && window.hbSetPanelState) {
                if (window.matchMedia('(max-width: 1024px)').matches) {
                    window.hbSetPanelState(shell, 'sidebar', false);
                }
                window.hbSetPanelState(shell, 'panel', true);
            }
            Object.values(PANEL_SELECTOR).forEach((sel) => {
                const panel = document.querySelector(sel);
                if (panel) panel.hidden = true;
            });
            const target = document.querySelector(selector);
            if (!target) return;
            target.hidden = false;
            document.dispatchEvent(new CustomEvent('hb:panel-shown', {
                detail: { panel: panelKey, tab: Number(tabIndex) || 0 },
            }));
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

        const setActiveNav = (panelKey, tabIndex, persist = false) => {
            const value = panelKey + ':' + Number(tabIndex || 0);
            const btn = document.querySelector('[data-hb-nav="' + value + '"]');
            document.querySelectorAll('[data-hb-nav]').forEach((other) => {
                const active = !!btn && other === btn;
                other.classList.toggle('hb-navitem--active', active);
                other.setAttribute('aria-current', active ? 'true' : 'false');
            });
            if (persist && btn) {
                try { localStorage.setItem(NAV_STORE, value); } catch (e) { }
            }
        };

        const activateNav = (btn, persist) => {
            const [panelKey, tabIndex] = (btn.dataset.hbNav || '').split(':');
            if (!PANEL_SELECTOR[panelKey]) return;
            setActiveNav(panelKey, tabIndex, persist);
            showPanel(panelKey, tabIndex);
        };

        const boot = () => {
            if (!document.__hbNavPanelSync) {
                document.__hbNavPanelSync = true;
                document.addEventListener('hb:panel-shown', (event) => {
                    const detail = event.detail || {};
                    setActiveNav(detail.panel || '', detail.tab || 0);
                });
                document.addEventListener('change', (event) => {
                    const tablist = event.target.closest && event.target.closest('[data-hb-tablist]');
                    if (!tablist || !event.detail) return;
                    const panel = tablist.closest('[data-hb-panel-cb], [data-hb-panel-seo], [data-hb-panel-style], [data-hb-panel-ai], [data-hb-panel-nav]');
                    if (!panel) return;
                    const panelKey = panel.hasAttribute('data-hb-panel-cb') ? 'cb'
                        : panel.hasAttribute('data-hb-panel-seo') ? 'seo'
                        : panel.hasAttribute('data-hb-panel-style') ? 'style'
                        : panel.hasAttribute('data-hb-panel-ai') ? 'ai' : 'nav';
                    setActiveNav(panelKey, event.detail.index || 0);
                });
            }
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
                    activateNav(btn, false);
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
        ...($documentType === 'email' ? [
            ['icon' => 'brackets-curly', 'label' => 'Variables', 'panel' => 'vars', 'tab' => 0],
        ] : []),
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
