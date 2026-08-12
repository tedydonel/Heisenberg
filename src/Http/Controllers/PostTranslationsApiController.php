<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /heisenberg/posts/{post}/translations (routes/translations.php, `heisenberg.translations.index`)
 * — the public, read-only API a host uses to build its own language-switcher UI
 * (docs/content-translation.md §7). Distinct from {@see PostTranslationController}, the /editor
 * MUTATION endpoint that CREATES/overwrites a sibling row — this controller only ever reads.
 *
 * SHARED-SLUG INVARIANT: a translation group presents as ONE post with ONE slug (locale comes
 * from the HOST's own URL prefix, never the slug text — see {@see \Heisenberg\Models\Post}'s
 * `updating` hook and {@see PostController::applySlug()}), so the slug is returned once, at the
 * TOP level, not per row. This package deliberately emits NO URLs here — it does not own the
 * host's URL shape (a host might route `/{locale}/blog/{slug}`, `/{locale}/{slug}`, a
 * locale-only-for-non-default scheme, or something else entirely); a host combines each row's
 * `locale` with the shared `slug` and its own route to build a link.
 *
 * AUTHORIZATION, per row (same posture {@see CommentController}'s public thread endpoint uses):
 * actor = the authenticated user, or a {@see GuestActor} stand-in — never a bare null. The
 * REQUESTED post is authorized first via `Gate::authorize('view', ...)`, so an unauthorized
 * request for a draft post 403s outright (mirrors CommentController's own precedent) rather than
 * silently returning an empty list; an unknown post id 404s via `findOrFail()`. Every OTHER
 * group member is then filtered independently through the SAME `PostPolicy::view()` gate — a
 * guest sees only `published` siblings, while the post's own author or an `authors`/`admins`-tier
 * actor sees drafts too. This means the response can legitimately omit siblings a guest may not
 * see, without ever 403ing the whole request over them.
 */
class PostTranslationsApiController
{
    public function index(Request $request, string $post): JsonResponse
    {
        $model = $this->findOrFail($post);
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('view', $model);

        $members = $model->siblings()->push($model);

        $translations = $members
            ->filter(fn (Post $candidate) => Gate::forUser($actor)->allows('view', $candidate))
            ->sortBy('locale')
            ->values()
            ->map(fn (Post $candidate) => [
                'locale' => $candidate->locale,
                'post_id' => $candidate->getKey(),
                'status' => $candidate->status,
                'current' => $candidate->getKey() === $model->getKey(),
            ])
            ->all();

        return response()->json([
            'default_locale' => LocaleConfig::default(),
            // Top-level, not per-row — it's the ONE slug the whole group shares.
            'slug' => $model->slug,
            'translations' => $translations,
        ]);
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every other
     * public/controller in this package uses (see CommentController's own docblock on this).
     */
    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Post> */
    private function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }

    private function findOrFail(string $id): Post
    {
        $class = $this->postClass();

        return $class::query()->findOrFail($id);
    }
}
