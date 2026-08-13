<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Models\Post;
use Heisenberg\Support\LocaleConfig;
use Heisenberg\Support\LocalizedAttributes;

/**
 * Per-locale translation COMPLETENESS for one post (docs/content-translation.md §0, single-row
 * model — this class no longer reports sibling ROWS; the split-row `source | missing | draft |
 * published | outdated` shape it used to return is gone along with `Post::sibling()`/`siblings()`).
 *
 * A logical post is one `heisenberg_posts` row; every configured locale's content lives on THAT
 * row (title_<locale>/excerpt_<locale> columns, block attributes' `_<locale>` variants). This
 * service answers one question per configured locale: "has this post actually been translated
 * into it yet?" — for the editor's Translations panel (Wave T2a) and any MCP surface that wants
 * the same signal (Wave T2b), neither of which exists yet; this class is built now because the
 * completeness computation belongs on the model/service layer, not duplicated per consumer.
 *
 * @see \Heisenberg\Support\LocalizedAttributes for the per-attribute/per-block read primitives
 *   this class composes; @see \Heisenberg\Services\BlockRegistryService::translatableAttributes()
 *   for which attributes count per block type.
 *
 * Row shape — one entry per `LocaleConfig::locales()`, in that order:
 *
 * ```
 * {
 *   locale:             string,   // e.g. "en"
 *   is_default:         bool,     // locale === LocaleConfig::default()
 *   title:               bool,    // title_<locale> has non-empty content
 *   excerpt:            bool,     // excerpt_<locale> has non-empty content
 *   blocks_translated:  int,     // count of translatable blocks with FULL content in this locale
 *   blocks_total:       int,     // count of blocks that declare at least one translatable attribute
 *   complete:           bool,    // see completeness rule below
 * }
 * ```
 *
 * A block counts toward `blocks_total`/`blocks_translated` only when it has AUTHORED content worth
 * translating — at least one of its contract's `translatable` attributes is non-empty in its bare
 * form (a separator with no tooltip text, or any block whose only translatable attribute is an
 * unused optional field, has nothing to translate and never shows up as "untranslated" noise). A
 * counted block is "translated" for a locale when every one of ITS AUTHORED translatable
 * attributes has content for that locale ({@see LocalizedAttributes::locales()} — an unused
 * optional attribute is never demanded either). The whole tree is walked (inner blocks included),
 * so a paragraph nested three columns deep still counts.
 *
 * Completeness rule: `complete` requires `title` AND every block counted in `blocks_total` to be
 * translated (`blocks_total === 0 || blocks_translated === blocks_total`). `excerpt` does NOT gate
 * completeness on its own — an excerpt is optional editorial metadata, and a post that never uses
 * excerpts in ANY locale should not read as "incomplete" forever; it only gates completeness when
 * the post has excerpt content in at least one configured locale (i.e. an excerpt was authored
 * somewhere and this locale is missing its own copy).
 */
class TranslationStatusService
{
    public function __construct(private ?BlockRegistryService $registry = null)
    {
    }

    /**
     * @return list<array{locale: string, is_default: bool, title: bool, excerpt: bool, blocks_translated: int, blocks_total: int, complete: bool}>
     */
    public function statuses(Post $post): array
    {
        $locales = LocaleConfig::locales();
        $default = LocaleConfig::default();
        // The one locale a block's BARE (unsuffixed) attribute value belongs to — see
        // LocalizedAttributes::locales()'s own docblock for why this can't be a fallback for
        // every locale. The post's own `locale` column is the right "home", not the config
        // default: a solo fr-locale row's untranslated bare content IS fr content, not en's.
        $ownLocale = (string) ($post->locale ?: $default);

        $post->loadMissing('blocks');
        $flat = $this->flattenTranslatable($post->blocks);
        $total = count($flat);

        $excerptUsedElsewhere = $this->excerptUsedIn($post, $locales);

        $rows = [];
        foreach ($locales as $locale) {
            $locale = (string) $locale;

            $title = LocalizedAttributes::hasContent($post->getAttribute("title_{$locale}"));
            $excerpt = LocalizedAttributes::hasContent($post->getAttribute("excerpt_{$locale}"));

            $translated = 0;
            foreach ($flat as $entry) {
                if (LocalizedAttributes::locales($entry['block'], $entry['attributes'], [$locale], $ownLocale) !== []) {
                    $translated++;
                }
            }

            $rows[] = [
                'locale' => $locale,
                'is_default' => $locale === $default,
                'title' => $title,
                'excerpt' => $excerpt,
                'blocks_translated' => $translated,
                'blocks_total' => $total,
                'complete' => $title
                    && ($total === 0 || $translated === $total)
                    && (! $excerptUsedElsewhere || $excerpt),
            ];
        }

        return $rows;
    }

    /** True when SOME configured locale's excerpt column has content — see this class's docblock. */
    private function excerptUsedIn(Post $post, array $locales): bool
    {
        foreach ($locales as $locale) {
            if (LocalizedAttributes::hasContent($post->getAttribute('excerpt_' . (string) $locale))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten the post's block tree (top-level rows + every nested `innerBlocks`) to the blocks
     * that actually declare translatable attributes, paired with that attribute list — computed
     * once so `statuses()` doesn't re-walk the tree or re-hit the registry per locale.
     *
     * @param iterable<\Heisenberg\Models\Block> $blockModels
     * @return list<array{block: array<string, mixed>, attributes: string[]}>
     */
    private function flattenTranslatable(iterable $blockModels): array
    {
        $flat = [];
        foreach ($blockModels as $blockModel) {
            $content = $blockModel->content ?? null;
            if (is_array($content)) {
                $this->collect($content, $flat);
            }
        }

        return $flat;
    }

    /**
     * A block counts toward `blocks_total` only when it has content actually worth translating —
     * declaring a `translatable` attribute isn't enough on its own (`titleAttr`, a tooltip, is
     * translatable on nearly every contract but empty on most instances): an unused optional
     * field would otherwise inflate the denominator with blocks that need no translation at all.
     * "Worth translating" = at least one translatable attribute has non-empty content in its bare
     * (unsuffixed) form — the same authored-content check {@see LocalizedAttributes::locales()}
     * itself uses.
     *
     * @param list<array{block: array<string, mixed>, attributes: string[]}> $flat
     */
    private function collect(array $node, array &$flat): void
    {
        if (is_string($node['name'] ?? null)) {
            $attributes = $this->registry()->translatableAttributes($node['name']);
            $blockAttributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            $hasAuthoredContent = false;
            foreach ($attributes as $key) {
                if (LocalizedAttributes::hasContent($blockAttributes[$key] ?? null)) {
                    $hasAuthoredContent = true;
                    break;
                }
            }
            if ($hasAuthoredContent) {
                $flat[] = ['block' => $node, 'attributes' => $attributes];
            }
        }

        $inner = $node['innerBlocks'] ?? null;
        if (is_array($inner)) {
            foreach ($inner as $child) {
                if (is_array($child)) {
                    $this->collect($child, $flat);
                }
            }
        }
    }

    private function registry(): BlockRegistryService
    {
        return $this->registry ??= app(BlockRegistryService::class);
    }
}
