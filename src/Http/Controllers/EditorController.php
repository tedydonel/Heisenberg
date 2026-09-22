<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Category;
use Heisenberg\Models\Pattern;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Models\Tag;
use Heisenberg\Services\AiProviderRegistry;
use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\EmailBlockCoverageService;
use Heisenberg\Services\EmailVariableCatalog;
use Heisenberg\Services\FontCatalogService;
use Heisenberg\Services\IconLibraryService;
use Heisenberg\Services\SavedThemeRepository;
use Heisenberg\Services\SeoAnalyzer;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Support\AnimationCatalog;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Support\LocaleConfig;
use Heisenberg\Support\SupportsStyle;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

final class EditorController
{
    // Must stay in sync with 34-canvas.css's --hb-page-padding-x/-y fallbacks.
    private const DEFAULT_PAGE_PADDING_X = 56;

    private const DEFAULT_PAGE_PADDING_Y = 56;

    /**
     * GET /editor — a blank POST document.
     *
     * `?type=email` used to seed a blank email here. Emails now have their own authoring address
     * (docs/email-system.md §6), so that query form redirects to it rather than rendering a
     * second, differently-addressed email editor: one document type, one URL.
     */
    public function index(Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, EmailVariableCatalog $emailVariables): View|RedirectResponse
    {
        if ($this->documentType($request->query('type')) === 'email') {
            return redirect()->route('heisenberg.editor.email.new');
        }

        return $this->blankDocument('post', $request, $registry, $themes, $savedThemes, $fonts, $emailVariables);
    }

    /**
     * GET /editor/email — a blank EMAIL document (docs/email-system.md §6). Same shell as
     * index() above, stamped with the type the FIRST save will carry (see PostController::save()'s
     * create-only `type` handling) — which is what gates the palette down to the email-safe
     * blocks, narrows the canvas to the 600px shell, and gives the Post tab its email shape.
     */
    public function newEmail(Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, EmailVariableCatalog $emailVariables): View
    {
        return $this->blankDocument('email', $request, $registry, $themes, $savedThemes, $fonts, $emailVariables);
    }

    private function blankDocument(string $documentType, Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, EmailVariableCatalog $emailVariables): View
    {
        // The editor is one big server-rendered component tree; the FIRST render
        // after a view-cache rebuild compiles/loads hundreds of Blade views and
        // can exceed a host's default 30s max_execution_time (observed on a
        // Windows host: cold ~35s, warm ~11s) — dying there is a white page.
        // Never under CLI: there the default is UNLIMITED, so this would impose
        // a 120s cap on someone's test suite or queue worker instead of raising one.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(120);
        }

        $shared = $this->sharedViewData($registry, $themes, $savedThemes, $fonts);

        return view('heisenberg::editor.index', array_merge($shared, [
            // No post yet — the first Save is what gives it an id; the Post tab's
            // taxonomy/layout controls render disabled until hb:post-id fires.
            'postId' => null,
            'postTitle' => '',
            // The editing-locale seed (docs/content-translation.md §0/Wave 2): a brand-new
            // document has no `locale` column yet, so the client's default editing locale — and
            // the "home locale" the bare/suffixed write rule keys off — falls back to the
            // config default, same source PostController's own create path would use.
            'postLocale' => LocaleConfig::default(),
            'postTitleByLocale' => array_fill_keys(LocaleConfig::locales(), ''),
            'contentVersion' => 0,
            'initialBlocks' => [],
            'postCategoryIds' => [],
            'postTagIds' => [],
            'postPagePaddingX' => self::DEFAULT_PAGE_PADDING_X,
            'postPagePaddingY' => self::DEFAULT_PAGE_PADDING_Y,
            'postAllowComments' => true,
            'postMeta' => $this->postMeta(null, $documentType),
            // The SEO/Social panel's shared slug (bare, no leading `/`) + seed payload
            // (docs/seo-system.md §3, Wave S2a) — null model means the blank /editor document,
            // same defaults a first save would produce. The panel itself renders disabled until
            // hb:post-id fires, matching every other Summary control's "save first" posture.
            'postSlug' => '',
            'postSeo' => $this->postSeo(null),
            'postTocEntries' => [],
            // No post yet — the Summary's schedule/publish-date inputs have nothing to seed.
            'postScheduledAt' => null,
            'postPublishedAt' => null,
            // The Translations disclosure's "unsaved" marker (docs/content-translation.md §0/Wave
            // 2) — null renders a plain locale-switch row list (no completeness counts, nothing
            // saved yet to count) instead of TranslationStatusService's real per-locale rows.
            'postTranslations' => null,
            'documentType' => $documentType,
            // The Components/quick-insert palette (docs/email-system.md §7-E3): every enabled
            // block for a plain post, but only the `email`-surface subset (BlockRegistryService::
            // contractsFor('email')) once this document is one — filtered SERVER-SIDE, never by
            // client JS re-reading the full registry. See paletteBlocks()'s own docblock.
            'paletteBlocks' => $this->paletteBlocks($registry, $shared['registry'], $documentType),
            'emailVariables' => $documentType === 'email' ? $emailVariables->definitions() : [],
        ]));
    }

    /**
     * GET /editor/{post} — opens an EXISTING POST in the editor shell.
     *
     * An email opened here redirects to its own authoring address (docs/email-system.md §6)
     * instead of rendering in the post surface. The two document types answer to different URLs
     * precisely so the shell around them can differ; serving an email from the post URL would put
     * it back in the surface the split exists to keep it out of. A redirect rather than a 404
     * because this is a link people already hold — a saved bookmark, a row in a host's admin list.
     */
    public function show(Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, EmailVariableCatalog $emailVariables, string $post): View|RedirectResponse
    {
        $model = $this->openable($request, $post);

        return $model->type === 'email'
            ? redirect()->route('heisenberg.editor.email.show', ['post' => $model->getKey()])
            : $this->openDocument($model, $request, $registry, $themes, $savedThemes, $fonts, $emailVariables);
    }

    /**
     * GET /editor/email/{post} — opens an EXISTING EMAIL document. The mirror of show() above: a
     * plain post asked for here redirects back to the post surface, so each document is reachable
     * at exactly one authoring URL no matter which one a link points at.
     */
    public function showEmail(Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, EmailVariableCatalog $emailVariables, string $post): View|RedirectResponse
    {
        $model = $this->openable($request, $post);

        return $model->type === 'email'
            ? $this->openDocument($model, $request, $registry, $themes, $savedThemes, $fonts, $emailVariables)
            : redirect()->route('heisenberg.editor.show', ['post' => $model->getKey()]);
    }

    /**
     * Resolve a post for editing. The model class is resolved manually because
     * config('heisenberg.models.post') is host-swappable (no implicit route binding). Runs the
     * same PostPolicy view check as PostController::show() — the editor ships the post's full
     * content, so an anonymous visitor must not be able to read drafts by ID. Authorization
     * happens BEFORE either caller decides where to send the request, so a redirect never reveals
     * that an id exists to someone who may not read it.
     */
    private function openable(Request $request, string $post): Post
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);
        $model = $class::query()->with(['blocks', 'categories', 'tags', 'featuredImage', 'tocEntries', 'seoMeta'])->findOrFail($post);

        Gate::forUser($request->user() ?? new GuestActor())->authorize('view', $model);

        return $model;
    }

    /** Renders the editor shell around an already-resolved, already-authorized document. */
    private function openDocument(Post $model, Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, EmailVariableCatalog $emailVariables): View
    {
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(120); // same cold-render headroom as index()
        }

        // Seed the Post tab's featured image on first render — the {id, url, srcset, sizes, alt}
        // shape the inspector's hidden inputs expect so the preview (replace/remove) button
        // shows the existing image rather than the empty dropzone.
        $featuredImage = null;
        if ($model->featuredImage !== null) {
            $payload = $model->featuredImage->imagePayload('hero');
            $payload['id'] = (int) $model->featuredImage->id;
            $payload['alt'] = $model->featuredImage->getAlt((string) ($model->locale ?? 'en'));
            $featuredImage = $payload;
        }

        // A document never changes type (docs/email-system.md §3) — reads straight off the
        // saved row rather than re-deriving anything.
        $documentType = $this->documentType((string) $model->type);
        $shared = $this->sharedViewData($registry, $themes, $savedThemes, $fonts);

        // The editing-locale seed (docs/content-translation.md §0/Wave 2): the client defaults to
        // EDITING the post's own locale — the "home locale" the bare/suffixed write rule keys off
        // too — falling back to the config default only for a row saved before `locale` existed.
        $postLocale = (string) ($model->locale ?: LocaleConfig::default());

        return view('heisenberg::editor.index', array_merge($shared, [
            'postId' => $model->getKey(),
            'postTitle' => $model->title($postLocale),
            'postLocale' => $postLocale,
            // title_en/title_fr have no bare/unsuffixed column (unlike a block's translatable
            // attributes) — every configured locale's own raw value, so the client can swap the
            // visible title on a locale switch and remember an unsaved edit to EITHER locale
            // without losing the other (see topbar.blade.php's hbTitleByLocale).
            'postTitleByLocale' => collect(LocaleConfig::locales())
                ->mapWithKeys(fn (string $l) => [$l => (string) ($model->getAttribute("title_{$l}") ?? '')])
                ->all(),
            'contentVersion' => (int) $model->content_version,
            // Each block ships exactly as stored — the shape newBlockModel() produces client-side.
            'initialBlocks' => $model->blocks->map(fn ($block) => $block->content)->values()->all(),
            // Stringified so the checklist's strict in_array() compares like-for-like with
            // categoryOptions()/tagOptions()'s string values.
            'postCategoryIds' => $model->categories->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'postTagIds' => $model->tags->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'postPagePaddingX' => $model->page_padding_x ?? self::DEFAULT_PAGE_PADDING_X,
            'postPagePaddingY' => $model->page_padding_y ?? self::DEFAULT_PAGE_PADDING_Y,
            'postAllowComments' => $model->allow_comments ?? true,
            'postFeaturedImage' => $featuredImage,
            'postMeta' => $this->postMeta($model, $documentType),
            // The SEO/Social panel's shared slug + seed payload (docs/seo-system.md §3) — see
            // postSeo()'s own docblock for the accessor-fallback rationale.
            'postSlug' => (string) $model->slug,
            'postSeo' => $this->postSeo($model),
            // The SEO panel's preview crumb + canonical-URL placeholder read from the host's
            // PostUrlResolver (config('heisenberg.seo.url_template') or its own resolver binding) —
            // never a hardcoded "yoursite.com" string — so the author sees the URL their actual
            // blog routes will publish under.
            'postPublicUrl' => app(PostUrlResolver::class)->url($model),
            // Seeds the Summary's schedule/publish-date <input type="datetime-local"> pair, each
            // of which expects a timezone-less "Y-m-d\TH:i" — a bare ISO offset string won't
            // populate the widget.
            'postScheduledAt' => $model->scheduled_at?->format('Y-m-d\TH:i'),
            'postPublishedAt' => $model->published_at?->format('Y-m-d\TH:i'),
            // The Post tab's authored table of contents (Post::tocEntries(), ordered) — {label,
            // anchor} pairs only; the modal's own script owns render/reorder/save.
            'postTocEntries' => $model->tocEntries->map(fn ($entry) => [
                'label' => $entry->label,
                'anchor' => $entry->anchor,
            ])->values()->all(),
            // The Post tab's Translations section + topbar language dropdown (docs/
            // content-translation.md §0/Wave 2): per-locale COMPLETENESS on this one row —
            // {locale, is_default, title, excerpt, blocks_translated, blocks_total, complete}.
            'postTranslations' => app(TranslationStatusService::class)->statuses($model),
            'documentType' => $documentType,
            // See index()'s own note — filtered server-side to the email surface once this
            // document is one.
            'paletteBlocks' => $this->paletteBlocks($registry, $shared['registry'], $documentType),
            'emailVariables' => $documentType === 'email' ? $emailVariables->definitions() : [],
        ]));
    }

    /**
     * Normalize an untrusted `type` (a query string param on index(), or the saved row's own
     * column on show()) to one of the two known values — anything else, including an absent
     * param, reads as the existing default 'post' (docs/email-system.md §3).
     */
    private function documentType(?string $raw): string
    {
        return $raw === 'email' ? 'email' : 'post';
    }

    /**
     * The Components tab + quick-inserter's block palette (docs/email-system.md §7-E3): the
     * full client registry for a plain post, or — once $documentType is 'email' — only the
     * subset {@see BlockRegistryService::contractsFor()} reports for the `email` surface (10 of
     * the 12 shipped contracts; embed/icon excluded, §4). Filtering happens HERE, against the
     * already-localized $fullRegistry (BlockViewData::clientBlocks()'s shape), so
     * panel-components-blocks.blade.php and quick-inserter.blade.php — both handed this array
     * instead of the full `$registry` prop — never see a card for a block the email surface
     * doesn't support; there is no client-side re-filtering to keep in sync.
     *
     * @param array<string, array<string, mixed>> $fullRegistry BlockViewData::clientBlocks()'s shape
     * @return array<string, array<string, mixed>>
     */
    private function paletteBlocks(BlockRegistryService $registry, array $fullRegistry, string $documentType): array
    {
        if ($documentType !== 'email') {
            return $fullRegistry;
        }

        $emailNames = array_map(
            static fn (array $contract): string => (string) ($contract['name'] ?? ''),
            $registry->contractsFor('email'),
        );

        return array_intersect_key($fullRegistry, array_flip($emailNames));
    }

    /**
     * The Post tab'sSummary rows, from the REAL post (2026-08-08 — the section used to
     * render hardcoded placeholder strings). Each row carries a `key` the inspector's own
     * script uses to keep the value live: status/publish/url refresh from `hb:post-saved`
     * (every successful save echoes the post payload), blocks from `hb:blocks-changed`.
     * A null model is the blank /editor document — the same defaults a first save produces.
     *
     * The `status` row additionally carries `raw` (the un-translated status key the client
     * compares against) and `options` (current status + its legal transitions.php targets,
     * each {value,label}) — inspector.blade.php renders those as a ui/select so the Summary
     * can actually DRIVE a transition, not just display one. The server remains the sole
     * enforcer of tier permissions (PostController::applyTransition); this list only decides
     * what's worth offering in the menu.
     *
     * The `url` row's `raw` is the bare slug (never the leading `/`) — inspector.blade.php
     * seeds the editable slug input from it, separate from `value`'s already-formatted
     * "/slug" or "—" display text.
     *
     * The `blocks` row (a live block count) was removed 2026-08-11 — nothing else in the
     * Summary depended on it, and the owner asked for it gone outright.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function postMeta(?Post $model, string $documentType = 'post'): array
    {
        $urlRow = [
            // Same editable slug on both document types, but it means different things and so
            // reads differently: a post's public path, or — for an email — the ONE address the
            // built email is served at (docs/email-system.md §6.1), prefix included, so the
            // author can see what the link they are about to send actually looks like.
            'key' => 'url',
            'label' => (string) __($documentType === 'email'
                ? 'heisenberg::editor.inspector.summary_email_address'
                : 'heisenberg::editor.inspector.summary_url'),
            'value' => ($model !== null && (string) $model->slug !== '')
                ? $this->slugPath($documentType) . $model->slug
                : '—',
            'raw' => (string) ($model?->slug ?? ''),
        ];

        // An email document has no lifecycle of its own — when a campaign sends is host
        // business, not Heisenberg's — so the Summary uses authoring metrics instead of
        // post-only status/publish controls. The subject is the document title, while the
        // content and variable counts are read-only snapshots of the saved document.
        if ($documentType === 'email') {
            $locale = (string) ($model?->locale ?: LocaleConfig::default());
            $subject = $model !== null ? trim((string) $model->title($locale)) : '';
            $blocks = $model?->blocks ?? collect();
            $blockContents = $blocks->pluck('content')->all();
            $serialized = json_encode($blockContents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            preg_match_all('/\\{\\{\\s*([a-z][a-z0-9_]*(?:\\.[a-z][a-z0-9_]*)*)\\s*\\}\\}/i', $serialized, $matches);
            $variableCount = count(array_unique($matches[1] ?? []));

            $rows = [
                [
                    'key' => 'subject',
                    'label' => (string) __('heisenberg::editor.inspector.summary_email_subject'),
                    'value' => $subject !== '' ? $subject : '—',
                ],
                [
                    'key' => 'email_blocks',
                    'label' => (string) __('heisenberg::editor.inspector.summary_email_blocks'),
                    'value' => (string) $blocks->count(),
                ],
                [
                    'key' => 'variables',
                    'label' => (string) __('heisenberg::editor.inspector.summary_email_variables'),
                    'value' => (string) $variableCount,
                ],
            ];

            // Silent-failure visibility (docs/email-system.md §4): a snapshot of the SAVED
            // document, same "computed once at render time" posture as the two metrics above —
            // never live-recomputed as the canvas changes, just like them. Two separate rows
            // (never merged) because they carry different confidence: `dropped` is certain (no
            // `email` template at all), `degraded` is a real but survivable difference.
            $coverage = app(EmailBlockCoverageService::class)->documentSummary($blockContents);
            if ($coverage['dropped_count'] > 0) {
                $rows[] = [
                    'key' => 'email_coverage_dropped',
                    'label' => (string) __('heisenberg::editor.inspector.summary_email_dropped_label'),
                    'value' => str_replace(
                        ':count',
                        (string) $coverage['dropped_count'],
                        (string) __('heisenberg::editor.inspector.summary_email_dropped_value')
                    ),
                ];
            }
            if ($coverage['degraded_count'] > 0) {
                $rows[] = [
                    'key' => 'email_coverage_degraded',
                    'label' => (string) __('heisenberg::editor.inspector.summary_email_degraded_label'),
                    'value' => str_replace(
                        ':count',
                        (string) $coverage['degraded_count'],
                        (string) __('heisenberg::editor.inspector.summary_email_degraded_value')
                    ),
                ];
            }

            $rows[] = $urlRow;

            return $rows;
        }

        $currentStatus = (string) ($model?->status ?? 'draft');
        $transitions = (array) config('heisenberg.lifecycle.transitions', []);
        $targets = array_values(array_unique(array_merge([$currentStatus], (array) ($transitions[$currentStatus] ?? []))));

        return [
            [
                'key' => 'status',
                'label' => (string) __('heisenberg::editor.inspector.summary_status'),
                'value' => $this->statusLabel($currentStatus),
                'raw' => $currentStatus,
                'options' => array_map(fn (string $status) => [
                    'value' => $status,
                    'label' => $this->statusLabel($status),
                ], $targets),
            ],
            [
                // Rendered as an editable datetime-local input (postPublishedAt seeds it, see
                // show()/index()) — 'value' is unused for this key but kept for shape parity
                // with the other rows.
                'key' => 'publish',
                'label' => (string) __('heisenberg::editor.inspector.summary_publish'),
                'value' => '',
            ],
            $urlRow,
        ];
    }

    /** `/` for a post, `/{email.route_prefix}/` for an email — see postMeta()'s `url` row. */
    private function slugPath(string $documentType): string
    {
        if ($documentType !== 'email') {
            return '/';
        }

        $prefix = trim((string) config('heisenberg.email.route_prefix', 'emails'), '/') ?: 'emails';

        return '/' . $prefix . '/';
    }

    /**
     * The SEO/Social panel's seed payload (docs/seo-system.md §3, Wave S2a). The five localized
     * fields go through {@see SeoMeta}'s own fallback accessors — own-locale
     * first, cross-locale fallback, the same {@see PublicFile::getAlt()}
     * posture {@see SeoAnalyzer}'s own `resolve()` already uses — so the
     * panel's starting point matches "what would actually be used publicly right now", not a
     * blank field next to content that already exists on the other locale's row. This is
     * deliberately DIFFERENT from PostController::seoPayload()'s post-save echo, which reads the
     * raw own-locale columns instead — see that method's own docblock for why the two need to
     * differ. `og_image`/`canonical_url` have no locale half to fall back across. A null model
     * (blank /editor document) or a post with no SeoMeta row yet both resolve to the same shape
     * a first save would produce ('index, follow' + sitemap on, matching the migration's own
     * column defaults).
     *
     * @return array<string, mixed>
     */
    private function postSeo(?Post $model): array
    {
        $locale = ((string) ($model?->locale ?? 'en')) === 'fr' ? 'fr' : 'en';
        $seo = $model?->seoMeta;
        $robots = (string) ($seo?->robots ?? 'index, follow');

        return [
            'meta_title' => $seo?->metaTitle($locale) ?? '',
            'meta_description' => $seo?->metaDescription($locale) ?? '',
            'og_title' => $seo?->ogTitle($locale) ?? '',
            'og_description' => $seo?->ogDescription($locale) ?? '',
            'focus_keyphrase' => $seo?->focusKeyphrase($locale) ?? '',
            'og_image' => (string) ($seo?->og_image ?? ''),
            'canonical_url' => (string) ($seo?->canonical_url ?? ''),
            'robots_index' => ! str_contains($robots, 'noindex'),
            'robots_follow' => ! str_contains($robots, 'nofollow'),
            'in_sitemap' => $seo?->in_sitemap ?? true,
        ];
    }

    /**
     * `__ID__` template for the SEO score endpoint (docs/seo-system.md §4, Wave S2b) — that
     * route/controller is built in a PARALLEL wave (see this file's own CONCURRENCY note in the
     * class docblock area, or the task that produced this method); `Route::has()` guards against
     * it not having landed yet so THIS file's own tests never depend on the other wave's landing
     * order, falling back to the exact same literal URL shape the named route resolves to.
     */
    private function postSeoAnalyzeUrlTemplate(): string
    {
        return Route::has('heisenberg.editor.seo.analyze')
            ? route('heisenberg.editor.seo.analyze', ['post' => '__ID__'])
            : '/editor/posts/__ID__/seo/analyze';
    }

    /**
     * Translated display name for a lifecycle status — `summary_status_{status}` in
     * editor.php's `inspector` group. Falls back to a humanized version of the raw key so a
     * host that reconfigures `heisenberg.lifecycle.transitions` with its own status names
     * never renders a raw, untranslated lang key.
     */
    private function statusLabel(string $status): string
    {
        $key = 'heisenberg::editor.inspector.summary_status_' . $status;

        return Lang::has($key) ? (string) __($key) : ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Every status name the transitions graph can ever mention (sources AND targets),
     * translated — the client-side payload inspector.blade.php's script uses to relabel
     * the status select's options after each save, when the post's CURRENT status (and so
     * its legal next edges) has changed. See postMeta()'s docblock for the per-row options.
     *
     * @param array<string, list<string>> $transitions
     * @return array<string, string>
     */
    private function statusLabels(array $transitions): array
    {
        $statuses = array_unique(array_merge(array_keys($transitions), ...array_values($transitions)));

        $labels = [];
        foreach ($statuses as $status) {
            $labels[$status] = $this->statusLabel($status);
        }

        return $labels;
    }

    /**
     * Initial patterns list seeded into the editor's Blocks tab. Loaded once at render
     * time — a save through the toolbar's save-as-block icon then re-fetches through
     * patternsIndexUrl (panel-components-blocks.blade.php) so the tab picks up new entries
     * without a full reload.
     *
     * @return array<int, array{id:int, name:string, blocks:array<int, mixed>, updated_at:?string}>
     */
    private function patternsForView(): array
    {
        return Pattern::query()
            ->orderBy('name')
            ->get(['id', 'name', 'blocks', 'updated_at'])
            ->map(static fn (Pattern $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'blocks' => $p->blocks ?: [],
                'updated_at' => optional($p->updated_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * View data both `index()` and `show()` need. No registry allow-list — the client
     * ships whatever BlockRegistryService discovers under resources/blocks.
     */
    private function sharedViewData(BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts): array
    {
        // Seeds the Summary status control's client-side option rebuild (inspector.blade.php) —
        // the FULL map, never hardcoded client-side, so a host's own config('heisenberg.lifecycle.
        // transitions') override is honoured without an editor.php code change.
        $postStatusTransitions = (array) config('heisenberg.lifecycle.transitions', []);

        return [
            'postStatusTransitions' => $postStatusTransitions,
            'postStatusLabels' => $this->statusLabels($postStatusTransitions),
            // User's saved reusable block compositions — the toolbar's save-as-block writes to
            // /editor/patterns, the Components panel's Blocks tab reads /editor/patterns and
            // inserts a picked pattern through hbEditor.insertPattern (toolbar-composition.md §8).
            'patterns' => $this->patternsForView(),
            'patternsIndexUrl' => route('heisenberg.editor.patterns.index'),
            'patternsStoreUrl' => route('heisenberg.editor.patterns.store'),
            'patternsDestroyUrl' => route('heisenberg.editor.patterns.destroy'),
            'registry' => BlockViewData::clientBlocks($registry),
            'blocksCss' => BlockViewData::blocksCss($registry),
            'theme' => $themes->load(),
            // The theme's --hb-t-* custom properties, so token references resolve on the canvas.
            'themeCss' => $themes->css(),
            // Merged theme-over-config token maps — what the Block.style variable picker offers.
            'themeTokens' => $themes->tokens(),
            'savedThemes' => $savedThemes->all(),
            // First page of fonts so the searchable select never opens empty.
            'fontOptions' => array_map(
                static fn (array $f): array => ['value' => $f['family'], 'label' => $f['family']],
                $fonts->search('', 40),
            ),
            // Save payloads must echo the hash the page was rendered against —
            // BlocksPayloadService rejects a stale-schema payload.
            'registryHash' => $registry->computeHash(),
            // Seeds the locale switcher's return field; the editor-index fallback covers
            // test fixtures that bypass routing.
            'localeReturn' => url()->current() !== url('/') ? url()->current() : route('heisenberg.editor.index', [], false),
            // categoriesIndexUrl/tagsIndexUrl double as list AND store endpoints (GET vs POST).
            // The __ID__/__ITEM_ID__ templates follow topbar.blade.php's convention for routes
            // whose numeric ids don't exist yet at render time.
            'categoriesIndexUrl' => route('heisenberg.editor.categories.index'),
            'tagsIndexUrl' => route('heisenberg.editor.tags.index'),
            'postCategoryUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/categories/__ITEM_ID__',
            'postTagsUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/tags/__ITEM_ID__',
            'postLayoutUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/layout',
            'postRevisionsUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/revisions',
            // Move to trash — DELETE on the SAME URL PostController's own update/show routes use
            // (same base template convention as the others here), just a different verb.
            'postTrashUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__',
            'postDiscussionUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/discussion',
            'postFeaturedImageUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/featured-image',
            'postTocUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/toc',
            // SEO score & checklist (docs/seo-system.md §4) — see postSeoAnalyzeUrlTemplate()'s
            // own docblock for the Route::has() guard.
            'postSeoAnalyzeUrlTemplate' => $this->postSeoAnalyzeUrlTemplate(),
            // Email authoring's two read-only endpoints (docs/email-system.md §7-E3,
            // EmailPreviewController) — same __ID__ template convention as every other
            // post-scoped URL above. Both are gated exactly like the post preview route
            // (PostPolicy `view`), so they resolve for any documentType; the topbar/footer
            // scripts only ever call them when the CURRENT document is an email.
            'emailPreviewUrlTemplate' => route('heisenberg.editor.email.preview', ['post' => '__ID__']),
            'emailSizeUrlTemplate' => route('heisenberg.editor.email.size', ['post' => '__ID__']),
            'emailExportUrlTemplate' => route('heisenberg.editor.email.export', ['post' => '__ID__']),
            // The topbar language dropdown + Post-tab Translations section (docs/
            // content-translation.md §0/Wave 2): which locales this install supports, and their
            // display labels — the client needs the labels to relabel the dropdown trigger after
            // an in-place switch, without a second lang-key lookup mechanism client-side.
            'contentLocales' => LocaleConfig::locales(),
            'contentLocaleLabels' => collect(LocaleConfig::locales())
                ->mapWithKeys(fn (string $l) => [$l => (string) __('heisenberg::editor.locales.' . $l)])
                ->all(),
            // Initial-render seed only — the real, policy-gated read/write happens in the
            // taxonomy controllers once the user acts.
            'categoryOptions' => $this->categoryOptions(),
            'tagOptions' => $this->tagOptions(),
            // AI settings modal seed. Resolved here rather than added to index()/show()'s
            // signatures because it is view-only data with no request dependency — the
            // provider catalogue comes from config and the settings from a JSON file.
            // `aiProviders` never carries key material: each row reports `configured` as a
            // boolean and names its env var, nothing more (see AiProviderRegistry).
            'aiPayload' => [
                'settings' => app(AiSettingsRepository::class)->load(),
                'providers' => app(AiProviderRegistry::class)->describe(),
                'presets' => app(AiProviderRegistry::class)->availablePresets(),
                'formats' => app(AiProviderRegistry::class)->formats(),
            ],
            // Model picker for the chat composer, rendered with the real
            // ui/select. Only enabled models are offered; the active one is
            // preselected. Built here (not client-fetched) so the select is a
            // real, server-rendered component, not markup assembled in JS.
            'aiModelOptions' => array_values(array_map(
                static fn (array $m): array => ['value' => $m['provider'] . ':' . $m['id'], 'label' => $m['label'] ?? $m['id']],
                array_filter(
                    app(AiSettingsRepository::class)->load()['models'],
                    static fn (array $m): bool => ($m['enabled'] ?? true) !== false,
                ),
            )),
            'aiActiveModel' => app(AiSettingsRepository::class)->activeModel()?->key(),
            'aiSettingsUrl' => route('heisenberg.editor.ai.settings.update'),
            // __ID__ placeholders, following topbar.blade.php's convention for
            // routes whose segment isn't known at render time.
            'aiKeyUrlTemplate' => route('heisenberg.editor.ai.providers.key', ['provider' => '__ID__']),
            'aiDiscoverUrlTemplate' => route('heisenberg.editor.ai.providers.discover', ['provider' => '__ID__']),
            'aiMcpTestUrl' => route('heisenberg.editor.ai.mcp.test'),
        ];
    }

    /**
     * Every category, flattened into one ordered list with depth-indented labels
     * ("Parent" then "— Child") — an option list can't draw a real tree.
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
        // $seen terminates the walk if imported data carries a parent cycle the API forbids.
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
     * Every tag, flat and name-ordered (tags have no tree).
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
        // DEV-ONLY, now enforced in code (2026-08-10 blueprint audit): this
        // route is a stand-in for the web server's static /uploads handling,
        // but nothing gated it — mounted in production it would quietly become
        // an unauthenticated PHP file server for the whole uploads disk,
        // contradicting the blueprint's "no PHP in the read path" invariant.
        // Same environment discipline as LocalDevRoleGate: re-checked per call.
        if (! app()->environment('local', 'testing')) {
            return response('', 404);
        }

        // Defense-in-depth: never hand the disk a traversal or NUL path, even though
        // Flysystem's own normalizer also rejects '..'.
        if (str_contains($path, "\0") || in_array('..', preg_split('#[/\\\\]#', $path) ?: [], true)) {
            return response('', 404);
        }

        $disk = Storage::disk((string) config('heisenberg.media.disk', 'uploads'));

        if (! $disk->exists($path)) {
            return response('', 404);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Cache keys the three asset-version helpers below memoize into outside
     * `local` (see cssAssetVersion() etc.). Public so tests can Cache::forget()
     * a stale entry after mutating whatever the version is derived from,
     * without needing to know a magic string.
     */
    public const CSS_VERSION_CACHE_KEY = 'heisenberg:editor-css:version';

    public const ANIMATIONS_VERSION_CACHE_KEY = 'heisenberg:editor-animations-css:version';

    public const SUPPORTS_VERSION_CACHE_KEY = 'heisenberg:editor-supports-css:version';

    /**
     * Absolute paths of every source file concatenated into the editor.css
     * bundle, in the same order css() concatenates them.
     *
     * @return list<string>
     */
    private static function cssSourceFiles(): array
    {
        $base = dirname(__DIR__, 3) . '/resources/css/';
        $files = [$base . 'tokens.css', ...(glob($base . 'editor/*.css') ?: [])];

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * Content fingerprint for a list of files: each file's path + a hash of its
     * bytes. Exposed as public (and file-list-parameterized) so it's unit-testable
     * without mutating the package's real shipped CSS files.
     *
     * This used to hash path + mtime + size, which was cheaper but wrong in two
     * ways, both of which serve a STALE stylesheet under an `immutable` cache
     * header — the worst possible failure for this cache:
     *  - PHP caches stat() results per file for the life of the process, so a file
     *    edited after its first stat kept reporting the old mtime/size. That is how
     *    this surfaced: a test that rewrote a file with different content AND a
     *    later mtime still produced an identical fingerprint on Linux.
     *  - Second-resolution mtimes cannot distinguish an edit made in the same
     *    second that happens to preserve the byte length.
     * Reading the bytes costs nothing extra in practice: css() already reads every
     * one of these files to build the bundle it serves, and outside `local` the
     * computed version is memoized for 300s (see cachedVersion()).
     *
     * @param list<string> $files
     */
    public static function fingerprintFiles(array $files): string
    {
        $parts = [];
        foreach ($files as $file) {
            $parts[] = $file . ':' . (@md5_file($file) ?: '0');
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    /** Short content-hash version for a generated (in-PHP, not on-disk) CSS string. */
    public static function contentVersion(string $content): string
    {
        return substr(sha1($content), 0, 12);
    }

    /**
     * Shared local/non-local caching split for the three version helpers below.
     * Outside `local`, memoizing the (cheap but not free) computation in the
     * app cache means a burst of requests doesn't repeat it on every one, and a
     * short TTL means a deploy that changes the CSS is picked up within minutes
     * without a manual cache flush. `local` always recomputes so an on-disk CSS
     * edit shows up on the very next reload.
     *
     * A host's cache backend is not this route's problem: an unmigrated
     * `database` store, an unreachable Redis, etc. must never turn serving the
     * editor's CSS into a 500 — any Cache failure here just falls back to
     * computing the version directly, same as `local`.
     */
    private static function cachedVersion(string $key, \Closure $compute): string
    {
        if (app()->environment('local')) {
            return $compute();
        }

        try {
            return Cache::remember($key, 300, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    /**
     * Cache-busting version for /heisenberg-assets/editor.css — also what the
     * `<link>` tags append as `?v=` (see e.g. resources/views/editor/layouts/app.blade.php)
     * and what css() uses as its ETag.
     */
    public static function cssAssetVersion(): string
    {
        return self::cachedVersion(
            self::CSS_VERSION_CACHE_KEY,
            static fn (): string => self::fingerprintFiles(self::cssSourceFiles()),
        );
    }

    /**
     * Cache-busting version for /heisenberg-assets/editor-animations.css. The
     * bundle is generated purely from AnimationCatalog's own PHP constants (no
     * disk file, no config/theme lookup — see AnimationCatalog::css()), so the
     * version is a content hash of the generated string rather than a file
     * fingerprint.
     */
    public static function animationsAssetVersion(): string
    {
        return self::cachedVersion(
            self::ANIMATIONS_VERSION_CACHE_KEY,
            static fn (): string => self::contentVersion(AnimationCatalog::css()),
        );
    }

    /**
     * Cache-busting version for /heisenberg-assets/editor-supports.css. Same
     * reasoning as animationsAssetVersion(): SupportsStyle::css() is generated
     * purely from code today (no block-contract or theme config lookups), so a
     * content hash of its output is the correct and cheapest version signal —
     * if that ever starts reading per-theme config, this must hash that input
     * too, or the cached version could go stale across a config change alone.
     */
    public static function supportsAssetVersion(): string
    {
        return self::cachedVersion(
            self::SUPPORTS_VERSION_CACHE_KEY,
            static fn (): string => self::contentVersion(SupportsStyle::css()),
        );
    }

    /**
     * Shared conditional-GET handling for the three generated CSS bundles below.
     *
     * The URL's own `?v=` is the fast path: when it matches the current
     * version the response can never change without the URL itself changing,
     * so it's safe to cache forever (`immutable`). Anything else — no `v`, a
     * stale `v` (e.g. an old cached HTML page still linking a previous hash),
     * or a direct hit on the bare route, as several tests do — falls back to
     * standard ETag revalidation: a 304 when the caller already has this exact
     * content, a fresh 200 body otherwise. `local` never issues the immutable
     * response, so editing CSS on disk stays visible on the next reload.
     */
    private function cssBundleResponse(Request $request, string $version, \Closure $buildBody): Response
    {
        $etag = '"' . $version . '"';
        $ifNoneMatch = trim((string) $request->headers->get('If-None-Match', ''));

        $cacheControl = app()->environment('local')
            ? 'no-cache'
            : ($version === (string) $request->query('v', '')
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=0, must-revalidate');

        $headers = [
            'Content-Type' => 'text/css; charset=UTF-8',
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
        ];

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return response('', 304, $headers);
        }

        return response($buildBody(), 200, $headers);
    }

    public function css(Request $request): Response
    {
        return $this->cssBundleResponse($request, self::cssAssetVersion(), static function (): string {
            $editorCss = '';
            foreach (self::cssSourceFiles() as $file) {
                $editorCss .= (string) file_get_contents($file) . "\n";
            }

            return $editorCss;
        });
    }

    /**
     * Shared animation catalog (keyframes + trigger classes) — generated, not a
     * disk asset. Consumed by preview.blade.php (heisenberg.editor.asset.animations).
     */
    public function animationsCss(Request $request): Response
    {
        return $this->cssBundleResponse(
            $request,
            self::animationsAssetVersion(),
            static fn (): string => AnimationCatalog::css(),
        );
    }

    /**
     * The generated supports-capabilities stylesheet — a no-op for any block root
     * that doesn't carry hb-supports.
     */
    public function supportsCss(Request $request): Response
    {
        return $this->cssBundleResponse(
            $request,
            self::supportsAssetVersion(),
            static fn (): string => SupportsStyle::css(),
        );
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

    /**
     * One block-library icon (see IconLibraryService — the imported VvvebJs
     * collection). The set/slug pair is manifest-gated inside the service, so an
     * unlisted pair 404s without ever touching the filesystem.
     */
    public function icon(IconLibraryService $icons, string $set, string $slug): Response
    {
        $svg = $icons->svg($set . '/' . $slug);
        if ($svg === null) {
            return response('', 404);
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * GET /editor/icons — the icon picker's search feed. `q` substring-matches
     * slugs, `set` filters to one set, `limit`/`offset` page. Each row carries
     * the reference the icon block's attribute stores plus its asset URL.
     */
    public function iconsSearch(Request $request, IconLibraryService $icons): JsonResponse
    {
        $result = $icons->search(
            (string) $request->query('q', ''),
            $request->query('set') === null ? null : (string) $request->query('set'),
            (int) $request->integer('limit', 60),
            (int) $request->integer('offset', 0),
        );

        return response()->json([
            // `svg` is the markup itself: the picker inlines it instead of issuing one request
            // per icon (a page of 60 was 61 requests, each booting the framework — the icon grid
            // took seconds to fill). `url` stays for the canvas runtime, which fetch-injects a
            // single icon and gets a year of browser caching out of it, and as the picker's
            // fallback whenever inlineSvg() refuses a file.
            'icons' => array_map(fn (array $row) => [
                'set' => $row['set'],
                'slug' => $row['slug'],
                'reference' => $row['set'] . '/' . $row['slug'],
                'url' => route('heisenberg.editor.asset.icon', ['set' => $row['set'], 'slug' => $row['slug']]),
                'svg' => $icons->inlineSvg($row['set'] . '/' . $row['slug']),
            ], $result['icons']),
            'total' => $result['total'],
            'sets' => $icons->sets(),
        ]);
    }
}
