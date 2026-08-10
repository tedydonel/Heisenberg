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
    {{-- The SVG brand mark doubles as the favicon (the PNG logo was retired
         2026-08-10 — SVG-only branding), served through the package's own
         asset route so no publishing step is needed. --}}
    <link rel="icon" type="image/svg+xml" href="/heisenberg-assets/editor-logo.svg">
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
                // Boot gate — see .hb-editor--booting in 20-shell.css. The restores that need the
                // parsed DOM (active panel, block hydration, unsaved draft) run at DOMContentLoaded;
                // without this the browser paints the fresh-boot layout first and the restored state
                // arrives as a visible "readjust". Released by the script after the content yield.
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
        <script>
            (() => {
                // Boot-gate release. Registered AFTER every restore script in the content above,
                // so its DOMContentLoaded listener runs last (listener order = registration
                // order): by the time this fires, the active panel is swapped in and the block
                // tree is hydrated. Two rAFs let that batch commit, then the shell reveals in its
                // restored state. The timeout is the failsafe — if any restore script throws, the
                // editor must still appear.
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
