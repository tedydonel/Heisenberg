<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /heisenberg/posts/{post}/translations (routes/translations.php, `heisenberg.translations.index`)
 * — the public, read-only API a host uses to build its own language-switcher UI
 * (docs/content-translation.md §0/§7). Rewritten for the single-row translation model: a
 * "translation" is no longer a sibling row (`Post::siblings()` is gone) — it is that SAME post's
 * content in another locale, living as `_<locale>` attribute variants on this one row. This
 * endpoint now answers "which locales does this ONE post have content for", not "which sibling
 * rows exist".
 *
 * SHARED-SLUG INVARIANT (now trivially true rather than an invariant to enforce): every locale
 * IS the same row, so there is exactly one slug, returned once at the TOP level. This package
 * deliberately emits NO URLs here — it does not own the host's URL shape (a host might route
 * `/{locale}/blog/{slug}`, `/{locale}/{slug}`, a locale-only-for-non-default scheme, or something
 * else entirely); a host combines a `locale` from `translations` with the shared `slug` and its
 * own route to build a link.
 *
 * AUTHORIZATION (same posture {@see CommentController}'s public thread endpoint uses): actor =
 * the authenticated user, or a {@see GuestActor} stand-in — never a bare null. The requested post
 * is authorized via `Gate::authorize('view', ...)`, so an unauthorized request for a draft post
 * 403s outright (mirrors CommentController's own precedent); an unknown post id 404s via
 * `findOrFail()`. One gate check now covers the whole response — there are no other rows left to
 * filter independently.
 *
 * Response shape: `{default_locale, slug, translations: [{locale, complete, current}]}` —
 * `complete` is that locale's translation completeness ({@see TranslationStatusService}); `slug`
 * is top-level per the shared-slug note above; `current` marks whichever locale the caller asked
 * about (`?locale=`, defaulting to the post's own locale) — meaningful now that "the locale being
 * viewed" is a property of the REQUEST, not of which row was loaded.
 */
class PostTranslationsApiController
{
    public function __construct(private TranslationStatusService $translationStatus)
    {
    }

    public function index(Request $request, string $post): JsonResponse
    {
        $model = $this->findOrFail($post);
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('view', $model);

        $requestedLocale = trim((string) $request->query('locale', ''));
        if ($requestedLocale === '' || ! LocaleConfig::isValid($requestedLocale)) {
            $requestedLocale = (string) ($model->locale ?: LocaleConfig::default());
        }

        $translations = array_map(
            static fn (array $row): array => [
                'locale' => $row['locale'],
                'complete' => $row['complete'],
                'current' => $row['locale'] === $requestedLocale,
            ],
            $this->translationStatus->statuses($model),
        );

        return response()->json([
            'default_locale' => LocaleConfig::default(),
            // Top-level, not per-row — it's the ONE slug the whole post has.
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
