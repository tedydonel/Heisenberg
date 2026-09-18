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
                const narrow = window.matchMedia('(max-width: 1023px)').matches;
                const storedOpen = (key, fallback = true) => {
                    const value = localStorage.getItem(`hb-editor:${key}-state`);
                    return value === null ? fallback : value === 'open';
                };
                const panelOpen = storedOpen('panel', true);
                const inspectorOpen = storedOpen('inspector', !narrow);

                /* Restore only the state classes. The live controller owns all
                   later transitions and interactions; this runs before paint. */
                panelKeys.forEach((key) => {
                    const open = key === 'sidebar' ? (narrow ? true : storedOpen('sidebar', true)) : key === 'panel' ? panelOpen : inspectorOpen;
                    shell.classList.toggle(`hb-editor--${key}-closed`, !open);
                    if (narrow && key !== 'sidebar') {
                        shell.classList.toggle(`hb-editor--${key}-open`, open);
                    }
                });
                if (narrow && panelOpen && inspectorOpen) {
                    /* Legacy storage can contain both drawers open. Keep one
                       deterministic winner instead of rendering both. */
                    shell.classList.remove('hb-editor--inspector-open', 'hb-editor--inspector-closed');
                    shell.classList.add('hb-editor--inspector-closed');
                    localStorage.setItem('hb-editor:inspector-state', 'closed');
                }
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
