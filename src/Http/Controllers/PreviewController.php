<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Contracts\PostSeoMetaProvider;
use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\FontCatalogService;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Support\LocaleConfig;
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
        private PostCommentProvider $comments,
        private PostSeoMetaProvider $seoMeta,
        private PostUrlResolver $urls,
        private TranslationStatusService $translationStatus,
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
        $this->applyContentLocale($request);

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
     * `$seo` is resolved through {@see PostSeoMetaProvider} (docs/seo-system.md Wave S1) —
     * empty when the bound provider has nothing for this post (no row, or the null adapter),
     * in which case the title-only head fallback in preview.blade.php applies, same as the
     * "nothing in the session yet" branch of show() above.
     *
     * Title reads through {@see Post::title()} (docs/content-translation.md §7) — own-locale
     * column first, cross-locale fallback, `''` never null — rather than the old hardcoded
     * `title_en` read, so a FR-only row (empty `title_en`) previews with its real FR title
     * instead of "Untitled post".
     */
    public function showPost(Request $request, string $post): View
    {
        $this->applyContentLocale($request);

        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);
        $model = $class::query()->with(['featuredImage', 'tocEntries'])->findOrFail($post);

        // An email document is served at its own slug and nowhere else (docs/email-system.md
        // §6.1, routes/email.php). Rendering one here would dress it in the post page's shell —
        // SEO head, hreflang, comments thread — none of which an email has any business carrying,
        // and would give it a second public address that the "one URL" rule exists to prevent.
        abort_if($model->type === 'email', 404);

        Gate::forUser($this->actor($request))->authorize('view', $model);

        return $this->renderDoc(
            blocks: $model->blocks->map(fn ($block) => $block->content)->values()->all(),
            // Explicitly the REQUEST's locale: Post::title() with no argument resolves against the
            // ROW's own `locale` column, which is the language the post was created in, not the one
            // being viewed. Left implicit, the page rendered French blocks under an English
            // heading. The accessor's cross-locale fallback still applies, so a row with only one
            // translation reads the same as before.
            title: $model->title(app()->getLocale()) ?: 'Untitled post',
            seo: $this->seoPayload($model),
            hasDoc: true,
            featured: $this->featuredPayload($model->featuredImage),
            // hreflang alternates (docs/seo-system.md §5, docs/content-translation.md §0/§7) —
            // empty when the post has content in only its own home locale (a solo post emits no
            // <link rel="alternate"> at all; see alternatesPayload()'s own docblock).
            alternates: $this->alternatesPayload($model),
            // The AUTHORED table of contents (Post::tocEntries()) — renders ONLY when the post
            // has rows here; see preview.blade.php and Post::tocEntries()'s own docblock for why
            // this is deliberately distinct from the tableOfContents capability's render-time
            // "derive from headings" path (docs/post-template-schema.md, source: "entries").
            toc: $model->tocEntries->map(fn ($entry) => [
                'label' => $entry->label,
                'anchor' => $entry->anchor,
            ])->values()->all(),
            // Native comments section (docs/ai-mcp-plan.md's sibling, PostCommentProvider) —
            // absent entirely (null) when the post opted out via allow_comments === false;
            // `null` (the default, "use the built-in default") and `true` both render it.
            comments: $model->allow_comments === false ? null : $this->commentsPayload($request, $model),
        );
    }

    /** @param list<array<string, mixed>> $blocks */
    private function renderDoc(array $blocks, string $title, array $seo, bool $hasDoc, ?array $featured, array $toc = [], ?array $comments = null, array $alternates = []): View
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
            'comments' => $comments,
            'alternates' => $alternates,
        ]);
    }

    /**
     * Maps {@see PostSeoMetaProvider}'s return shape onto what preview.blade.php's head logic
     * expects: `title`/`description`/`canonical`/`ogTitle`/`ogDescription`/`ogImage` pass
     * straight through (same key names on both sides), and the view's `noindex`/`nofollow`
     * booleans are derived here from the provider's single `robots` directive string (e.g.
     * `'noindex, follow'`) — the provider/model store the one W3C-shaped string; the view was
     * already built around two independent booleans, and Wave S1 deliberately keeps the view
     * untouched (see docs/seo-system.md §2). `jsonLd` passes through as-is for the
     * `<script type="application/ld+json">` block.
     *
     * @return array<string, mixed>
     */
    private function seoPayload(Post $model): array
    {
        $meta = $this->seoMeta->meta($model, app()->getLocale());
        if ($meta === []) {
            return [];
        }

        $robots = strtolower((string) ($meta['robots'] ?? ''));

        return [
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'canonical' => $meta['canonical'] ?? null,
            'ogTitle' => $meta['ogTitle'] ?? null,
            'ogDescription' => $meta['ogDescription'] ?? null,
            'ogImage' => $meta['ogImage'] ?? null,
            'noindex' => str_contains($robots, 'noindex'),
            'nofollow' => str_contains($robots, 'nofollow'),
            'jsonLd' => $meta['jsonLd'] ?? [],
        ];
    }

    /**
     * Builds the comments payload the view renders (see preview.blade.php's
     * `.hb-preview-comments` section). `created_at` is formatted server-side
     * here (not left for JS) so the section is consistent regardless of
     * whether the provider handed back a Carbon instance (the native
     * adapter) or a plain string (any custom adapter).
     */
    private function commentsPayload(Request $request, Post $model): array
    {
        $thread = $this->comments->thread($model, 'newest');
        $user = $request->user();
        $allowGuests = (bool) config('heisenberg.comments.allow_guests', true);

        return [
            'count' => $thread['count'],
            'items' => $this->formatItems($thread['items']),
            'post_id' => $model->getKey(),
            'submit_url' => route('heisenberg.comments.store', $model->getKey()),
            'can_submit' => $user !== null || $allowGuests,
            'is_guest' => $user === null,
            'max_depth' => (int) config('heisenberg.comments.max_depth', 3),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function formatItems(array $items): array
    {
        return array_map(function (array $item): array {
            $item['created_at'] = $this->formatDate($item['created_at'] ?? null);
            $item['replies'] = $this->formatItems($item['replies'] ?? []);

            return $item;
        }, $items);
    }

    private function formatDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('M j, Y H:i');
        }

        if (is_string($value) && $value !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($value)->format('M j, Y H:i');
            } catch (\Throwable) {
                return $value;
            }
        }

        return '';
    }

    /**
     * hreflang alternates (docs/seo-system.md §5, docs/content-translation.md §0/§7), rewritten
     * for the single-row model. There are no sibling ROWS to relate anymore (`Post::siblings()`
     * is gone) — a post's other locales live as `_<locale>` attribute variants on THIS SAME row —
     * so every alternate points at the SAME post, through the existing {@see PostUrlResolver}
     * seam (`heisenberg.seo.url_template`/`url_resolver`), with only the `locale` attribute
     * swapped on an in-memory CLONE of the post (never persisted, see {@see self::urlFor()}). A
     * host's own resolver binding (or the bundled `SeoUrlResolver`) reads `$post->locale` to
     * build the address either way, so this needs no knowledge of what a locale-prefixed URL
     * actually looks like — same seam the sitemap already resolves this contract through.
     *
     * Emits one alternate per locale the post actually HAS CONTENT for — that locale's `title`
     * flag from {@see TranslationStatusService} is the cheapest real "this locale exists" signal
     * — plus one `x-default` entry at `heisenberg.default_locale`. A post translated into only
     * its own home locale (untranslated — the common case) emits NOTHING: a single-locale
     * hreflang set is a self-referencing link with no informational value, so the whole block is
     * withheld rather than emitted with one lonely entry.
     *
     * @return list<array{locale:string,url:string}>
     */
    private function alternatesPayload(Post $model): array
    {
        $withContent = array_values(array_filter(
            $this->translationStatus->statuses($model),
            static fn (array $row): bool => $row['title'],
        ));

        if (count($withContent) < 2) {
            return [];
        }

        $alternates = [];
        foreach ($withContent as $row) {
            $alternates[] = ['locale' => $row['locale'], 'url' => $this->urlFor($model, $row['locale'])];
        }

        $alternates[] = ['locale' => 'x-default', 'url' => $this->urlFor($model, LocaleConfig::default())];

        return $alternates;
    }

    /**
     * `$model`'s public URL for a locale OTHER than (or the same as) its own — via the SAME
     * {@see PostUrlResolver} seam every other public URL in this package goes through, given an
     * in-memory clone with only `locale` swapped (attributes never persisted; `getKey()`/`slug`
     * stay the model's own). Works with the bundled `SeoUrlResolver` (reads `$post->locale`) and
     * any host-bound resolver that does the same, without this method knowing the URL shape.
     */
    private function urlFor(Post $model, string $locale): string
    {
        $clone = clone $model;
        $clone->locale = $locale;

        return $this->urls->url($clone);
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

    /**
     * Honour an explicit `?locale=` on a preview (validated against the configured set; anything
     * else leaves the app locale alone).
     *
     * The app locale alone was the wrong answer: it is the UI language, set from the session by
     * EditorLocaleMiddleware, while the locale being EDITED is client state in the editor
     * (`hbEditor.getEditingLocale()`) that never touched that session value. Previewing a post
     * while editing its French version therefore rendered the English one. Set on the request
     * rather than threaded as a parameter because everything downstream — renderBlocks(), the SEO
     * payload, Post::title() — resolves the locale for itself, and they all have to agree.
     */
    private function applyContentLocale(Request $request): void
    {
        $requested = trim((string) $request->query('locale', ''));

        if ($requested !== '' && LocaleConfig::isValid($requested)) {
            app()->setLocale($requested);
        }
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }
}
