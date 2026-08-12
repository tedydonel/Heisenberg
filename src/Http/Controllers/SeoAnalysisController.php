<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Services\SeoAnalyzer;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /editor/posts/{post}/seo/analyze` (docs/seo-system.md §4, Wave S2b) — runs
 * {@see SeoAnalyzer} against a post and returns `{score, rating, checks[]}`. The SEO/Social
 * panel (a later wave) calls this debounced on every field edit, sending the panel's UNSAVED
 * values as `o_*` query overrides so a score reflects what the user is typing, not what's
 * persisted.
 *
 * Same authorization posture as {@see PreviewController::showPost()}: `GuestActor` stand-in +
 * `PostPolicy::view` — this is a READ that exposes a post's title/slug/block text back through
 * the checklist, so a draft must stay just as invisible here as it is on the preview page (the
 * `LocalDevRoleGate` wrapped inside `PostPolicy` still applies in local dev, same as everywhere
 * else this pattern is used).
 *
 * `locale` defaults to the post's OWN locale (split-row model, docs/content-translation.md §2) —
 * not the app/request locale — because the panel always analyzes the row it has open. An
 * explicit `?locale=` outside `LocaleConfig::locales()` is a 422, matching the validation posture
 * `LocaleController`/`McpToolRegistry` already use.
 */
class SeoAnalysisController
{
    /** query param => SeoAnalyzer::analyze() override key. */
    private const OVERRIDE_PARAMS = [
        'o_meta_title' => 'meta_title',
        'o_meta_description' => 'meta_description',
        'o_focus_keyphrase' => 'focus_keyphrase',
        'o_slug' => 'slug',
        'o_canonical' => 'canonical',
        'o_robots' => 'robots',
        'o_og_image' => 'og_image',
    ];

    public function __construct(private SeoAnalyzer $analyzer)
    {
    }

    public function show(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $locale = (string) $request->query('locale', $model->locale ?: LocaleConfig::default());
        if (! LocaleConfig::isValid($locale)) {
            return response()->json(['message' => 'Unsupported locale.'], 422);
        }

        $result = $this->analyzer->analyze($model, $locale, $this->overrides($request));

        return response()->json($result);
    }

    /** @return array<string,string> */
    private function overrides(Request $request): array
    {
        $overrides = [];
        foreach (self::OVERRIDE_PARAMS as $param => $key) {
            $value = $request->query($param);
            if (is_string($value)) {
                $overrides[$key] = $value;
            }
        }

        return $overrides;
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    private function findPostOrFail(string $id): Post
    {
        $class = (string) config('heisenberg.models.post', Post::class);

        return $class::query()->findOrFail($id);
    }
}
