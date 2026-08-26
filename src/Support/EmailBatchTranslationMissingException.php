<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * One or more requested locales do not have complete persisted translation content for
 * the supplied email post (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 6). Heisenberg's single-row Post model stores every locale on the same
 * row (`title_<locale>` and localized block attributes); one export call may
 * therefore generate the full requested locale matrix.
 *
 * The exporter raises this BEFORE rendering so an admin never sees an
 * English body mislabeled as French inside the zip. The exception carries
 * `$locales` (the untranslated locales the admin asked for) and
 * `$postLocale` (the row's own locale — the one the post WAS persisted
 * for) so the API can surface a controlled 422 with both lists and
 * NOTHING ELSE (no values, no formatter internals, no recipient map).
 *
 * Separate class — NOT a {@see EmailVariableResolutionException} — because
 * the failure is structural (the persisted row itself doesn't carry the
 * requested translation), not a per-token resolution failure: lumping it
 * under the interpolator's exception would conflate two very different
 * reasons a batch can fail.
 */
final class EmailBatchTranslationMissingException extends \RuntimeException
{
    /**
     * @param list<string> $locales the untranslated locales the admin requested
     */
    public function __construct(
        public readonly array $locales,
        public readonly string $postLocale,
    ) {
        parent::__construct(sprintf(
            'Cannot export email for the requested locale(s) (%s): this post is missing complete persisted content for those locales (home locale: "%s").',
            implode(', ', $locales),
            $postLocale,
        ));
    }
}
