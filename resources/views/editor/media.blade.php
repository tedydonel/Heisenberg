<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Heisenberg — {{ __('heisenberg::editor.common.untitled') }}</title>
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.css') }}">
</head>
<body class="hb-preview">
    <main class="hb-preview__main">
        <header>
            <p class="hb-preview__eyebrow">Editor UI · Livewire</p>
            <h1 class="hb-preview__title">Media Library — wired</h1>
            <p class="hb-preview__section-note" style="margin-bottom:24px;">
                Live and backed by <code>heisenberg_public_files</code>: search filters real rows, uploads run
                through <code>MediaLibraryService</code> and appear in the grid, and clicking a card selects it.
            </p>
        </header>
        <div style="display:flex; justify-content:center; overflow-x:auto;">
            <livewire:heisenberg.media-library />
        </div>
    </main>
</body>
</html>
