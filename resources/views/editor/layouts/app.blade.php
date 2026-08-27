<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim((string) ($postTitle ?? '')) !== '' ? $postTitle : __('heisenberg::editor.canvas.ph_untitled_post') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ route('heisenberg.editor.asset.logo') }}">
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.css') }}">
</head>
<body>
    <div class="hb-editor">
        @stack('hb-nav-strings')
        <script>
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

                if (window.matchMedia('(max-width: 1023px)').matches) {
                    const openKeys = panelKeys.filter((key) => !shell.classList.contains(`hb-editor--${key}-collapsed`));
                    if (openKeys.length > 1) {
                        panelKeys.filter((key) => key !== openKeys[0]).forEach((key) => {
                            shell.classList.add(`hb-editor--${key}-collapsed`);
                            localStorage.setItem(`hb-editor:${key}-collapsed`, 'true');
                        });
                    }
                }
            })();
        </script>
        @yield('content')
        <script>
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
