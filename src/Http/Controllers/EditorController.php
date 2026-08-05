<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\FontCatalogService;
use Heisenberg\Services\SavedThemeRepository;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Support\BlockViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class EditorController
{
    // Falls back to a uniform 56px on all four sides when a post has no saved override —
    // matches resources/css/editor/34-canvas.css's own `--hb-page-padding-x/-y` CSS fallback,
    // which must stay in sync with this (see that file's docblock on the two collapsing the
    // previous asymmetric 44/56/160 hardcoded padding into one configurable X/Y pair).
    private const DEFAULT_PAGE_PADDING_X = 56;
    private const DEFAULT_PAGE_PADDING_Y = 56;

    public function index(BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts): View
    {
        return view('heisenberg::editor.index', array_merge($this->sharedViewData($registry, $themes, $savedThemes, $fonts), [
            // No post yet — the canvas boots empty and the first Save (POST /editor/posts)
            // is what gives it an id. See live/topbar.blade.php's save wiring.
            'postId' => null,
            'postTitle' => '',
            'contentVersion' => 0,
            'initialBlocks' => [],
            // No post id yet either, so there's nothing to attach a category/tag TO, or a
            // layout/discussion setting to save — the Post tab's controls render disabled until
            // `hb:post-id` fires (see topbar.blade.php).
            'postCategoryIds' => [],
            'postTagIds' => [],
            'postPagePaddingX' => self::DEFAULT_PAGE_PADDING_X,
            'postPagePaddingY' => self::DEFAULT_PAGE_PADDING_Y,
            'postAllowComments' => true,
        ]));
    }

    /**
     * Opens an EXISTING post in the editor shell (routes/editor.php: GET /editor/{post}) —
     * the blank `/editor` above stays a fresh document. Seeds the view with the post's title
     * and its saved block tree so index.blade.php can hydrate the canvas through
     * window.hbEditor's public API (insertBlock/setAttribute/setSupport) once the runtime has
     * booted — block-runtime.blade.php itself is frozen and is not touched to do this.
     *
     * Mirrors PostController's own "resolve the configured Post model manually" convention
     * (config('heisenberg.models.post') is host-swappable, so implicit route-model binding
     * isn't used) rather than depending on PostController's private payload()/findOrFail().
     * Deliberately does not run a PostPolicy authorization check — this only renders the page
     * shell, and /editor's whole surface is already unauthenticated-by-default (see
     * config('heisenberg.middleware.editor')); the save endpoints PostController guards are
     * unaffected by this route.
     */
    public function show(BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, string $post): View
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);
        $model = $class::query()->with(['blocks', 'categories', 'tags'])->findOrFail($post);

        return view('heisenberg::editor.index', array_merge($this->sharedViewData($registry, $themes, $savedThemes, $fonts), [
            'postId' => $model->getKey(),
            'postTitle' => (string) ($model->title_en ?? ''),
            'contentVersion' => (int) $model->content_version,
            // Exactly the shape newBlockModel() produces client-side (id/name/schemaVersion/
            // attributes/supports/innerBlocks) — see PostController::payload()'s own docblock,
            // which this mirrors: each block is shipped back exactly as stored.
            'initialBlocks' => $model->blocks->map(fn ($block) => $block->content)->values()->all(),
            // Stringified — categoryOptions()/tagOptions() below build their own `value`s as
            // strings too (a plain <select>-style option list has no native int/string typing),
            // so the checklist's `in_array(..., true)` checked-state check in inspector.blade.php
            // compares like-for-like without a separate cast at every call site.
            'postCategoryIds' => $model->categories->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'postTagIds' => $model->tags->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'postPagePaddingX' => $model->page_padding_x ?? self::DEFAULT_PAGE_PADDING_X,
            'postPagePaddingY' => $model->page_padding_y ?? self::DEFAULT_PAGE_PADDING_Y,
            'postAllowComments' => $model->allow_comments ?? true,
        ]));
    }

    /**
     * View data both `index()` and `show()` need — the live block registry + its CSS/hash,
     * plus the saved site theme (Style/Themes panel, TODO 6.7) so the panel never renders
     * fixture data even on first paint.
     * No allow-list anymore (2026-08-02) — the client registry ships whatever
     * BlockRegistryService actually discovers under resources/blocks (heading +
     * paragraph today). Add a contract, it's live everywhere automatically.
     */
    private function sharedViewData(BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts): array
    {
        return [
            'registry' => BlockViewData::clientBlocks($registry),
            'blocksCss' => BlockViewData::blocksCss($registry),
            'theme' => $themes->load(),
            // The theme's own `--hb-t-*` custom properties. Only preview.blade.php ever emitted
            // these, so in the editor a token reference like `var(--hb-t-accent-1)` resolved to
            // nothing: the Style/Themes panel could edit and save tokens that the canvas could
            // not then display, and binding a block style to one was pointless. (TODO 7.6)
            'themeCss' => $themes->css(),
            // Merged theme-over-config token maps (color/fontSize/space/radius/fontFamily), keyed
            // `var(--hb-t-name) => Label`. ThemeRepository::tokens() existed with no callers at
            // all; it is what the Block.style variable picker offers.
            'themeTokens' => $themes->tokens(),
            'savedThemes' => $savedThemes->all(),
            // Seeds the Fonts field's ui/select (searchable) with a first page so it never opens
            // empty before a single keystroke — same popular-head GET /editor/fonts?q= would return.
            'fontOptions' => array_map(
                static fn (array $f): array => ['value' => $f['family'], 'label' => $f['family']],
                $fonts->search('', 40),
            ),
            // Every save payload must carry the registry hash the page was rendered against —
            // BlocksPayloadService rejects a payload whose hash no longer matches the live
            // contracts (stale-schema detection). Without shipping it here the client has no
            // way to obtain it, so the save endpoint would 422 every request from a real
            // browser even though it round-trips fine from server-side tests.
            'registryHash' => $registry->computeHash(),
            // The locale switcher's return URL — the route reads it from the
            // session, so every editor render must capture the current URL.
            // Falls back to the editor index if the URL helper is unavailable
            // (e.g. inside test fixtures that bypass routing).
            'localeReturn' => url()->current() !== url('/') ? url()->current() : route('heisenberg.editor.index', [], false),
            // Post tab Categories/Tags (Phase 3.1; both reworked 2026-08-03 into a shared
            // multi-select checklist widget — see inspector.blade.php's wirePostTaxonomy()).
            // categoriesIndexUrl/tagsIndexUrl double as both list AND store endpoints (GET vs POST
            // on the same URL). The attach/detach URLs are templates with __ID__/__ITEM_ID__
            // placeholders — same convention live/topbar.blade.php already uses for its own
            // post-id-shaped URLs, since the underlying routes are numeric-constrained
            // (`whereNumber`) and building them via route()'s own parameter substitution isn't
            // meant for a placeholder that isn't a real id yet. Both templates are shaped
            // identically now that Post::categories()/tags() are both BelongsToMany.
            'categoriesIndexUrl' => route('heisenberg.editor.categories.index'),
            'tagsIndexUrl' => route('heisenberg.editor.tags.index'),
            'postCategoryUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/categories/__ITEM_ID__',
            'postTagsUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/tags/__ITEM_ID__',
            'postLayoutUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/layout',
            'postDiscussionUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/discussion',
            // Seeds the Categories/Tags checklists the same way $fontOptions above seeds the Fonts
            // one — read directly (no CategoryPolicy/TagPolicy gate), matching this method's own
            // established precedent for initial-render-only data; the real, policy-gated read/write
            // happens at CategoryController/TagController/PostCategoryController/PostTagController
            // once the user actually acts.
            'categoryOptions' => $this->categoryOptions(),
            'tagOptions' => $this->tagOptions(),
        ];
    }

    /**
     * Every category, flattened from its parent/child tree into one ordered list with
     * depth-indented labels (e.g. "Parent" then "— Child") — a plain <select>-style option list
     * has no way to draw a tree, and the taxonomy's own `order`/`name_en` sort (CategoryController's
     * own index()) already groups siblings together, so a simple recursive walk from each root
     * reproduces the hierarchy visually without needing real tree UI.
     *
     * @return list<array{value: string, label: string}>
     */
    private function categoryOptions(): array
    {
        /** @var class-string<Category> $class */
        $class = (string) config('heisenberg.models.category', Category::class);
        $all = $class::query()->orderBy('order')->orderBy('name_en')->get();
        $byParent = $all->groupBy(fn (Category $category) => $category->parent_id ?? 0);

        $options = [];
        $seen = [];
        // Guards against a pre-existing corrupt cycle in the data the same way
        // Category::getAncestorIds()/getDescendantIds() already do — the API itself
        // (CategoryController::parentAssignmentError()) prevents creating one, but this walk
        // has no other way to terminate if the data was seeded/imported around that guard.
        $walk = function ($parentId, int $depth) use (&$walk, &$options, &$seen, $byParent): void {
            foreach ($byParent->get($parentId ?? 0, collect()) as $category) {
                if (isset($seen[$category->getKey()])) {
                    continue;
                }
                $seen[$category->getKey()] = true;
                $options[] = [
                    'value' => (string) $category->getKey(),
                    'label' => str_repeat('— ', $depth) . $category->name_en,
                ];
                $walk($category->getKey(), $depth + 1);
            }
        };
        $walk(0, 0);

        return $options;
    }

    /**
     * Every tag, flat (unlike categoryOptions() above, tags have no tree) — ordered by name,
     * mirroring TagController::index()'s own default ordering.
     *
     * @return list<array{value: string, label: string}>
     */
    private function tagOptions(): array
    {
        /** @var class-string<Tag> $class */
        $class = (string) config('heisenberg.models.tag', Tag::class);

        return $class::query()->orderBy('name_en')->get()
            ->map(fn (Tag $tag): array => ['value' => (string) $tag->getKey(), 'label' => (string) $tag->name_en])
            ->values()->all();
    }

    public function components(): View
    {
        return view('heisenberg::editor.components');
    }

    /** The Livewire media-library demo page. */
    public function media(): View
    {
        return view('heisenberg::editor.media');
    }

    /**
     * Dev-only static server for the `uploads` disk. In production a host runs
     * `storage:link` (or fronts the disk with a CDN) so `/uploads/...` is served
     * by the web server with no PHP in the path; `testbench serve` has no such
     * link, so this route stands in so uploaded media actually renders in dev.
     */
    public function servedUpload(string $path): Response
    {
        $disk = Storage::disk((string) config('heisenberg.media.disk', 'uploads'));

        if (! $disk->exists($path)) {
            return response('', 404);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function css(): Response
    {
        $base = dirname(__DIR__, 3) . '/resources/css/';
        $files = [$base . 'tokens.css', ...(glob($base . 'editor/*.css') ?: [])];
        $files = array_values(array_filter($files, 'is_file'));
        $editorCss = '';

        foreach ($files as $file) {
            $editorCss .= (string) file_get_contents($file) . "\n";
        }

        // Editor is under active development — a stale cached response here means every fix looks
        // unfixed to whoever's looking at it. no-store until this stabilizes; revisit for production.
        return response($editorCss, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Shared animation catalog (keyframes + trigger classes) — generated, not a
     * disk asset. Consumed by preview.blade.php (heisenberg.editor.asset.animations).
     */
    public function animationsCss(): Response
    {
        return response(\Heisenberg\Support\AnimationCatalog::css(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The supports-capabilities stylesheet (full-kit overhaul, Phase 1) — generated,
     * not a disk asset. Additive-only: a no-op until a block root carries hb-supports.
     * Consumed by preview.blade.php (heisenberg.editor.asset.supports).
     */
    public function supportsCss(): Response
    {
        return response(\Heisenberg\Support\SupportsStyle::css(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Self-hosted Editor chrome fonts (Rubik woff2, vendored under
     * resources/fonts/vendor). Strict name allowlist — no traversal surface.
     */
    public function font(string $file): Response
    {
        if (preg_match('/^[a-z0-9-]+\.woff2$/', $file) !== 1) {
            return response('', 404);
        }

        $path = dirname(__DIR__, 3) . '/resources/fonts/vendor/' . $file;
        if (! is_file($path)) {
            return response('', 404);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'font/woff2',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Editor's own brand mark — a separate vendored copy from Builder's
     * resources/img/heisenberg-logo.svg (different file, supplied directly
     * for Editor), served via Editor's own route so it doesn't depend on
     * Builder's asset serving.
     */
    public function logo(): Response
    {
        $path = dirname(__DIR__, 3) . '/resources/img/editor-logo.svg';
        if (! is_file($path)) {
            return response('', 404);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
