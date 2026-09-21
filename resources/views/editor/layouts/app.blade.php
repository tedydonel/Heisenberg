<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim((string) ($postTitle ?? '')) !== '' ? $postTitle : __((($documentType ?? 'post') === 'email') ? 'heisenberg::editor.canvas.ph_untitled_email' : 'heisenberg::editor.canvas.ph_untitled_post') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ route('heisenberg.editor.asset.logo') }}">
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.css', ['v' => \Heisenberg\Http\Controllers\EditorController::cssAssetVersion()]) }}" nonce="{{ heisenberg_csp_nonce() }}">
</head>
{{-- hb-editor-body marks "this is the editor shell, not a page being read". The per-block
     hide-on-device rules use it to stand down here: inside the editor the DEVICE SWITCHER is
     the authority (it narrows the canvas container, which a viewport media query cannot see),
     while preview and the published page have no such class and use the real viewport. --}}
<body class="hb-editor-body">
    <div class="hb-editor">
        @stack('hb-nav-strings')
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                const shell = document.currentScript.parentElement;
                shell.classList.add('hb-editor--booting');
                const panelKeys = ['sidebar', 'panel', 'inspector'];
                const narrow = window.matchMedia('(max-width: 1200px)').matches;
                const storedOpen = (key, fallback = true) => {
                    const value = localStorage.getItem(`hb-editor:${key}-state`);
                    return value === null ? fallback : value === 'open';
                };
                const sidebarOpen = storedOpen('sidebar', true);
                const panelOpen = storedOpen('panel', !narrow);
                const inspectorOpen = storedOpen('inspector', !narrow);

                /* Restore only state classes before paint. On narrow screens
                   the sidebar, left panel, and inspector are one drawer group. */
                const initial = { sidebar: sidebarOpen, panel: panelOpen, inspector: inspectorOpen };
                let activeDrawer = null;
                if (narrow) {
                    activeDrawer = sidebarOpen ? 'sidebar' : panelOpen ? 'panel' : inspectorOpen ? 'inspector' : null;
                    panelKeys.forEach((key) => { initial[key] = key === activeDrawer; });
                    if (activeDrawer) shell.dataset.hbActiveDrawer = activeDrawer;
                }
                panelKeys.forEach((key) => {
                    const open = initial[key];
                    shell.classList.toggle(`hb-editor--${key}-closed`, !open);
                    if (narrow) shell.classList.toggle(`hb-editor--${key}-open`, open);
                    if (narrow && key !== activeDrawer) localStorage.setItem(`hb-editor:${key}-state`, 'closed');
                });
                if (localStorage.getItem('hb-editor:theme') === 'dark') {
                    shell.classList.add('hb-editor--dark');
                }
            })();
        </script>
        @yield('content')
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                const shell = document.currentScript.parentElement;
                const release = () => requestAnimationFrame(() => requestAnimationFrame(
                    () => shell.classList.remove('hb-editor--booting')
                ));
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', release, { once: true });
                else release();
                setTimeout(() => shell.classList.remove('hb-editor--booting'), 1500);
            })();
        </script>
    </div>
</body>
</html>
