<?php

declare(strict_types=1);

use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Services\FontCatalogService;
use Heisenberg\Services\PostTemplateRegistryService;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workbench "client test app" — the demo host's OWN blog
|--------------------------------------------------------------------------
| Everything in this file is what a real Heisenberg adopter has to write
| themselves per README's "Post Templates and Page Rendering" section — a
| controller that resolves PostTemplateRegistryService, calls
| BlockRenderer::renderBlocks(), and wires the block/theme stylesheets. It
| deliberately does NOT reuse Heisenberg\Http\Controllers\PostPublicController
| (that one is exercised separately, at its own bundled /posts/{locale}/{slug}
| URL, once `heisenberg.public.routes` is turned on — see workbench/config/
| heisenberg.php) so both surfaces stay independently inspectable:
|
|  - /posts/{locale}/{slug}  — the PACKAGE's bundled route: zero host code,
|    zero template-capability wiring (task 1 finding — see docs/demo.md).
|  - /blog/{locale}/{slug}   — THIS host's own route: every capability the
|    workbench's own resources/heisenberg-templates/blog/post.json declares
|    (featuredImage, tableOfContents, readingTime, breadcrumbs, comments,
|    relatedPosts, shareButtons, authorBox) is read from the template
|    contract and rendered by hand below, because nothing under src/ does
|    that dispatch for you.
|
| BILINGUAL MODEL (docs/content-translation.md §0, single row — see
| DemoSeeder's own docblock): a post is ONE row for both locales. Resolving
| it by URL locale mirrors `PostPublicController::resolvePost()` (fixed
| 2026-09-19): the row whose OWN `locale` matches the URL wins outright;
| otherwise the slug's row answers for the requested locale only if it
| actually has a `title_<locale>` — the "has content" test
| TranslationStatusService::statuses() uses, so this never serves a locale
| the post was never translated into.
|
| Required via a plain `require` (never `require_once`) from a route-loading
| context — either Testbench's own Workbench::discoverRoutes() (real
| `testbench serve`) or tests/Demo/AdopterPathTest::defineWebRoutes() (a
| fresh Router per test) — so this file must never declare a named
| class/function (redeclaration error on the 2nd require in the same PHP
| process); every closure below is a fresh value, which is always safe to
| redefine.
*/

// Shared "does this post have content for this locale" predicate — the query-level
// equivalent of TranslationStatusService's `title` check, used for LISTING many posts
// (index, related) where resolving each one through the service would be needlessly
// N+1. `$locale` is always validated against LocaleConfig::locales() before reaching
// here (route `where()` constraint below, or the explicit check in /blog), so
// interpolating it into a column name is safe — never raw user input.
$hasLocaleContent = function ($query, string $locale) {
    return $query->where(function ($q) use ($locale) {
        $q->where('locale', $locale)->orWhereNotNull("title_{$locale}");
    });
};

Route::get('/blog', function (Request $request) use ($hasLocaleContent) {
    $locale = (string) $request->query('locale', 'en');
    if (! LocaleConfig::isValid($locale)) {
        $locale = LocaleConfig::default();
    }

    $posts = $hasLocaleContent(
        Post::query()->posts()->where('status', 'published'),
        $locale,
    )
        ->orderByDesc('published_at')
        ->with(['categories', 'featuredImage'])
        ->get();

    return view('blog.index', ['posts' => $posts, 'locale' => $locale]);
})->name('workbench.blog.index');

Route::get('/blog/{locale}/{slug}', function (
    Request $request,
    string $locale,
    string $slug,
    BlockRenderer $renderer,
    BlockRegistryService $registry,
    ThemeRepository $themes,
    FontCatalogService $fonts,
    PostTemplateRegistryService $templates,
    PostCommentProvider $comments,
    TranslationStatusService $translationStatus,
) use ($hasLocaleContent) {
    $published = fn () => Post::query()->posts()->where('slug', $slug)->where('status', 'published');

    // Same-row resolution as PostPublicController::resolvePost() (fixed 2026-09-19):
    // the row whose own `locale` matches the URL wins outright; otherwise the slug's
    // row answers for this locale only if it actually has a `title_<locale>`.
    $post = $published()->where('locale', $locale)->first();
    if ($post === null) {
        $post = $published()->orderBy('id')->first();
        if ($post === null) {
            abort(404);
        }
        $hasTitle = false;
        foreach ($translationStatus->statuses($post) as $row) {
            if ($row['locale'] === $locale && $row['title']) {
                $hasTitle = true;
                break;
            }
        }
        if (! $hasTitle) {
            abort(404);
        }
    }
    $post->load(['categories', 'tags', 'featuredImage', 'tocEntries']);

    // The one line README's "Post Templates and Page Rendering" section shows —
    // everything below is the wiring it does NOT show, because src/ has no
    // renderer for a template's own capabilities.
    $template = $templates->getTemplate('heisenberg/blog') ?? [];
    $capabilities = is_array($template['capabilities'] ?? null) ? $template['capabilities'] : [];

    $blocks = $post->blocks->map(fn ($block) => $block->content)->values()->all();
    $html = $renderer->renderBlocks($blocks, $locale);

    $theme = $themes->load();
    $faces = [...$themes->fontFaces($theme), ...$fonts->facesForBlocks($blocks)];

    $featured = null;
    if (($capabilities['featuredImage']['enabled'] ?? false) && $post->featuredImage) {
        $featured = $post->featuredImage->imagePayload((string) ($capabilities['featuredImage']['context'] ?? 'hero'));
        $featured['alt'] = $post->featuredImage->getAlt($locale);
    }

    $toc = [];
    if ($capabilities['tableOfContents']['enabled'] ?? false) {
        // source: "entries" — the AUTHORED table of contents. This template
        // declares "entries" (see its own JSON); a "headings" source would need
        // the host to derive it from $blocks itself — nothing in src/ does that.
        // TocEntry has no per-locale `label_<locale>` column (see DemoSeeder's own
        // docblock), so these labels are the same text in every locale — a real,
        // separate gap this workbench does not attempt to paper over.
        $toc = $post->tocEntries->map(fn ($entry) => [
            'label' => $entry->label,
            'anchor' => $entry->anchor,
        ])->values()->all();
    }

    $readingTime = null;
    if ($capabilities['readingTime']['enabled'] ?? false) {
        $words = str_word_count(strip_tags($html));
        $wordsPerMinute = max(1, (int) ($capabilities['readingTime']['wordsPerMinute'] ?? 200));
        $minutes = max(1, (int) ceil($words / $wordsPerMinute));
        $readingTime = $minutes . ' ' . ($capabilities['readingTime']['label'] ?? 'min read');
    }

    $breadcrumbs = [];
    if ($capabilities['breadcrumbs']['enabled'] ?? false) {
        $breadcrumbs[] = ['label' => $capabilities['breadcrumbs']['homeLabel'] ?? 'Blog', 'url' => route('workbench.blog.index', ['locale' => $locale])];
        $firstCategory = $post->categories->first();
        if ($firstCategory) {
            $breadcrumbs[] = ['label' => $locale === 'fr' ? $firstCategory->name_fr : $firstCategory->name_en, 'url' => null];
        }
        $breadcrumbs[] = ['label' => $post->title($locale), 'url' => null];
    }

    $commentsPayload = null;
    if (($capabilities['comments']['enabled'] ?? false) && $post->allow_comments !== false) {
        // Reads the CONTRACT's own sortOrder — 'oldest' for this template — unlike
        // Heisenberg\Http\Controllers\PostPublicController, which hardcodes 'newest'
        // and ignores the capability entirely (task 1 finding).
        $sortOrder = (string) ($capabilities['comments']['sortOrder'] ?? 'newest');
        $thread = $comments->thread($post, $sortOrder);
        $commentsPayload = [
            'count' => $thread['count'],
            'items' => $thread['items'],
            'allow_guests' => (bool) ($capabilities['comments']['allowGuests'] ?? true),
        ];
    }

    $related = collect();
    if ($capabilities['relatedPosts']['enabled'] ?? false) {
        $categoryIds = $post->categories->pluck('id')->all();
        if ($categoryIds !== []) {
            $related = $hasLocaleContent(
                Post::query()->posts()->where('status', 'published')->where('id', '!=', $post->id),
                $locale,
            )
                ->whereHas('categories', fn ($q) => $q->whereKey($categoryIds))
                ->limit((int) ($capabilities['relatedPosts']['limit'] ?? 3))
                ->get();
        }
    }

    $shareNetworks = ($capabilities['shareButtons']['enabled'] ?? false)
        ? (array) ($capabilities['shareButtons']['networks'] ?? [])
        : [];

    $authorBox = ($capabilities['authorBox']['enabled'] ?? false)
        ? ['name' => 'The Widgets Team', 'bio' => 'We write about widgets so you do not have to guess.']
        : null;

    return view('blog.show', [
        'post' => $post,
        'locale' => $locale,
        'title' => $post->title($locale),
        'html' => $html,
        'blocksCss' => BlockViewData::blocksCss($registry),
        'stateCss' => $renderer->stateStylesCss($blocks),
        'themeCss' => $themes->css($theme),
        'fontsHref' => $fonts->css2Url($faces),
        'featured' => $featured,
        'toc' => $toc,
        'tocTitle' => $capabilities['tableOfContents']['title'] ?? 'Contents',
        'readingTime' => $readingTime,
        'breadcrumbs' => $breadcrumbs,
        'comments' => $commentsPayload,
        'related' => $related,
        'shareNetworks' => $shareNetworks,
        'authorBox' => $authorBox,
        'currentUrl' => $request->fullUrl(),
    ]);
})->where(['locale' => '[a-z]{2}', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->name('workbench.blog.show');

// "Render this email the way a host's send pipeline would" — the task's own request:
// a tiny route that calls EmailRenderer directly and shows the HTML + plain text side
// by side. `preview: true` swaps cid: references for real URLs so the HTML iframe
// renders in a browser tab exactly like EmailPreviewController's own preview does.
Route::get('/demo/email-preview/{slug}', function (string $slug, EmailRenderer $renderer) {
    $email = Post::query()->emails()
        ->where('slug', $slug)
        ->where('status', 'published')
        ->firstOrFail();

    $result = $renderer->render($email, 'en', preview: true);

    return view('blog.email-preview', ['result' => $result, 'email' => $email]);
})->name('workbench.email.preview');
