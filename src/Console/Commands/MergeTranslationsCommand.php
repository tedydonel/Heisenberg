<?php

declare(strict_types=1);

namespace Heisenberg\Console\Commands;

use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Support\LocaleConfig;
use Heisenberg\Support\LocalizedAttributes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan heisenberg:merge-translations` — the one-time migration from the split-row
 * translation model to the single-row model (docs/content-translation.md §0). Every
 * `translation_group_id` with more than one row is a leftover sibling group; this command folds
 * each group back into ONE surviving row and removes the rest.
 *
 * **Survivor selection**: the row whose `locale` equals `config('heisenberg.default_locale')`;
 * if no row in the group has that locale, the oldest row (`created_at` ascending, `id` tie-break).
 *
 * **What gets folded into the survivor, per non-survivor row ("the sibling")**:
 *  - `title_<sibling's locale>` / `excerpt_<sibling's locale>` columns — copied from the
 *    sibling's own columns.
 *  - Every translatable block attribute ({@see BlockRegistryService::translatableAttributes()}),
 *    copied into the survivor's matching block's `<attribute>_<sibling's locale>` variant
 *    ({@see LocalizedAttributes::write()}), matched BY POSITION: block N of the sibling's tree
 *    folds into block N of the survivor's tree, recursing into `innerBlocks` the same way.
 *  - SEO (`SeoMeta`'s `meta_title`/`meta_description`/`og_title`/`og_description`/
 *    `focus_keyphrase`, each already `_en`/`_fr` columns): the sibling's own-locale columns fold
 *    into the survivor's `SeoMeta` row (created via `updateOrCreate` if the survivor has none
 *    yet), validated with the SAME refuse-to-guess rule as title/excerpt/blocks below. The
 *    row-level fields (`og_image`, `canonical_url`, `robots`, `schema_type`, `schema_data`,
 *    `in_sitemap`) are NOT folded — they are technical/single-value, not per-locale text, and the
 *    survivor's own value (or lack of one) is left exactly as it is.
 *  - Comments: REASSIGNED (`post_id` UPDATE) onto the survivor, never deleted — a discussion
 *    thread is about the article, not about one translation row of it (docs/content-translation.md
 *    §0), so losing it on merge would be a silent, unrecoverable content loss.
 *
 * **What is deliberately NOT folded, and is lost when the sibling row is removed**:
 *  - TOC entries ({@see \Heisenberg\Models\TocEntry}) — `label` has no `_<locale>` column yet
 *    (unlike title/excerpt/block attributes/SEO), so there is nowhere honest to fold a sibling's
 *    translated labels TO. The survivor keeps its own TOC untouched; the sibling's TOC rows are
 *    removed with it (`toc_entries.post_id` cascades on delete). This is a real, documented gap —
 *    bilingual TOC labels need their own migration in a later wave; see docs/content-translation.md.
 *  - Revisions — a point-in-time snapshot of the SIBLING row specifically; cascade-deleted with
 *    it (same FK `Post::delete()`'s own docblock describes). The survivor's own revision history
 *    is untouched.
 *  - Category/tag pivot rows on the sibling (cascade-deleted with it) — the survivor's own
 *    taxonomy is left as-is, never unioned with the sibling's.
 *  - `page_padding_x`/`page_padding_y`/`allow_comments`/`featured_image_id` — the survivor's own
 *    values win untouched (these were already meant to be group-wide settings kept in sync by the
 *    split-row workflow, not per-locale text).
 *
 * **Refuse to guess** (never silently overwrite or drop authored text): before anything is
 * written, EVERY fold operation for EVERY sibling in the group is validated. A single conflict —
 * a block-tree shape mismatch (different block count/name at any position, at any depth) OR a
 * target field (title/excerpt/block attribute/SEO column) on the survivor already holding
 * DIFFERENT non-empty content — skips the ENTIRE GROUP. Nothing partial is ever written for a
 * group; the group is reported with every reason found, so a human can resolve it by hand.
 *
 * The default run wraps each group's merge in its own transaction and then removes the merged-away
 * rows via `forceDelete()` (a real SQL DELETE — their blocks/revisions/toc_entries/category+tag
 * pivot rows cascade at the DB level, same FKs `Post::delete()`'s own docblock describes; comments
 * and SEO were already handled above, so the cascade never touches comments and the sibling's own
 * now-redundant `SeoMeta` row is deleted explicitly since `SeoMeta` has no cascading FK). `--dry-run`
 * computes and reports the identical plan without writing anything.
 */
class MergeTranslationsCommand extends Command
{
    protected $signature = 'heisenberg:merge-translations {--dry-run : Report what would happen; write nothing}';

    protected $description = 'Merge leftover split-row translation groups into the single-row model (docs/content-translation.md §0).';

    public function handle(BlockRegistryService $registry): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $postClass = $this->postClass();
        $seoClass = $this->seoClass();
        $commentClass = $this->commentClass();

        $groupIds = $postClass::query()
            ->whereNotNull('translation_group_id')
            ->select('translation_group_id')
            ->groupBy('translation_group_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('translation_group_id');

        if ($groupIds->isEmpty()) {
            $this->info('No split-row translation groups found — nothing to merge.');

            return self::SUCCESS;
        }

        $mergedGroups = [];
        $skippedGroups = [];

        foreach ($groupIds as $groupId) {
            /** @var Collection<int, Post> $rows */
            $rows = $postClass::query()
                ->where('translation_group_id', $groupId)
                ->with('blocks')
                ->orderBy('created_at')->orderBy('id')
                ->get();

            if ($rows->count() < 2) {
                continue; // raced/soft-deleted down to one row between the count and the fetch
            }

            [$survivor, $siblings] = $this->pickSurvivor($rows);
            $conflicts = [];
            $plan = $this->buildPlan($survivor, $siblings, $registry, $seoClass, $conflicts);

            if ($conflicts !== []) {
                $skippedGroups[] = [
                    'group' => (string) $groupId,
                    'survivor_id' => $survivor->getKey(),
                    'reasons' => $conflicts,
                ];
                continue;
            }

            $summary = [
                'group' => (string) $groupId,
                'survivor_id' => $survivor->getKey(),
                'survivor_locale' => (string) $survivor->locale,
                'merged_away' => $siblings->map(fn (Post $s) => ['id' => $s->getKey(), 'locale' => (string) $s->locale])->all(),
            ];

            if ($dryRun) {
                $mergedGroups[] = $summary;
                continue;
            }

            DB::transaction(function () use ($plan, $survivor, $siblings, $seoClass, $commentClass): void {
                $this->applyPlan($plan, $survivor, $siblings, $seoClass, $commentClass);
            });

            $mergedGroups[] = $summary;
        }

        $this->report($mergedGroups, $skippedGroups, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param Collection<int, Post> $rows
     * @return array{0: Post, 1: Collection<int, Post>}
     */
    private function pickSurvivor(Collection $rows): array
    {
        $default = LocaleConfig::default();
        $survivor = $rows->firstWhere('locale', $default) ?? $rows->first();

        $siblings = $rows->reject(fn (Post $row) => $row->is($survivor))->values();

        return [$survivor, $siblings];
    }

    /**
     * Validate every fold for every sibling and build the in-memory write plan. Pushes a
     * human-readable reason onto `$conflicts` (by reference) for each problem found; the caller
     * skips the whole group when `$conflicts` is non-empty, so this method keeps building (to
     * surface every reason at once) rather than stopping at the first one.
     *
     * @param Collection<int, Post> $siblings
     * @param class-string<SeoMeta> $seoClass
     * @param string[] $conflicts
     * @return array{title: array<string, string>, excerpt: array<string, string>, seo: array<string, string>, blocks: array<int, array<string, mixed>>}
     */
    private function buildPlan(Post $survivor, Collection $siblings, BlockRegistryService $registry, string $seoClass, array &$conflicts): array
    {
        $titleWrites = [];
        $excerptWrites = [];
        $seoWrites = [];
        // blocks: survivor block index => merged content array (built incrementally across siblings)
        $blockContent = $survivor->blocks->sortBy('order')->values()->map(fn ($b) => $b->content)->all();

        $survivorSeo = $seoClass::query()
            ->where('able_type', $survivor->getMorphClass())
            ->where('able_id', $survivor->getKey())
            ->first();

        foreach ($siblings as $sibling) {
            $locale = (string) $sibling->locale;
            $prefix = "group {$survivor->translation_group_id} (sibling {$sibling->getKey()}/{$locale})";

            $this->planColumn($survivor, $sibling, 'title', $locale, $prefix, $titleWrites, $conflicts);
            $this->planColumn($survivor, $sibling, 'excerpt', $locale, $prefix, $excerptWrites, $conflicts);
            $this->planSeo($survivorSeo, $sibling, $locale, $prefix, $seoWrites, $conflicts);

            $siblingBlocks = $sibling->blocks->sortBy('order')->values()->map(fn ($b) => $b->content)->all();

            if (count($siblingBlocks) !== count($blockContent)) {
                $conflicts[] = "{$prefix}: block count differs (survivor " . count($blockContent) . ', sibling ' . count($siblingBlocks) . ')';
                continue;
            }

            foreach ($blockContent as $index => $survivorNode) {
                $blockContent[$index] = $this->mergeNode(
                    is_array($survivorNode) ? $survivorNode : [],
                    is_array($siblingBlocks[$index]) ? $siblingBlocks[$index] : [],
                    $locale,
                    $registry,
                    $conflicts,
                    "{$prefix}: block[{$index}]",
                );
            }
        }

        return ['title' => $titleWrites, 'excerpt' => $excerptWrites, 'seo' => $seoWrites, 'blocks' => $blockContent];
    }

    /**
     * @param array<string, string> $writes locale => value, accumulated across siblings
     * @param string[] $conflicts
     */
    private function planColumn(Post $survivor, Post $sibling, string $column, string $locale, string $prefix, array &$writes, array &$conflicts): void
    {
        $target = "{$column}_{$locale}";
        $siblingValue = $sibling->getAttribute($target);
        if (! LocalizedAttributes::hasContent($siblingValue)) {
            return; // nothing authored on the sibling for its own locale — nothing to fold
        }

        $existing = $writes[$locale] ?? $survivor->getAttribute($target);
        if (LocalizedAttributes::hasContent($existing) && $existing !== $siblingValue) {
            $conflicts[] = "{$prefix}: {$target} already has different content on the survivor";

            return;
        }

        $writes[$locale] = $siblingValue;
    }

    /**
     * Same refuse-to-guess rule as {@see self::planColumn()}, applied to `SeoMeta`'s five
     * locale-suffixed text fields. `$survivorSeo` is read ONCE by the caller (not per sibling) —
     * safe because this method only stages writes into `$writes`, never persists.
     *
     * @param array<string, string> $writes column => value, accumulated across siblings
     * @param string[] $conflicts
     */
    private function planSeo(?SeoMeta $survivorSeo, Post $sibling, string $locale, string $prefix, array &$writes, array &$conflicts): void
    {
        $siblingSeo = $sibling->seoMeta;
        if ($siblingSeo === null) {
            return;
        }

        foreach (['meta_title', 'meta_description', 'og_title', 'og_description', 'focus_keyphrase'] as $field) {
            $column = "{$field}_{$locale}";
            $value = $siblingSeo->getAttribute($column);
            if (! LocalizedAttributes::hasContent($value)) {
                continue;
            }

            $existing = $writes[$column] ?? $survivorSeo?->getAttribute($column);
            if (LocalizedAttributes::hasContent($existing) && $existing !== $value) {
                $conflicts[] = "{$prefix}: SEO {$column} already has different content on the survivor";

                continue;
            }

            $writes[$column] = $value;
        }
    }

    /**
     * Recursively fold `$siblingNode`'s translatable attributes (for `$siblingLocale`) into
     * `$survivorNode`, returning the (possibly modified) survivor node. Appends to `$conflicts`
     * on any shape mismatch or populated-with-different-content collision; the returned tree is
     * discarded by the caller whenever `$conflicts` ends up non-empty for the group.
     *
     * @param string[] $conflicts
     * @return array<string, mixed>
     */
    private function mergeNode(array $survivorNode, array $siblingNode, string $siblingLocale, BlockRegistryService $registry, array &$conflicts, string $path): array
    {
        $survivorName = $survivorNode['name'] ?? null;
        $siblingName = $siblingNode['name'] ?? null;

        if (! is_string($survivorName) || $survivorName !== $siblingName) {
            $conflicts[] = "{$path}: block name mismatch ('" . $this->str($survivorName) . "' vs '" . $this->str($siblingName) . "')";

            return $survivorNode;
        }

        $keys = $registry->translatableAttributes($survivorName);
        $survivorAttrs = is_array($survivorNode['attributes'] ?? null) ? $survivorNode['attributes'] : [];
        $siblingAttrs = is_array($siblingNode['attributes'] ?? null) ? $siblingNode['attributes'] : [];

        foreach ($keys as $key) {
            // The sibling's OWN bare value legitimately represents ITS OWN locale (a split-row
            // sibling never had suffixed variants — its bare content IS its one locale's text),
            // so read()'s fallback-to-bare is correct here.
            $siblingValue = LocalizedAttributes::read($siblingAttrs, $key, $siblingLocale);
            if (! LocalizedAttributes::hasContent($siblingValue)) {
                continue;
            }

            // The SURVIVOR's bare value represents the SURVIVOR's own locale, not the sibling's
            // target locale — read()'s fallback would wrongly compare the sibling's fr text
            // against the survivor's own en text as if it were an existing fr conflict. Only an
            // EXPLICIT `<key>_<siblingLocale>` variant counts as "already translated" here.
            $suffixedKey = $key . '_' . $siblingLocale;
            $existing = array_key_exists($suffixedKey, $survivorAttrs) ? $survivorAttrs[$suffixedKey] : null;
            if (LocalizedAttributes::hasContent($existing) && $existing !== $siblingValue) {
                $conflicts[] = "{$path}: attribute '{$key}' already has different {$siblingLocale} content on the survivor";

                continue;
            }

            $survivorAttrs = LocalizedAttributes::write($survivorAttrs, $key, $siblingLocale, $siblingValue);
        }
        $survivorNode['attributes'] = $survivorAttrs;

        $survivorInner = is_array($survivorNode['innerBlocks'] ?? null) ? $survivorNode['innerBlocks'] : [];
        $siblingInner = is_array($siblingNode['innerBlocks'] ?? null) ? $siblingNode['innerBlocks'] : [];

        if (count($survivorInner) !== count($siblingInner)) {
            $conflicts[] = "{$path}: innerBlocks count differs (survivor " . count($survivorInner) . ', sibling ' . count($siblingInner) . ')';

            return $survivorNode;
        }

        foreach ($survivorInner as $index => $child) {
            $survivorInner[$index] = $this->mergeNode(
                is_array($child) ? $child : [],
                is_array($siblingInner[$index]) ? $siblingInner[$index] : [],
                $siblingLocale,
                $registry,
                $conflicts,
                "{$path}>{$index}",
            );
        }
        $survivorNode['innerBlocks'] = $survivorInner;

        return $survivorNode;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : 'null';
    }

    /**
     * @param array{title: array<string, string>, excerpt: array<string, string>, seo: array<string, string>, blocks: array<int, array<string, mixed>>} $plan
     * @param Collection<int, Post> $siblings
     * @param class-string<SeoMeta> $seoClass
     * @param class-string<Comment> $commentClass
     */
    private function applyPlan(array $plan, Post $survivor, Collection $siblings, string $seoClass, string $commentClass): void
    {
        foreach ($plan['title'] as $locale => $value) {
            $survivor->setAttribute("title_{$locale}", $value);
        }
        foreach ($plan['excerpt'] as $locale => $value) {
            $survivor->setAttribute("excerpt_{$locale}", $value);
        }
        if ($plan['title'] !== [] || $plan['excerpt'] !== []) {
            $survivor->save();
        }

        if ($plan['seo'] !== []) {
            $seoClass::query()->updateOrCreate(
                ['able_type' => $survivor->getMorphClass(), 'able_id' => $survivor->getKey()],
                $plan['seo'],
            );
        }

        $survivorBlocks = $survivor->blocks->sortBy('order')->values();
        $blocksChanged = false;
        foreach ($plan['blocks'] as $index => $content) {
            $block = $survivorBlocks->get($index);
            if ($block !== null && $block->content !== $content) {
                $block->content = $content;
                $block->save();
                $blocksChanged = true;
            }
        }
        if ($blocksChanged) {
            $survivor->bumpContentVersion();
        }

        foreach ($siblings as $sibling) {
            // Comments attach to the ARTICLE, not one translation row of it (docs/content-
            // translation.md §0) — reassigned, never dropped, before the sibling row is removed.
            $commentClass::query()->where('post_id', $sibling->getKey())->update(['post_id' => $survivor->getKey()]);

            // SeoMeta has no cascading FK (polymorphic, no real foreign key constraint) — the
            // sibling's own row is now redundant (its usable fields were already folded above)
            // and would otherwise leak as an orphan pointing at a row that's about to vanish.
            $sibling->seoMeta?->delete();

            // TOC entries and category/tag pivot rows ARE cascade-deleted by the FK when the
            // sibling row is force-deleted below — see this class's own docblock for why TOC
            // labels specifically are a documented gap rather than folded.
            $sibling->forceDelete();
        }
    }

    private function report(array $merged, array $skipped, bool $dryRun): void
    {
        $verb = $dryRun ? 'would merge' : 'merged';

        $this->info(sprintf('%d group(s) %s, %d group(s) skipped.', count($merged), $verb, count($skipped)));

        foreach ($merged as $entry) {
            $away = collect($entry['merged_away'])->map(fn ($s) => "{$s['id']} ({$s['locale']})")->implode(', ');
            $this->line(sprintf(
                ' - group %s: survivor #%d (%s) <- %s',
                $entry['group'],
                $entry['survivor_id'],
                $entry['survivor_locale'] ?? '',
                $away === '' ? '(none)' : $away,
            ));
        }

        foreach ($skipped as $entry) {
            $this->warn(sprintf(' - group %s SKIPPED (survivor #%d):', $entry['group'], $entry['survivor_id']));
            foreach ($entry['reasons'] as $reason) {
                $this->line("     - {$reason}");
            }
        }

        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        }
    }

    /** @return class-string<Post> */
    private function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }

    /** @return class-string<SeoMeta> */
    private function seoClass(): string
    {
        return (string) config('heisenberg.models.seo_meta', SeoMeta::class);
    }

    /** @return class-string<Comment> */
    private function commentClass(): string
    {
        return (string) config('heisenberg.models.comment', Comment::class);
    }
}
