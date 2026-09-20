<?php

declare(strict_types=1);
use Heisenberg\Services\SeoUrlResolver;

/*
|--------------------------------------------------------------------------
| Workbench (demo host) config overrides
|--------------------------------------------------------------------------
|
| Loaded before HeisenbergServiceProvider::register()'s mergeConfigFrom(),
| via Testbench's `workbench.discovers.config: true` (see testbench.yaml) —
| the workbench analogue of a real adopter publishing config/heisenberg.php
| and editing values. Every key below is something the README/task explicitly
| calls out as the host's own decision, never edited in the package's own
| config/heisenberg.php.
|
| mergeConfigFrom() does a SHALLOW array_merge (package defaults array_merge'd
| UNDER whatever is already set here), so each top-level key below completely
| replaces the package's default for that key — every sub-key the package
| default carries for these two arrays is repeated here so nothing is lost.
*/

return [
    // Turn on the bundled turnkey public blog route (GET /posts/{locale}/{slug}),
    // off by default in the package. docs/demo.md's AdopterPathTest exercises this
    // directly to show what a visitor gets with ZERO host code.
    'public' => [
        'routes' => true,
    ],

    // Point the template registry at the WORKBENCH's own template directory
    // instead of the package's bundled resources/templates — the "host provides
    // its own post/page template" seam PostTemplateRegistryService's docblock
    // describes. workbench/resources/heisenberg-templates/blog/post.json is the
    // only contract discovered once this is set.
    'template_root' => __DIR__ . '/../resources/heisenberg-templates',

    // As of 2026-09-19, SeoUrlResolver::url() falls back to the bundled public
    // route automatically once 'public.routes' above is on and no url_template is
    // set (previously fell back to /editor/{id}/preview — see docs/demo.md's
    // "found by this demo, fixed 2026-09-19" note). Setting it explicitly here is
    // now belt-and-suspenders, not load-bearing, but is still what a real host
    // would do to keep the sitemap/alternates independent of that fallback.
    'seo' => [
        'sitemap' => true,
        'url_template' => '/posts/{locale}/{slug}',
        'url_resolver' => SeoUrlResolver::class,
    ],
];
