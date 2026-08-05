<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- No Alpine/Livewire on this page (see resources/views/components/live/topbar.blade.php's
         script for the house vanilla-JS idiom) — interactive pieces talk to the backend via plain
         fetch(). This token is what makes those POSTs (e.g. the featured-image upload in
         live/media/media-dialog.blade.php) pass Laravel's CSRF check. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The tab shows the post's own name and nothing else. `$title` was never passed by
         EditorController (it passes `postTitle`), so this always read "Untitled" regardless of
         what the post was actually called. Kept in sync as you type by live/canvas's title
         script, which also owns the canvas/inspector title mirroring. --}}
    <title>{{ trim((string) ($postTitle ?? '')) !== '' ? $postTitle : __('heisenberg::editor.canvas.ph_untitled_post') }}</title>
    <link rel="stylesheet" href="/heisenberg-assets/editor.css">
</head>
<body>
    <div class="hb-editor">
        @stack('hb-nav-strings')
        <script>
            (() => {
                // Runs synchronously as the very first child of .hb-editor, before the rest of the
                // shell paints, so persisted collapse/theme state applies with no visible flash.
                const shell = document.currentScript.parentElement;
                const panelKeys = ['sidebar', 'panel', 'inspector'];
                panelKeys.forEach((key) => {
                    if (localStorage.getItem(`hb-editor:${key}-collapsed`) === 'true') {
                        shell.classList.add(`hb-editor--${key}-collapsed`);
                    }
                });
                if (localStorage.getItem('hb-editor:theme') === 'dark') {
                    shell.classList.add('hb-editor--dark');
                }

                // Persisted state can come from a wider viewport (e.g. desktop, where all three can
                // be open at once). Below 1024px only one may be open — normalize before first paint
                // rather than let an invalid multi-open state flash. Priority: sidebar, then panel,
                // then inspector — first one found open wins, the rest get force-collapsed.
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
    </div>
</body>
</html>
