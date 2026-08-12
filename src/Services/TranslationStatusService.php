<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Models\Post;
use Heisenberg\Support\LocaleConfig;

/**
 * Per-locale translation status for a post's group (docs/content-translation.md §2, §9). Powers
 * the editor's Translations section (Wave T2a) and `get_post`'s `translations` block (Wave T2b);
 * built now (Wave T1) because both need the same status computation and it belongs on the model
 * layer, not duplicated in a controller and an MCP tool.
 *
 * Plain class, no constructor dependencies (locales come from `LocaleConfig::locales()` at call
 * time, same as `LocaleController`/`EditorLocaleMiddleware` after this wave) — resolved ad
 * hoc (`new TranslationStatusService()` or `app(TranslationStatusService::class)`, both work via
 * Laravel's zero-arg auto-resolution) rather than bound as a container singleton. Compare
 * `PostTemplateRegistryService`, which IS bound explicitly because it has real constructor deps
 * (a validator instance, a config-resolved root path) that are worth constructing once.
 */
class TranslationStatusService
{
    /**
     * One entry per configured locale (`config('heisenberg.locales')`, §3), in that order.
     *
     * - The row's own locale reports `source` — it does not judge itself outdated.
     * - A locale with no sibling row reports `missing` (`post_id: null`).
     * - A sibling that exists reports `outdated` (per `Post::isTranslationOutdated()`) regardless
     *   of its own lifecycle status — a published-but-stale translation is still `outdated`, not
     *   `published`, because that's the more actionable signal for an editor deciding whether to
     *   re-translate.
     * - Otherwise the sibling's own lifecycle status is bucketed: `published` when
     *   `status === 'published'`, `draft` for anything else (pending_review, scheduled, archived,
     *   draft itself) — the Translations section only needs "is it live" vs. "still being worked
     *   on", not the full lifecycle enum.
     *
     * @return array<int, array{locale: string, status: string, post_id: int|null}>
     */
    public function statuses(Post $post): array
    {
        $locales = LocaleConfig::locales();
        $ownLocale = (string) ($post->locale ?: LocaleConfig::default());

        $siblings = $post->siblings()->keyBy('locale');

        $rows = [];

        foreach ($locales as $locale) {
            $locale = (string) $locale;

            if ($locale === $ownLocale) {
                $rows[] = ['locale' => $locale, 'status' => 'source', 'post_id' => $post->getKey()];
                continue;
            }

            /** @var Post|null $sibling */
            $sibling = $siblings->get($locale);

            if ($sibling === null) {
                $rows[] = ['locale' => $locale, 'status' => 'missing', 'post_id' => null];
                continue;
            }

            if ($sibling->isTranslationOutdated()) {
                $rows[] = ['locale' => $locale, 'status' => 'outdated', 'post_id' => $sibling->getKey()];
                continue;
            }

            $status = $sibling->status === 'published' ? 'published' : 'draft';
            $rows[] = ['locale' => $locale, 'status' => $status, 'post_id' => $sibling->getKey()];
        }

        return $rows;
    }
}
