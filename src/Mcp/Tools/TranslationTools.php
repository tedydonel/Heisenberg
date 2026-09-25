<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Console\Commands\MergeTranslationsCommand;
use Heisenberg\Mcp\Support\ContentBlockPipeline;
use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\TocService;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Support\LocaleConfig;
use Heisenberg\Support\LocalizedAttributes;
use Illuminate\Support\Facades\DB;

/**
 * `create_translation` — the single-row translation model (docs/content-translation.md
 * §0, §6, rewritten for the single-row model: this tool keeps its Wave-1 name but no
 * longer creates a sibling row — there is nothing left to sibling). `title`/`excerpt`
 * write straight to `title_<locale>`/`excerpt_<locale>` on the SAME row. `code`, if
 * supplied, is validated through the exact same pipeline `update_post` uses (see
 * {@see ContentBlockPipeline::validatedContentBlocks()}), then folded into the post's
 * EXISTING stored blocks' translatable attributes as `_<locale>` variants, matched BY
 * POSITION ({@see self::foldTranslatedBlocks()}) — the stored block TREE itself is never
 * replaced, because structure is shared across every locale now.
 *
 * Never touches lifecycle status, slug, or any other post setting — it edits translated
 * TEXT fields only, on a post that already exists.
 *
 * Surface posture (owner decision, this wave): available on BOTH surfaces with no
 * draft-only restriction, unlike `create_post`/`update_post`. Those hold a draft-only
 * posture on the external surface because an unattended agent could otherwise ship
 * unreviewed content live. This tool cannot do that — it never creates a post and never
 * changes a post's `status`; the worst it can do is add/replace translated text on a
 * post whose PUBLISH state a human (or `set_post_status`, editor-surface only) already
 * decided independently. Restricting it to drafts on the external surface would only
 * block the exact workflow it exists for — "loop list_posts -> create_translation over
 * an already-published catalog" — for no safety gained.
 */
final class TranslationTools implements McpToolProvider
{
    public function __construct(
        private PostAccess $posts,
        private ContentBlockPipeline $blocks,
        private BlockRegistryService $registry,
        private TranslationStatusService $translationStatus,
        private TocService $toc,
    ) {
    }

    /**
     * `toc` as a clean list of {anchor, label}, or null when the call did not pass one.
     *
     * @return list<array{anchor: string, label: string}>|null
     */
    private function tocLabels(mixed $toc): ?array
    {
        if ($toc === null) {
            return null;
        }
        if (! is_array($toc) || $toc === []) {
            throw new McpToolException('toc must be a non-empty array of {anchor, label} objects.');
        }

        $labels = [];
        foreach (array_values($toc) as $i => $entry) {
            $anchor = is_array($entry) ? trim((string) ($entry['anchor'] ?? '')) : '';
            $label = is_array($entry) ? trim((string) ($entry['label'] ?? '')) : '';
            if ($anchor === '' || $label === '') {
                throw new McpToolException("toc[{$i}] needs a non-empty anchor and label.");
            }
            if (mb_strlen($label) > 160) {
                throw new McpToolException("toc[{$i}].label must be 160 characters or fewer.");
            }
            $labels[] = ['anchor' => $anchor, 'label' => $label];
        }

        return $labels;
    }

    public function definitions(): array
    {
        return [
            // The single-row model (docs/content-translation.md §0): a post's translation is
            // NOT a separate row — it is locale-suffixed attribute variants (`content_fr`, …) on
            // the SAME row. This tool keeps its Wave-1 name (existing agents already call it) but
            // its job changed: it translates THIS post's fields in place. `title`/`excerpt` write
            // straight to `title_<locale>`/`excerpt_<locale>`; `code` is parsed and validated
            // through the exact same pipeline create_post/update_post use (BlocksPayloadService,
            // live contracts), then folded into the EXISTING stored blocks' translatable
            // attributes as `_<locale>` variants, matched BY POSITION (top-level index, then
            // recursively through innerBlocks) — the block TREE itself is never replaced,
            // because structure is shared across every locale now. A translated document whose
            // shape (block count, or a block name at any position/depth) doesn't match what's
            // stored is refused outright, naming the mismatch, rather than silently corrupting
            // the post's structure.
            'create_translation' => [
                'description' => 'Translate an existing post\'s fields into another locale — writes locale-suffixed variants on the SAME row '
                    . '(docs/content-translation.md §0), it does not create a new post. Read the source with get_post first (its `code` is the '
                    . 'structure to translate). Supply at least one of `title`/`excerpt`/`code`. `title`/`excerpt` are translated text written to '
                    . '`title_<locale>`/`excerpt_<locale>`. `code` must be the SAME block sequence and structure as the post\'s current content '
                    . '(only human-readable text translated — never block names, attribute names, ids, URLs or media references); it is validated '
                    . 'like update_post, then folded into the existing blocks by position — a structural mismatch (different block count, or a '
                    . 'different block at some position) is refused with an error naming where, not silently applied. No draft-only '
                    . 'restriction: this edits fields of an existing post, it never changes or creates its publish status. '
                    . '`toc` translates the post\'s table of contents (get_post\'s `toc`): one {anchor, label} per entry, the anchor copied '
                    . 'unchanged and only the label translated; an anchor the post does not have is refused. A translation is not complete '
                    . 'until the title, every block and every table-of-contents label are translated. '
                    . 'Returns the target locale\'s translation completeness from get_post\'s own `translations` shape.',
                'tier' => self::TIER_AUTHORS,
                // External clients only. It writes straight to the database, behind any editor that
                // has the post open; the in-editor assistant translates with translate_page, which
                // lands in the open document that the author then saves.
                'surface' => self::SURFACE_EXTERNAL,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer', 'description' => 'Post id to translate.'],
                    'target_locale' => ['type' => 'string', 'description' => 'Locale to translate into (must differ from the post\'s own home locale), e.g. "fr".'],
                    'title' => ['type' => 'string', 'description' => 'Translated title, written to title_<target_locale>.'],
                    'excerpt' => ['type' => 'string', 'description' => 'Translated excerpt, written to excerpt_<target_locale>.'],
                    'code' => ['type' => 'string', 'description' => 'The translated document as Heisenberg shortcode — same block sequence and structure as the post\'s stored content, text translated. Folded into the existing blocks by position, never replaces the tree.'],
                    'toc' => [
                        'type' => 'array',
                        'description' => 'Translated table-of-contents labels, one per entry of get_post\'s `toc`: {anchor (unchanged), label (translated)}.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'anchor' => ['type' => 'string', 'description' => 'The entry\'s anchor, exactly as get_post returned it.'],
                                'label' => ['type' => 'string', 'description' => 'The translated label, plain text.'],
                            ],
                            'required' => ['anchor', 'label'],
                        ],
                    ],
                ], ['post_id', 'target_locale']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return $tool === 'create_translation';
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return $this->createTranslation($arguments);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{post_id: int|string, locale: string, complete: bool, blocks_translated: int, blocks_total: int, toc_translated: int, toc_total: int}
     */
    private function createTranslation(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);

        $targetLocale = trim((string) ($args['target_locale'] ?? ''));
        if ($targetLocale === '' || ! LocaleConfig::isValid($targetLocale)) {
            $allowed = implode(', ', LocaleConfig::locales());
            throw new McpToolException("target_locale must be one of: {$allowed} (got '{$targetLocale}').");
        }

        $homeLocale = (string) ($post->locale ?: LocaleConfig::default());
        if ($targetLocale === $homeLocale) {
            throw new McpToolException(
                "target_locale ('{$targetLocale}') must differ from the post's own home locale ('{$homeLocale}') — there is nothing to translate into its own language."
            );
        }

        $hasTitle = array_key_exists('title', $args) && is_string($args['title']);
        $hasExcerpt = array_key_exists('excerpt', $args) && is_string($args['excerpt']);
        $hasCode = array_key_exists('code', $args) && is_string($args['code']) && trim($args['code']) !== '';
        $tocLabels = $this->tocLabels($args['toc'] ?? null);

        if (! $hasTitle && ! $hasExcerpt && ! $hasCode && $tocLabels === null) {
            throw new McpToolException('Supply at least one of: title, excerpt, code, toc — there is nothing to translate.');
        }

        // Anchors are checked BEFORE the transaction, like the block code is validated below: a
        // bad call never lands half-applied.
        if ($tocLabels !== null) {
            $known = $post->tocEntries()->pluck('anchor')->all();
            $unknown = array_values(array_diff(array_column($tocLabels, 'anchor'), $known));
            if ($unknown !== []) {
                $have = $known === [] ? 'it has no table of contents' : 'its anchors are: ' . implode(', ', $known);
                throw new McpToolException('toc: unknown anchor(s) ' . implode(', ', $unknown) . " — {$have}. Copy each anchor unchanged from get_post's `toc`.");
            }
        }

        // Parsed + validated through the SAME pipeline update_post uses
        // (validatedContentBlocks()'s own docblock) BEFORE any write, so a bad translation never
        // lands half-applied. The position-matched fold against the STORED tree happens inside
        // the transaction below, once we know nothing else about the call will fail first.
        $translatedBlocks = $hasCode ? $this->blocks->validatedContentBlocks(['code' => (string) $args['code']]) : null;

        return DB::transaction(function () use ($post, $targetLocale, $args, $hasTitle, $hasExcerpt, $translatedBlocks, $tocLabels): array {
            if ($tocLabels !== null) {
                $this->toc->translate($post, $targetLocale, $tocLabels);
                $post->unsetRelation('tocEntries');
            }
            if ($hasTitle) {
                $this->setLocaleField($post, $targetLocale, 'title', trim((string) $args['title']));
            }
            if ($hasExcerpt) {
                $this->setLocaleField($post, $targetLocale, 'excerpt', (string) $args['excerpt']);
            }
            if ($hasTitle || $hasExcerpt) {
                $post->save();
            }

            if ($translatedBlocks !== null) {
                $blockModels = $post->blocks()->orderBy('order')->get();
                $storedBlocks = $blockModels->map(static fn ($b) => $b->content)->values()->all();

                // Refuses (throws) on any shape mismatch BEFORE anything below writes — see the
                // method's own docblock for the exact rule.
                $folded = $this->foldTranslatedBlocks($storedBlocks, $translatedBlocks, $targetLocale);

                if ($storedBlocks !== []) {
                    $this->posts->captureRevision($post, 'manual');
                }

                $blocksChanged = false;
                foreach ($folded as $index => $content) {
                    $block = $blockModels->get($index);
                    if ($block !== null && $block->content !== $content) {
                        $block->content = $content;
                        $block->save();
                        $blocksChanged = true;
                    }
                }
                if ($blocksChanged) {
                    $post->bumpContentVersion();
                }
            }

            $post->refresh();

            return $this->translationCompleteness($post, $targetLocale);
        });
    }

    /**
     * Fold `$translated` (a freshly parsed+validated block tree, target `$locale`) into
     * `$stored` (the post's CURRENT block tree) by POSITION — top-level index, then recursively
     * through `innerBlocks` at the same index. Returns the (possibly modified) `$stored` tree
     * with `_<locale>` attribute variants written in; throws {@see McpToolException} naming every
     * shape mismatch found (a different block count, or a different block `name`, at any
     * position/depth) rather than writing anything when the shapes disagree — see
     * {@see MergeTranslationsCommand::mergeNode()} for the sibling
     * precedent this mirrors (position-matched fold, refuse on shape mismatch); this version has
     * no "already different content" conflict to check, because overwriting a locale's existing
     * translation IS what re-running create_translation for it means.
     *
     * @param list<array<string, mixed>> $stored
     * @param list<array<string, mixed>> $translated
     * @return list<array<string, mixed>>
     */
    private function foldTranslatedBlocks(array $stored, array $translated, string $locale): array
    {
        $mismatches = [];
        $folded = $this->foldNodes($stored, $translated, $locale, $mismatches, 'blocks');

        if ($mismatches !== []) {
            throw new McpToolException(
                "The translated code's structure does not match this post's stored blocks: " . implode('; ', $mismatches)
                . '. Translate the SAME block sequence and structure as the source (get_post\'s `code`) — only human-readable text may change.'
            );
        }

        return $folded;
    }

    /**
     * @param list<array<string, mixed>> $storedNodes
     * @param list<array<string, mixed>> $translatedNodes
     * @param string[] $mismatches
     * @return list<array<string, mixed>>
     */
    private function foldNodes(array $storedNodes, array $translatedNodes, string $locale, array &$mismatches, string $path): array
    {
        if (count($storedNodes) !== count($translatedNodes)) {
            $mismatches[] = "{$path}: block count differs (post has " . count($storedNodes) . ', translated code has ' . count($translatedNodes) . ')';

            return $storedNodes;
        }

        foreach ($storedNodes as $index => $storedNode) {
            $storedNodes[$index] = $this->foldNode(
                is_array($storedNode) ? $storedNode : [],
                is_array($translatedNodes[$index]) ? $translatedNodes[$index] : [],
                $locale,
                $mismatches,
                "{$path}[{$index}]",
            );
        }

        return $storedNodes;
    }

    /**
     * @param array<string, mixed> $storedNode
     * @param array<string, mixed> $translatedNode
     * @param string[] $mismatches
     * @return array<string, mixed>
     */
    private function foldNode(array $storedNode, array $translatedNode, string $locale, array &$mismatches, string $path): array
    {
        $storedName = $storedNode['name'] ?? null;
        $translatedName = $translatedNode['name'] ?? null;

        if (! is_string($storedName) || $storedName !== $translatedName) {
            $mismatches[] = "{$path}: block name mismatch ('" . (is_string($storedName) ? $storedName : 'null')
                . "' vs '" . (is_string($translatedName) ? $translatedName : 'null') . "')";

            return $storedNode;
        }

        $keys = $this->registry->translatableAttributes($storedName);
        $storedAttrs = is_array($storedNode['attributes'] ?? null) ? $storedNode['attributes'] : [];
        $translatedAttrs = is_array($translatedNode['attributes'] ?? null) ? $translatedNode['attributes'] : [];

        foreach ($keys as $key) {
            // The translated node's BARE value is the translator's actual text for this call —
            // an agent authors plain shortcode, never a suffixed variant, so read()'s
            // fallback-to-bare is exactly right here (same posture MergeTranslationsCommand's
            // mergeNode() takes reading a split-row sibling's own bare content).
            $value = LocalizedAttributes::read($translatedAttrs, $key, $locale);
            if (! LocalizedAttributes::hasContent($value)) {
                continue;
            }
            $storedAttrs = LocalizedAttributes::write($storedAttrs, $key, $locale, $value);
        }
        $storedNode['attributes'] = $storedAttrs;

        $storedInner = is_array($storedNode['innerBlocks'] ?? null) ? $storedNode['innerBlocks'] : [];
        $translatedInner = is_array($translatedNode['innerBlocks'] ?? null) ? $translatedNode['innerBlocks'] : [];

        if (count($storedInner) !== count($translatedInner)) {
            $mismatches[] = "{$path}: innerBlocks count differs (post has " . count($storedInner) . ', translated code has ' . count($translatedInner) . ')';

            return $storedNode;
        }

        foreach ($storedInner as $index => $child) {
            $storedInner[$index] = $this->foldNode(
                is_array($child) ? $child : [],
                is_array($translatedInner[$index]) ? $translatedInner[$index] : [],
                $locale,
                $mismatches,
                "{$path}>{$index}",
            );
        }
        $storedNode['innerBlocks'] = $storedInner;

        return $storedNode;
    }

    /** Writes `$value` into `{$field}_en` or `{$field}_fr` depending on `$locale` — the bilingual-column shape `title_en`/`title_fr` and `excerpt_en`/`excerpt_fr` share (docs/content-translation.md §3 caps real support at en/fr). */
    private function setLocaleField(Post $post, string $locale, string $field, ?string $value): void
    {
        $column = $field . '_' . ($locale === 'fr' ? 'fr' : 'en');
        $post->{$column} = $value;
    }

    /**
     * `$locale`'s row from {@see TranslationStatusService::statuses()}, reshaped to
     * `create_translation`'s return contract — the same completeness signal `get_post`'s
     * `translations` map reports for this locale, so a caller sees a consistent number either
     * way it asks.
     *
     * @return array{post_id: int|string, locale: string, complete: bool, blocks_translated: int, blocks_total: int, toc_translated: int, toc_total: int}
     */
    private function translationCompleteness(Post $post, string $locale): array
    {
        foreach ($this->translationStatus->statuses($post) as $row) {
            if ($row['locale'] === $locale) {
                return [
                    'post_id' => $post->getKey(),
                    'locale' => $locale,
                    'complete' => $row['complete'],
                    'blocks_translated' => $row['blocks_translated'],
                    'blocks_total' => $row['blocks_total'],
                    'toc_translated' => $row['toc_translated'],
                    'toc_total' => $row['toc_total'],
                ];
            }
        }

        // Unreachable in practice ($locale was already validated against LocaleConfig, and
        // statuses() returns one row per configured locale) — kept as a safe default rather than
        // an assertion, so a future config change degrades gracefully instead of fataling here.
        return ['post_id' => $post->getKey(), 'locale' => $locale, 'complete' => false, 'blocks_translated' => 0, 'blocks_total' => 0, 'toc_translated' => 0, 'toc_total' => 0];
    }
}
