<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Services\AiProviderRegistry;
use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\FontCatalogService;
use Heisenberg\Services\SavedThemeRepository;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Support\BlockViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class EditorController
{
    // Must stay in sync with 34-canvas.css's --hb-page-padding-x/-y fallbacks.
    private const DEFAULT_PAGE_PADDING_X = 56;
    private const DEFAULT_PAGE_PADDING_Y = 56;

    public function index(BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts): View
    {
        return view('heisenberg::editor.index', array_merge($this->sharedViewData($registry, $themes, $savedThemes, $fonts), [
            // No post yet — the first Save is what gives it an id; the Post tab's
            // taxonomy/layout controls render disabled until hb:post-id fires.
            'postId' => null,
            'postTitle' => '',
            'contentVersion' => 0,
            'initialBlocks' => [],
            'postCategoryIds' => [],
            'postTagIds' => [],
            'postPagePaddingX' => self::DEFAULT_PAGE_PADDING_X,
            'postPagePaddingY' => self::DEFAULT_PAGE_PADDING_Y,
            'postAllowComments' => true,
            'postMeta' => $this->postMeta(null),
        ]));
    }

    /**
     * Opens an EXISTING post in the editor shell; index.blade.php hydrates the canvas
     * through window.hbEditor's public API. The model class is resolved manually because
     * config('heisenberg.models.post') is host-swappable (no implicit route binding).
     * Runs the same PostPolicy view check as PostController::show() — this page ships the
     * post's full content, so an anonymous visitor must not be able to read drafts by ID.
     */
    public function show(Request $request, BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts, string $post): View
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);
        $model = $class::query()->with(['blocks', 'categories', 'tags'])->findOrFail($post);

        Gate::forUser($request->user() ?? new GuestActor())->authorize('view', $model);

        return view('heisenberg::editor.index', array_merge($this->sharedViewData($registry, $themes, $savedThemes, $fonts), [
            'postId' => $model->getKey(),
            'postTitle' => (string) ($model->title_en ?? ''),
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
            'postMeta' => $this->postMeta($model),
        ]));
    }

    /**
     * The Post tab's Summary rows, from the REAL post (2026-08-08 — the section used to
     * render hardcoded placeholder strings). Each row carries a `key` the inspector's own
     * script uses to keep the value live: status/publish/url refresh from `hb:post-saved`
     * (every successful save echoes the post payload), blocks from `hb:blocks-changed`.
     * A null model is the blank /editor document — the same defaults a first save produces.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function postMeta(?Post $model): array
    {
        $blocks = 0;
        if ($model !== null) {
            $count = function (array $list) use (&$count): int {
                $n = 0;
                foreach ($list as $block) {
                    $n += 1 + (is_array($block['innerBlocks'] ?? null) ? $count($block['innerBlocks']) : 0);
                }

                return $n;
            };
            $blocks = $count($model->blocks->map(fn ($b) => $b->content)->all());
        }

        return [
            [
                'key' => 'status',
                'label' => (string) __('heisenberg::editor.inspector.summary_status'),
                'value' => ucfirst((string) ($model?->status ?? 'draft')),
            ],
            [
                'key' => 'publish',
                'label' => (string) __('heisenberg::editor.inspector.summary_publish'),
                'value' => $model?->published_at?->format('M j, Y H:i')
                    ?? (string) __('heisenberg::editor.inspector.summary_immediately'),
            ],
            [
                'key' => 'url',
                'label' => (string) __('heisenberg::editor.inspector.summary_url'),
                'value' => ($model !== null && (string) $model->slug !== '') ? '/' . $model->slug : '—',
            ],
            [
                'key' => 'blocks',
                'label' => (string) __('heisenberg::editor.inspector.summary_blocks'),
                'value' => (string) $blocks,
            ],
        ];
    }

    /**
     * View data both `index()` and `show()` need. No registry allow-list — the client
     * ships whatever BlockRegistryService discovers under resources/blocks.
     */
    private function sharedViewData(BlockRegistryService $registry, ThemeRepository $themes, SavedThemeRepository $savedThemes, FontCatalogService $fonts): array
    {
        return [
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
            'postDiscussionUrlTemplate' => route('heisenberg.editor.posts.store') . '/__ID__/discussion',
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

    public function css(): Response
    {
        $base = dirname(__DIR__, 3) . '/resources/css/';
        $files = [$base . 'tokens.css', ...(glob($base . 'editor/*.css') ?: [])];
        $files = array_values(array_filter($files, 'is_file'));
        $editorCss = '';

        foreach ($files as $file) {
            $editorCss .= (string) file_get_contents($file) . "\n";
        }

        // no-store while the editor is under active development; revisit for production.
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
     * The generated supports-capabilities stylesheet — a no-op for any block root
     * that doesn't carry hb-supports.
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

    /**
     * One block-library icon (see IconLibraryService — the imported VvvebJs
     * collection). The set/slug pair is manifest-gated inside the service, so an
     * unlisted pair 404s without ever touching the filesystem.
     */
    public function icon(\Heisenberg\Services\IconLibraryService $icons, string $set, string $slug): Response
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
    public function iconsSearch(Request $request, \Heisenberg\Services\IconLibraryService $icons): \Illuminate\Http\JsonResponse
    {
        $result = $icons->search(
            (string) $request->query('q', ''),
            $request->query('set') === null ? null : (string) $request->query('set'),
            (int) $request->integer('limit', 60),
            (int) $request->integer('offset', 0),
        );

        return response()->json([
            'icons' => array_map(fn (array $row) => [
                'set' => $row['set'],
                'slug' => $row['slug'],
                'reference' => $row['set'] . '/' . $row['slug'],
                'url' => route('heisenberg.editor.asset.icon', ['set' => $row['set'], 'slug' => $row['slug']]),
            ], $result['icons']),
            'total' => $result['total'],
            'sets' => $icons->sets(),
        ]);
    }
}
