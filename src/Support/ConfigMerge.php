<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * Deep-merges the package's shipped config defaults into a host's config, with
 * the host's own values ALWAYS winning.
 *
 * Why this exists: `ServiceProvider::mergeConfigFrom()` does a SHALLOW
 * `array_merge()` — for any top-level key the host's published
 * `config/heisenberg.php` defines at all, the host's value wins WHOLESALE, and
 * any nested key the package added to that same top-level section later never
 * reaches a host that published before the addition existed. This bit a real
 * install three times: `post_template.comments_provider` /
 * `.seo_meta_provider` stuck on stale defaults after the package flipped its
 * own defaults to the native adapters, `roles.comments.moderate` simply didn't
 * exist so moderation authorized nobody, and `lifecycle.transitions.draft`
 * kept an old edge list with no `published` target — nobody, of any role,
 * could publish a post.
 *
 * The rule, walking the package defaults against the host's config:
 *  - a key ABSENT from the host: add the package's value (this is what fixes
 *    the three bugs above — new provider defaults, new abilities, new lifecycle
 *    edges all reach an old published config automatically).
 *  - a key PRESENT in the host, where BOTH the package's and the host's value
 *    are ASSOCIATIVE arrays: recurse, so a nested addition still surfaces even
 *    when the host customized a sibling in the same section.
 *  - a key PRESENT in the host in every other case (scalar, null, or a LIST —
 *    sequential integer keys from zero) — the host's value wins AS-IS and is
 *    never touched, never merged element-wise, never appended to.
 *
 * The list rule is deliberate and has a sharp edge worth stating plainly: a
 * LIST is treated as one atomic value, not a collection to merge entries into.
 * `lifecycle.transitions.draft` is a list of allowed target statuses — if the
 * package changes what that list CONTAINS (not just adds a new key beside it),
 * a host that already published the config keeps its OLD list contents
 * forever; this merge cannot and does not fix that. It fixes MISSING keys, not
 * stale CONTENT inside a list a host already owns. A host upgrading past a
 * change like that still has to edit that one list by hand — `php artisan
 * heisenberg:config-diff` is the tool that surfaces exactly where.
 *
 * Pure array logic, no framework coupling — trivially unit-testable, and used
 * by both HeisenbergServiceProvider::register() and the config-diff command.
 */
final class ConfigMerge
{
    /**
     * @param array<array-key, mixed> $defaults the package's shipped config
     * @param array<array-key, mixed> $host     the host's current config (published + already-set values)
     * @return array<array-key, mixed> the host's config, with any package key it was missing filled in
     */
    public static function merge(array $defaults, array $host): array
    {
        $result = $host;

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $result)) {
                $result[$key] = $value;
                continue;
            }

            $hostValue = $result[$key];

            if (is_array($value) && is_array($hostValue) && self::isAssociative($value) && self::isAssociative($hostValue)) {
                $result[$key] = self::merge($value, $hostValue);
                continue;
            }

            // Host wins as-is: scalar, null, a list (atomic — see class docblock),
            // or an associative/list type mismatch between package and host.
        }

        return $result;
    }

    /**
     * True for an associative array; false for a LIST (sequential integer keys
     * from zero — PHP's `array_is_list()` rule, under which an EMPTY array also
     * counts as a list) — the boundary that keeps `locales`, `middleware.editor`,
     * and `lifecycle.transitions.draft` atomic instead of being merged
     * element-wise. An empty array falling on the "list" side is harmless
     * either way: there is nothing inside it to merge from or into.
     *
     * Public: `heisenberg:config-diff` (Heisenberg\Console\Commands\ConfigDiffCommand)
     * walks defaults-vs-effective-config the same way this class merges them,
     * and shares this exact rule so "recurse deeper" vs "compare as one value"
     * never disagrees between the merge and the tool that audits it.
     */
    public static function isAssociative(array $array): bool
    {
        return ! array_is_list($array);
    }
}
