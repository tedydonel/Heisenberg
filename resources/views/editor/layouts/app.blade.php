<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim((string) ($postTitle ?? '')) !== '' ? $postTitle : __('heisenberg::editor.canvas.ph_untitled_post') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ route('heisenberg.editor.asset.logo') }}">
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.css') }}" nonce="{{ heisenberg_csp_nonce() }}">
</head>
<body>
    <div class="hb-editor">
        @stack('hb-nav-strings')
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                const shell = document.currentScript.parentElement;
                shell.classList.add('hb-editor--booting');
                const panelKeys = ['sidebar', 'panel', 'inspector'];
                panelKeys.forEach((key) => {
                    if (localStorage.getItem(`hb-editor:${key}-collapsed`) === 'true') {
                        shell.classList.add(`hb-editor--${key}-collapsed`);
                    }
                });
                if (localStorage.getItem('hb-editor:theme') === 'dark') {
                    shell.classList.add('hb-editor--dark');
                }

                /* Narrow screens use drawer mode (see 20-shell.css): the icon rail
                   always stays visible, so never persist/collapse it here; drawers
                   just render closed by their own persisted flag. */
                if (window.matchMedia('(max-width: 1023px)').matches) {
                    shell.classList.remove('hb-editor--sidebar-collapsed');
                    /* Drawers default to closed on narrow screens; they only
                       open via --*-open (set here when restored open, and by
                       hbSetPanelCollapsed on toggle). */
                    ['panel', 'inspector'].forEach((key) => {
                        const open = localStorage.getItem(`hb-editor:${key}-collapsed`) === 'false';
                        shell.classList.toggle(`hb-editor--${key}-collapsed`, !open);
                        shell.classList.toggle(`hb-editor--${key}-open`, open);
                    });
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
