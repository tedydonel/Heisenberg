@php
    $navLocale = $locale ?? 'en';
@endphp
<!DOCTYPE html>
<html lang="{{ $navLocale }}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title ?? 'Widgets Weekly — demo blog' }}</title>
    @if (! empty($fontsHref))
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="{{ $fontsHref }}" rel="stylesheet" />
    @endif
    @if (! empty($themeCss))
        <style nonce="{{ heisenberg_csp_nonce() }}">{!! $themeCss !!}</style>
    @endif
    @if (! empty($blocksCss))
        <style nonce="{{ heisenberg_csp_nonce() }}">{!! $blocksCss !!}</style>
    @endif
    @if (! empty($stateCss))
        <style nonce="{{ heisenberg_csp_nonce() }}">{!! $stateCss !!}</style>
    @endif
    <style nonce="{{ heisenberg_csp_nonce() }}">
        :root {
            --ink: #0a0a0a; --paper: #ffffff; --muted: #6b6b6b; --line: #e4e4e4;
            --brand: #1a56db; --subtle: #f7f8fa; --r-md: 8px;
            --font-sans: 'Rubik', -apple-system, sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--ink); font-family: var(--font-sans); }
        a { color: var(--brand); }
        .wb-shell { max-width: 920px; margin: 0 auto; padding: 0 24px; }
        .wb-header { border-bottom: 1px solid var(--line); background: var(--paper); position: sticky; top: 0; z-index: 5; }
        .wb-header__row { display: flex; align-items: center; justify-content: space-between; padding: 18px 0; }
        .wb-brand { font-weight: 700; font-size: 18px; text-decoration: none; color: var(--ink); }
        .wb-nav { display: flex; gap: 20px; align-items: center; }
        .wb-nav a { text-decoration: none; color: var(--ink); font-size: 14px; font-weight: 500; }
        .wb-nav a.wb-nav--active { color: var(--brand); }
        .wb-locale-switch { display: flex; gap: 8px; }
        .wb-locale-switch a { padding: 4px 8px; border: 1px solid var(--line); border-radius: 6px; font-size: 12px; text-transform: uppercase; }
        .wb-locale-switch a.wb-locale--active { background: var(--brand); color: #fff; border-color: var(--brand); }
        .wb-main { padding: 40px 0 80px; }
        .wb-footer { border-top: 1px solid var(--line); padding: 28px 0; margin-top: 40px; color: var(--muted); font-size: 13px; }
        .wb-badge { display: inline-block; background: var(--subtle); border: 1px solid var(--line); border-radius: 999px; padding: 3px 10px; font-size: 12px; color: var(--muted); }
    </style>
    @yield('head')
</head>
<body>
    <header class="wb-header">
        <div class="wb-shell wb-header__row">
            <a class="wb-brand" href="{{ url('/blog') }}">Widgets Weekly</a>
            <nav class="wb-nav">
                <a href="{{ url('/blog') }}">Blog</a>
                <a href="{{ url('/editor') }}">Open editor</a>
                <span class="wb-badge">Heisenberg demo host</span>
            </nav>
        </div>
    </header>
    <main class="wb-main">
        <div class="wb-shell">
            @yield('content')
        </div>
    </main>
    <footer class="wb-footer">
        <div class="wb-shell">
            This blog is the workbench demo host — everything above the block content
            (header, footer, breadcrumbs, table of contents, comments sort order, related
            posts, reading time) is workbench/resources/views/blog code, not package code.
        </div>
    </footer>
</body>
</html>
