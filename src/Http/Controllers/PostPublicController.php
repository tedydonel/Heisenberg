<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

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
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The bundled public show route (opt-in via `heisenberg.public.routes`): serves a
 * published post at its slug for the locale in the URL, rendering through the SAME
 * BlockRenderer + head/featured/alternates/comments pipeline the editor preview
 * ({@see PreviewController::showPost}) already produces.
 *
 * This deliberately mirrors PreviewController::showPost rather than extracting a shared
 * service: PreviewController is exercised by PreviewSeoTest and a live editor flow, and
 * refactoring it risks breaking both. The duplication here is small and self-contained
 * (a NEW controller, no changes to PreviewController), and the docblock at the top of
 * every helper points back so a future consolidation can find the pair. When a host's
 * needs diverge from the bundled shape (different chrome, a different view), they bind
 * their own `PostUrlResolver` (`heisenberg.seo.url_resolver`) and supply their own
 * view through the published-template contract, leaving this controller untouched.
 *
 * Serves ONLY `type = 'post'` rows in `status = 'published'` — draft / scheduled /
 * archived / trashed / email documents all 404. PostPolicy `view` is bypassed on
 * purpose for published posts (the public surface), but the framework's
 * `allow_anonymous_in_local` dev bypass and the middleware stack
 * (`heisenberg.middleware.public`, default `['web']`) are the only auth gates.
 */
class PostPublicController
{
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

    /**
     * GET /posts/{locale}/{slug} — the public show.
     *
     * `?locale=` overrides the URL locale (validated against `heisenberg.locales`), the
     * same seam PreviewController uses to render the locale the visitor is browsing
     * rather than the one the post was authored in. The route param itself is also
     * validated against the configured set so a stray segment can't slip through.
     */
    public function show(Request $request, string $locale, string $slug): View
    {
        if (! LocaleConfig::isValid($locale)) {
            abort(404);
        }

        // Published posts only. scopePosts() excludes `type = 'email'` so an email
        // document never leaks into the public blog at this URL (emails have their
        // own dedicated surface at /emails/{slug}). withTrashed() would include trashed
        // rows; omit it so a trashed post 404s here like it does everywhere else.
        $class = (string) config('heisenberg.models.post', Post::class);
        $model = $class::query()
            ->posts()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $this->applyContentLocale($request);

        // Mirror PreviewController::showPost: the REQUEST locale drives title(),
        // renderBlocks() and the SEO payload so the rendered page matches the locale
        // the visitor is browsing, not the row's authoring locale. Post::title()'s
        // own-locale column / cross-locale fallback still applies.
        $activeLocale = app()->getLocale();

        $blocks = $model->blocks->map(fn ($block) => $block->content)->values()->all();

        $theme = $this->themes->load();
        $faces = [
            ...$this->themes->fontFaces($theme),
            ...$this->fonts->facesForBlocks($blocks),
        ];

        return view('heisenberg::preview', [
            'hasDoc' => true,
            'title' => $model->title($activeLocale) ?: 'Untitled post',
            'html' => $this->renderer->renderBlocks($blocks, $activeLocale),
            'blocksCss' => BlockViewData::blocksCss($this->registry),
            'stateCss' => $this->renderer->stateStylesCss($blocks),
            'themeCss' => $this->themes->css($theme),
            'fontsHref' => $this->fonts->css2Url($faces),
            'seo' => $this->seoPayload($model, $activeLocale),
            'featured' => $this->featuredPayload($model->featuredImage, $activeLocale),
            'alternates' => $this->alternatesPayload($model),
            'toc' => $model->tocEntries->map(fn ($entry) => [
                'label' => $entry->label,
                'anchor' => $entry->anchor,
            ])->values()->all(),
            'comments' => $model->allow_comments === false ? null : $this->commentsPayload($request, $model),
        ]);
    }

    /**
     * Maps PostSeoMetaProvider's return shape onto what preview.blade.php's head logic
     * expects: title/description/canonical/ogTitle/ogDescription/ogImage pass through, the
     * provider's single `robots` directive string is split into the view's two independent
     * noindex/nofollow booleans here, jsonLd passes through. Mirrors
     * PreviewController::seoPayload verbatim so the bundled public route and the editor
     * preview render identical head markup for the same post.
     *
     * @return array<string, mixed>
     */
    private function seoPayload(Post $model, string $locale): array
    {
        $meta = $this->seoMeta->meta($model, $locale);
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
     * @return array<string, mixed>|null
     */
    private function featuredPayload($file, string $locale): ?array
    {
        if ($file === null) {
            return null;
        }

        $payload = $file->imagePayload('hero');
        $payload['id'] = (int) $file->id;
        $payload['alt'] = $file->getAlt($locale);

        return $payload;
    }

    /**
     * hreflang alternates (docs/seo-system.md §5, docs/content-translation.md §0/§7).
     * Mirrors PreviewController::alternatesPayload — a post's other locales live as
     * `_<locale>` attribute variants on THIS SAME row, so every alternate points at
     * the SAME post through the configured {@see PostUrlResolver} with only the
     * `locale` swapped on an in-memory clone. A post translated into only its home
     * locale emits no hreflang block (a single-locale set is a self-referencing link
     * with no informational value).
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
     * `$model`'s public URL for a locale OTHER than (or the same as) its own — via the
     * SAME {@see PostUrlResolver} seam every other public URL in this package goes
     * through. See PreviewController::urlFor for the rationale (in-memory clone with
     * `locale` swapped; attributes never persisted).
     */
    private function urlFor(Post $model, string $locale): string
    {
        $clone = clone $model;
        $clone->locale = $locale;

        return $this->urls->url($clone);
    }

    /**
     * Mirrors PreviewController::commentsPayload — the native comments section's
     * render payload. `created_at` is formatted server-side here (not left for JS) so
     * the section is consistent regardless of whether the provider handed back a
     * Carbon instance (the native adapter) or a plain string (any custom adapter).
     *
     * @return array<string, mixed>
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

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
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
     * Honour an explicit `?locale=` on a public show (validated against the configured
     * set; anything else leaves the app locale alone). Mirrors PreviewController::
     * applyContentLocale so the editor preview and the public page agree about what
     * `?locale=` means.
     */
    private function applyContentLocale(Request $request): void
    {
        $requested = trim((string) $request->query('locale', ''));

        if ($requested !== '' && LocaleConfig::isValid($requested)) {
            app()->setLocale($requested);
        }
    }
}
