<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\FontCatalogService;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Support\BlockViewData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Preview in another page" — the toolbar's external button. Two flows,
 * both rendering through the same sanitizing BlockRenderer pipeline:
 *
 *  - store()/show(): the editor POSTs its current, possibly-never-saved
 *    document (session-scoped, size-capped), then opens GET .../preview in
 *    a new tab (routes/editor.php: POST/GET /editor/preview, under
 *    config('heisenberg.middleware.editor')). Before the builder's removal
 *    (2026-08-02) this pair was ALSO reachable under the builder's own route
 *    group and gate — editor.php's copy was added specifically so /editor
 *    never depended on that group, which is why only one pair remains now.
 *  - showPost(): for a post that has already been saved (routes/editor.php:
 *    GET /editor/{post}/preview), rendering straight from the DB's stored
 *    block tree is more honest than a session round-trip — it can't reflect
 *    a stale or unrelated document some other tab left sitting in the
 *    session, and it needs no POST at all before the tab can be pointed at
 *    it.
 *
 * For the post-scoped flow, the controller also loads the post's featured
 * image (Post::featuredImage BelongsTo) and passes a flat {url, srcset, sizes,
 * alt} payload to the view (preview.blade.php renders it above the title when
 * present). The session flow carries the same payload through the client doc
 * envelope (`featured_image` field, optional), so the editor's pick shows up
 * in unsaved previews without a persistence round-trip.
 */
class PreviewController
{
    private const SESSION_KEY = 'heisenberg.preview-doc';
    private const MAX_BYTES = 1024 * 1024;

    public function __construct(
        private BlockRenderer $renderer,
        private BlockRegistryService $registry,
        private ThemeRepository $themes,
        private FontCatalogService $fonts,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['stored' => false, 'error' => 'Document too large'], 413);
        }

        $doc = json_decode($raw, true);
        if (! is_array($doc) || ! is_array($doc['blocks'] ?? null)) {
            return response()->json(['stored' => false, 'error' => 'Body must be a document with a blocks list'], 422);
        }

        $request->session()->put(self::SESSION_KEY, $doc);

        return response()->json(['stored' => true, 'url' => route('heisenberg.editor.preview')]);
    }

    public function show(Request $request): View
    {
        $doc = $request->session()->get(self::SESSION_KEY);
        $hasDoc = is_array($doc);
        $doc = $hasDoc ? $doc : ['title' => null, 'blocks' => []];

        // The session envelope may carry a `featured_image` field (the inspector's hidden inputs,
        // echoed back by EditorController::show() clients when an unsaved doc preview is opened).
        // It's already in the right shape — {id, url, ...} — so we just pass it through.
        $featured = is_array($doc['featured_image'] ?? null) ? $doc['featured_image'] : null;
        $featured = $this->normalizeFeatured($featured);

        return $this->renderDoc(
            blocks: array_values($doc['blocks']),
            title: (string) ($doc['title'] ?? '') ?: 'Untitled post',
            seo: (array) (($doc['meta'] ?? [])['seo'] ?? []),
            hasDoc: $hasDoc,
            featured: $featured,
        );
    }

    /**
     * Post-scoped preview (GET /editor/{post}/preview) — renders directly
     * from the DB's saved block tree, no session round-trip. Runs the same
     * PostPolicy view check as PostController::show() and EditorController::show():
     * this page ships the post's full rendered content, so an anonymous
     * visitor must not be able to read drafts by ID.
     * `Post` has no SEO fields of its own yet, so $seo is always empty here
     * — the title-only head fallback in preview.blade.php applies, same as
     * the "nothing in the session yet" branch of show() above.
     */
    public function showPost(Request $request, string $post): View
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);
        $model = $class::query()->with(['featuredImage', 'tocEntries'])->findOrFail($post);

        Gate::forUser($this->actor($request))->authorize('view', $model);

        return $this->renderDoc(
            blocks: $model->blocks->map(fn ($block) => $block->content)->values()->all(),
            title: (string) ($model->title_en ?? '') ?: 'Untitled post',
            seo: [],
            hasDoc: true,
            featured: $this->featuredPayload($model->featuredImage),
            // The AUTHORED table of contents (Post::tocEntries()) — renders ONLY when the post
            // has rows here; see preview.blade.php and Post::tocEntries()'s own docblock for why
            // this is deliberately distinct from the tableOfContents capability's render-time
            // "derive from headings" path (docs/post-template-schema.md, source: "entries").
            toc: $model->tocEntries->map(fn ($entry) => [
                'label' => $entry->label,
                'anchor' => $entry->anchor,
            ])->values()->all(),
        );
    }

    /** @param list<array<string, mixed>> $blocks */
    private function renderDoc(array $blocks, string $title, array $seo, bool $hasDoc, ?array $featured, array $toc = []): View
    {
        $theme = $this->themes->load();

        // Font faces: the theme's fonts plus any catalog family picked
        // directly on a block (raw family values, not var() tokens).
        $faces = $this->themes->fontFaces($theme);
        foreach ($blocks as $block) {
            $family = $block['supports']['typography']['fontFamily'] ?? null;
            if (is_string($family) && $family !== '' && ! str_starts_with($family, 'var(')) {
                $faces[] = ['family' => $family, 'weights' => [400, 700]];
            }
        }

        return view('heisenberg::preview', [
            'hasDoc' => $hasDoc,
            'title' => $title,
            'html' => $this->renderer->renderBlocks($blocks, app()->getLocale()),
            'blocksCss' => BlockViewData::blocksCss($this->registry),
            'stateCss' => $this->renderer->stateStylesCss($blocks),
            'themeCss' => $this->themes->css($theme),
            'fontsHref' => $this->fonts->css2Url($faces),
            'seo' => $seo,
            'featured' => $featured,
            'toc' => $toc,
        ]);
    }

    /**
     * Resolve a featured image payload from a PublicFile instance. Returns null when the post has
     * no featured image; otherwise returns the same flat {id, url, srcset, sizes, alt} payload
     * PublicFile::imagePayload() produces, plus the localized alt text the preview needs.
     */
    private function featuredPayload($file): ?array
    {
        if ($file === null) {
            return null;
        }

        $payload = $file->imagePayload('hero');
        $payload['id'] = (int) $file->id;
        $payload['alt'] = $file->getAlt(app()->getLocale());

        return $payload;
    }

    /**
     * The session-flow payload may already be a PublicFile::imagePayload-shaped array (when the
     * client sent it via the editor's POST /editor/preview envelope) — in that case we just
     * hydrate the alt from the existing alt field if present, otherwise leave it as-is.
     */
    private function normalizeFeatured(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        if (! isset($payload['url']) || ! is_string($payload['url']) || $payload['url'] === '') {
            return null;
        }

        if (! isset($payload['alt']) || ! is_string($payload['alt'])) {
            $payload['alt'] = '';
        }

        return $payload;
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }
}
