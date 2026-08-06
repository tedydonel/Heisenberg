{{-- Public-style preview of the editor's current document. Everything on
     this page went through BlockRenderer's sanitization; the head carries
     the doc's SEO meta so what you ship is what you see. --}}
@php
    $seo = $seo ?? [];
    $metaTitle = trim((string) ($seo['title'] ?? '')) ?: $title;
    $metaDescription = trim((string) ($seo['description'] ?? ''));
    $canonical = trim((string) ($seo['canonical'] ?? ''));
    $robots = [];
    if (! empty($seo['noindex'])) $robots[] = 'noindex';
    if (! empty($seo['nofollow'])) $robots[] = 'nofollow';
    $ogTitle = trim((string) ($seo['ogTitle'] ?? '')) ?: $metaTitle;
    $ogDescription = trim((string) ($seo['ogDescription'] ?? '')) ?: $metaDescription;
    $ogImage = trim((string) ($seo['ogImage'] ?? ''));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $metaTitle }}</title>
    @if ($metaDescription !== '')<meta name="description" content="{{ $metaDescription }}" />@endif
    @if ($robots !== [])<meta name="robots" content="{{ implode(', ', $robots) }}" />@endif
    @if ($canonical !== '')<link rel="canonical" href="{{ $canonical }}" />@endif
    <meta property="og:type" content="article" />
    <meta property="og:title" content="{{ $ogTitle }}" />
    @if ($ogDescription !== '')<meta property="og:description" content="{{ $ogDescription }}" />@endif
    @if ($ogImage !== '')<meta property="og:image" content="{{ $ogImage }}" />@endif
    <meta name="twitter:card" content="{{ $ogImage !== '' ? 'summary_large_image' : 'summary' }}" />
    <link rel="icon" type="image/svg+xml" href="{{ route('heisenberg.editor.asset.logo') }}" />
    @if (! empty($fontsHref))
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="{{ $fontsHref }}" rel="stylesheet" />
    @endif
    {{-- Base content tokens the block CSS defaults reference (chrome-side
         these live in the editor's own CSS; the public page defines them here). --}}
    <style>
        :root {
            --ink: #0a0a0a; --accent-1: #0a0a0a; --faint: #9a9a9a; --paper: #ffffff;
            --fs-sm: 13px; --fs-md: 14px; --fs-lg: 16px; --fs-xl: 20px;
            --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 1rem; --sp-4: 1.5rem;
            --font-sans: 'Rubik', -apple-system, sans-serif;
            --font-serif: Georgia, 'Times New Roman', serif;
            --font-mono: 'JetBrains Mono', ui-monospace, monospace;
            --fw-regular: 400; --fw-medium: 500; --fw-semibold: 600; --fw-bold: 700;
            --ui: var(--font-sans); --slate: #5a5a5a; --muted: #9a9a9a; --ghost: #c4c4c4;
            --line: #e4e4e4; --line-strong: #c8c8c8; --app: #eeeeee; --app-2: #f4f4f4;
            --bg: #ffffff; --subtle: #fafafa; --r-xs: 2px; --r-sm: 3px; --r-md: 5px; --r-lg: 8px;
            --sh-1: 0 1px 2px rgba(10,10,10,0.04); --speed: 170ms; --ink-soft: #1a1a1a; --on-ink: #ffffff;
        }
    </style>
    <style>{!! $themeCss !!}</style>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--paper, #fff); color: var(--ink, #0a0a0a);
            font-family: var(--font-sans, 'Rubik'), -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .hb-preview-page { max-width: 760px; margin: 0 auto; padding: 56px 24px 96px; }
        .hb-preview-title { font-size: 40px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; margin: 0 0 10px; }
        .hb-preview-bar {
            position: sticky; top: 0; background: var(--ink, #0a0a0a); color: var(--on-ink, #fff);
            font: 12px/1 var(--font-sans, 'Rubik'), sans-serif; letter-spacing: 0.04em;
            padding: 8px 16px; display: flex; align-items: center; gap: 8px;
        }
        .hb-preview-bar b { font-weight: 600; }
        .hb-preview-bar span { opacity: 0.6; }
    </style>
    <style>{!! $blocksCss !!}</style>
    {{-- Per-instance interaction-state overrides (hover/active/focus),
         compiled + sanitized by BlockRenderer::stateStylesCss. --}}
    <style>{!! $stateCss ?? '' !!}</style>
    {{-- Shared animation catalog (keyframes + trigger classes). --}}
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.animations') }}" />
    {{-- Full-kit overhaul (Phase 1) — the generated supports-capabilities
         stylesheet. Additive-only: a no-op until a block root carries hb-supports. --}}
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.supports') }}" />
</head>
<body>
    <div class="hb-preview-bar"><b>Preview</b><span>— sanitized public rendering; close this tab to return to the editor.</span></div>
    <main class="hb-preview-page">
        @if (($hasDoc ?? true) === false)
            <div style="padding:56px 16px;text-align:center;color:var(--muted,#9a9a9a);font-size:var(--fs-md,14px);line-height:1.6">
                <p style="font-size:17px;color:var(--ink,#0a0a0a);font-weight:600;margin:0 0 6px">Nothing to preview yet</p>
                <p style="margin:0">Save your post in the editor (or use the ↗ preview button) and this page will show the rendered result.</p>
            </div>
        @else
            <h1 class="hb-preview-title">{{ $title }}</h1>
            {!! $html !!}
        @endif
    </main>
    {{-- Scroll-animation runtime: JS-only hiding (no-JS readers always see
         content), IntersectionObserver plays entrances once (or re-arms
         when the block opts out of play-once), respects reduced motion. --}}
    <script>
    (function () {
        'use strict';
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var animated = document.querySelectorAll('[data-hb-anim]');
        if (!animated.length) return;
        if (!('IntersectionObserver' in window)) {
            animated.forEach(function (el) { el.classList.add('hb-anim-play'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var el = entry.target;
                var once = el.hasAttribute('data-hb-anim-once');
                if (entry.isIntersecting) {
                    el.classList.remove('hb-anim-wait');
                    el.classList.add('hb-anim-play');
                    if (once) io.unobserve(el);
                } else if (!once) {
                    el.classList.remove('hb-anim-play');
                    el.classList.add('hb-anim-wait');
                }
            });
        }, { threshold: 0.18 });
        animated.forEach(function (el) {
            el.classList.add('hb-anim-wait');
            io.observe(el);
        });
    })();
    </script>
</body>
</html>
