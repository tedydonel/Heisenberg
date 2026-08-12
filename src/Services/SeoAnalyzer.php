<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Support\Str;

/**
 * Deterministic, server-side SEO score & checklist (docs/seo-system.md §4, Wave S2b). No HTTP
 * calls, no I/O beyond the DB reads {@see analyze()} itself performs (the post's `SeoMeta` row
 * and its block tree) — pure computation over what's already loaded, so it's cheap enough to run
 * on every keystroke-debounced panel edit (via `$overrides`) as well as on save.
 *
 * `$overrides` (the SEO/Social panel's UNSAVED edits) always wins over the persisted `SeoMeta`
 * row/post title when the corresponding key is present in the array — same "pending edit beats
 * stored value" posture as `hbPendingSeo` (docs/seo-system.md §3). A key that is present but an
 * empty string is a real value (the user cleared the field), not "unset" — it is trimmed and, for
 * `meta_title` only, falls back to the post's own title exactly the way the public preview page's
 * `<title>` resolves (`preview.blade.php`: `$metaTitle = trim(...) ?: $title`), so "meta title
 * present" measures what a visitor's browser tab would actually show, not a raw column.
 *
 * DUAL MESSAGE SHAPE: every check carries both a plain-English `message` (this analyzer is reused
 * by the MCP `analyze_seo` tool, docs/seo-system.md §6, which has no Blade/lang-file access) AND a
 * `message_key` (`heisenberg::seo.checks.{id}.{status}`) + `params` pair for the editor panel to
 * localize itself. This wave does NOT add the corresponding lang file entries (lang files are a
 * parallel wave's surface, docs/seo-system.md §3) — `message_key` is data the UI can look up once
 * those land; until then `message` is what actually renders.
 */
class SeoAnalyzer
{
    /** Weighted checklist (docs/seo-system.md §4's table) — id => [group, weight]. */
    private const WEIGHTS = [
        'title-length' => ['meta', 10],
        'description-length' => ['meta', 10],
        'keyphrase-set' => ['keyphrase', 5],
        'keyphrase-in-title' => ['keyphrase', 10],
        'keyphrase-in-slug' => ['keyphrase', 5],
        'keyphrase-in-description' => ['keyphrase', 5],
        'keyphrase-in-intro' => ['keyphrase', 5],
        'density' => ['keyphrase', 10],
        'content-length' => ['content', 10],
        'heading-hierarchy' => ['content', 8],
        'paragraph-length' => ['content', 4],
        'image-alts' => ['media', 6],
        'internal-link' => ['media', 5],
        'outbound-link' => ['media', 4],
        'slug-quality' => ['technical', 5],
        'canonical' => ['technical', 3],
        'indexable' => ['technical', 10],
        'og-image' => ['technical', 4],
        'readability' => ['readability', 8],
    ];

    /** First ~N words of the body treated as "the intro" for `keyphrase-in-intro`. */
    private const INTRO_WORDS = 150;

    /**
     * @param array{meta_title?:string,meta_description?:string,focus_keyphrase?:string,slug?:string,canonical?:string,robots?:string,og_image?:string} $overrides
     * @return array{score:int,rating:string,checks:list<array<string,mixed>>}
     */
    public function analyze(Post $post, string $locale, array $overrides = []): array
    {
        $locale = LocaleConfig::isValid($locale) ? $locale : LocaleConfig::default();

        $seo = SeoMeta::query()
            ->where('able_type', $post->getMorphClass())
            ->where('able_id', $post->getKey())
            ->first();

        $rawMetaTitle = $this->resolve($overrides, 'meta_title', $seo?->metaTitle($locale) ?? '');
        $title = $rawMetaTitle !== '' ? $rawMetaTitle : $post->title($locale);
        $description = $this->resolve($overrides, 'meta_description', $seo?->metaDescription($locale) ?? '');
        $keyphrase = trim($this->resolve($overrides, 'focus_keyphrase', $seo?->focusKeyphrase($locale) ?? ''));
        $slug = $this->resolve($overrides, 'slug', (string) $post->slug);
        $canonical = $this->resolve($overrides, 'canonical', (string) ($seo?->canonical_url ?? ''));
        $robots = $this->resolve($overrides, 'robots', (string) ($seo?->robots ?: 'index, follow'));
        $ogImage = $this->resolve($overrides, 'og_image', (string) ($seo?->og_image ?? ''));

        $content = $this->extractContent($post, $locale);

        $checks = [
            $this->titleLengthCheck($title),
            $this->descriptionLengthCheck($description),
            $this->keyphraseSetCheck($keyphrase),
            $this->keyphraseInTitleCheck($keyphrase, $title),
            $this->keyphraseInSlugCheck($keyphrase, $slug),
            $this->keyphraseInDescriptionCheck($keyphrase, $description),
            $this->keyphraseInIntroCheck($keyphrase, $content['plainText']),
            $this->densityCheck($keyphrase, $content['plainText'], $content['wordCount']),
            $this->contentLengthCheck($content['wordCount']),
            $this->headingHierarchyCheck($content['headings'], $content['wordCount']),
            $this->paragraphLengthCheck($content['paragraphs']),
            $this->imageAltsCheck($content['images']),
            $this->internalLinkCheck($content['links']),
            $this->outboundLinkCheck($content['links']),
            $this->slugQualityCheck($slug),
            $this->canonicalCheck($canonical),
            $this->indexableCheck($post, $robots),
            $this->ogImageCheck($ogImage),
            $this->readabilityCheck($content['plainText'], $content['wordCount'], $locale),
        ];

        return [
            'score' => $this->score($checks),
            'rating' => $this->rating($this->score($checks)),
            'checks' => $checks,
        ];
    }

    /** `$overrides[$key]` wins whenever the key is present at all (even an intentional ''); else `$fallback`. */
    private function resolve(array $overrides, string $key, string $fallback): string
    {
        return array_key_exists($key, $overrides) ? trim((string) $overrides[$key]) : trim($fallback);
    }

    // ── Meta ──────────────────────────────────────────────────────────────

    private function titleLengthCheck(string $title): array
    {
        $len = mb_strlen($title);
        if ($len === 0) {
            return $this->check('title-length', 'fail', 'No title set — search results will show a fallback title.', ['length' => $len]);
        }
        if ($len < 30 || $len > 60) {
            return $this->check('title-length', 'warn', "Title is {$len} characters — aim for 30–60 so it doesn't get cut off in search results.", ['length' => $len, 'min' => 30, 'max' => 60]);
        }

        return $this->check('title-length', 'pass', "Title length ({$len} characters) is in the ideal range.", ['length' => $len]);
    }

    private function descriptionLengthCheck(string $description): array
    {
        $len = mb_strlen($description);
        if ($len === 0) {
            return $this->check('description-length', 'fail', 'No meta description set — search engines will pick their own snippet.', ['length' => $len]);
        }
        if ($len < 50 || $len > 160) {
            return $this->check('description-length', 'warn', "Description is {$len} characters — aim for 50–160.", ['length' => $len, 'min' => 50, 'max' => 160]);
        }

        return $this->check('description-length', 'pass', "Description length ({$len} characters) is in the ideal range.", ['length' => $len]);
    }

    // ── Keyphrase ─────────────────────────────────────────────────────────

    private function keyphraseSetCheck(string $keyphrase): array
    {
        return $keyphrase !== ''
            ? $this->check('keyphrase-set', 'pass', 'Focus keyphrase is set.')
            : $this->check('keyphrase-set', 'warn', 'Set a focus keyphrase to unlock keyphrase-based checks.');
    }

    private function keyphraseInTitleCheck(string $keyphrase, string $title): array
    {
        if ($keyphrase === '') {
            return $this->notApplicable('keyphrase-in-title');
        }

        return $this->contains($title, $keyphrase)
            ? $this->check('keyphrase-in-title', 'pass', 'Focus keyphrase appears in the title.')
            : $this->check('keyphrase-in-title', 'fail', 'Focus keyphrase does not appear in the title.');
    }

    private function keyphraseInSlugCheck(string $keyphrase, string $slug): array
    {
        if ($keyphrase === '') {
            return $this->notApplicable('keyphrase-in-slug');
        }

        $slugPhrase = str_replace('-', ' ', $slug);

        return $this->contains($slugPhrase, $keyphrase)
            ? $this->check('keyphrase-in-slug', 'pass', 'Focus keyphrase appears in the slug.')
            : $this->check('keyphrase-in-slug', 'warn', 'Focus keyphrase does not appear in the slug.');
    }

    private function keyphraseInDescriptionCheck(string $keyphrase, string $description): array
    {
        if ($keyphrase === '') {
            return $this->notApplicable('keyphrase-in-description');
        }

        return $this->contains($description, $keyphrase)
            ? $this->check('keyphrase-in-description', 'pass', 'Focus keyphrase appears in the meta description.')
            : $this->check('keyphrase-in-description', 'warn', 'Focus keyphrase does not appear in the meta description.');
    }

    private function keyphraseInIntroCheck(string $keyphrase, string $plainText): array
    {
        if ($keyphrase === '') {
            return $this->notApplicable('keyphrase-in-intro');
        }

        $words = $this->words($plainText);
        $intro = implode(' ', array_slice($words, 0, self::INTRO_WORDS));

        return $this->contains($intro, $keyphrase)
            ? $this->check('keyphrase-in-intro', 'pass', 'Focus keyphrase appears early in the content.')
            : $this->check('keyphrase-in-intro', 'warn', 'Focus keyphrase does not appear in the first ' . self::INTRO_WORDS . ' words.');
    }

    private function densityCheck(string $keyphrase, string $plainText, int $wordCount): array
    {
        if ($keyphrase === '') {
            return $this->notApplicable('density');
        }
        if ($wordCount === 0) {
            return $this->check('density', 'fail', 'No content to measure keyphrase density against.');
        }

        $occurrences = $this->countOccurrences($plainText, $keyphrase);
        $density = round($occurrences / $wordCount * 100, 2);

        if ($occurrences === 0) {
            return $this->check('density', 'fail', 'Focus keyphrase was not found in the content.', ['density' => $density]);
        }
        if ($density < 0.5) {
            return $this->check('density', 'warn', "Keyphrase density is {$density}% — aim for 0.5–2.5%.", ['density' => $density, 'min' => 0.5, 'max' => 2.5]);
        }
        if ($density > 2.5) {
            return $this->check('density', 'fail', "Keyphrase density is {$density}% — that reads as keyword stuffing, aim for 0.5–2.5%.", ['density' => $density, 'min' => 0.5, 'max' => 2.5]);
        }

        return $this->check('density', 'pass', "Keyphrase density ({$density}%) is in the ideal range.", ['density' => $density]);
    }

    // ── Content ───────────────────────────────────────────────────────────

    private function contentLengthCheck(int $wordCount): array
    {
        if ($wordCount >= 300) {
            return $this->check('content-length', 'pass', "Content is {$wordCount} words — a healthy length.", ['words' => $wordCount]);
        }
        if ($wordCount >= 150) {
            return $this->check('content-length', 'warn', "Content is {$wordCount} words — aim for at least 300.", ['words' => $wordCount, 'min' => 300]);
        }

        return $this->check('content-length', 'fail', "Content is only {$wordCount} words — aim for at least 300.", ['words' => $wordCount, 'min' => 300]);
    }

    /** @param list<array{level:int,text:string}> $headings */
    private function headingHierarchyCheck(array $headings, int $wordCount): array
    {
        foreach ($headings as $heading) {
            if ($heading['level'] === 1) {
                return $this->check('heading-hierarchy', 'fail', 'A content heading uses H1 — that level is reserved for the post title.');
            }
        }

        if ($headings === []) {
            return $wordCount >= 300
                ? $this->check('heading-hierarchy', 'warn', 'Long content has no subheadings — add H2/H3s to break it up.')
                : $this->check('heading-hierarchy', 'pass', 'No headings needed for this short content.');
        }

        $previous = null;
        foreach ($headings as $heading) {
            if ($previous !== null && $heading['level'] > $previous + 1) {
                return $this->check('heading-hierarchy', 'warn', 'Heading levels skip a level (e.g. H2 straight to H4) — keep the hierarchy sequential.');
            }
            $previous = $heading['level'];
        }

        return $this->check('heading-hierarchy', 'pass', 'Heading hierarchy looks sane.');
    }

    /** @param list<string> $paragraphs */
    private function paragraphLengthCheck(array $paragraphs): array
    {
        $longest = 0;
        foreach ($paragraphs as $paragraph) {
            $longest = max($longest, count($this->words($paragraph)));
        }

        if ($longest === 0) {
            return $this->check('paragraph-length', 'pass', 'No paragraphs to check.');
        }
        if ($longest > 200) {
            return $this->check('paragraph-length', 'warn', "The longest paragraph is about {$longest} words — break it into shorter ones.", ['words' => $longest, 'max' => 200]);
        }

        return $this->check('paragraph-length', 'pass', 'Paragraph lengths look reasonable.', ['words' => $longest]);
    }

    // ── Media & links ────────────────────────────────────────────────────

    /** @param list<array{hasAlt:bool}> $images */
    private function imageAltsCheck(array $images): array
    {
        if ($images === []) {
            return $this->check('image-alts', 'pass', 'No images to check.');
        }

        $missing = count(array_filter($images, static fn (array $i): bool => ! $i['hasAlt']));
        $total = count($images);

        if ($missing === 0) {
            return $this->check('image-alts', 'pass', "All {$total} image(s) have alt text.");
        }
        if ($missing < $total) {
            return $this->check('image-alts', 'warn', "{$missing} of {$total} images are missing alt text.", ['missing' => $missing, 'total' => $total]);
        }

        return $this->check('image-alts', 'fail', "None of the {$total} image(s) have alt text.", ['missing' => $missing, 'total' => $total]);
    }

    /** @param list<array{href:string,internal:bool}> $links */
    private function internalLinkCheck(array $links): array
    {
        $count = count(array_filter($links, static fn (array $l): bool => $l['internal']));

        return $count > 0
            ? $this->check('internal-link', 'pass', "{$count} internal link(s) found.", ['count' => $count])
            : $this->check('internal-link', 'warn', 'Add at least one internal link to related content.');
    }

    /** @param list<array{href:string,internal:bool}> $links */
    private function outboundLinkCheck(array $links): array
    {
        $count = count(array_filter($links, static fn (array $l): bool => ! $l['internal']));

        return $count > 0
            ? $this->check('outbound-link', 'pass', "{$count} outbound link(s) found.", ['count' => $count])
            : $this->check('outbound-link', 'warn', 'Add at least one outbound link to a credible external source.');
    }

    // ── Technical ────────────────────────────────────────────────────────

    private function slugQualityCheck(string $slug): array
    {
        if ($slug === '') {
            return $this->check('slug-quality', 'fail', 'The post has no slug.');
        }
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            return $this->check('slug-quality', 'fail', 'Slug should only contain lowercase letters, numbers, and hyphens.');
        }
        if (mb_strlen($slug) > 75) {
            return $this->check('slug-quality', 'warn', 'Slug is long — shorter slugs are easier to read and share.', ['length' => mb_strlen($slug)]);
        }

        return $this->check('slug-quality', 'pass', 'Slug is short and clean.');
    }

    private function canonicalCheck(string $canonical): array
    {
        if ($canonical === '') {
            return $this->check('canonical', 'pass', 'No canonical URL set — the page canonicalizes to itself.');
        }

        $valid = preg_match('#^https?://#i', $canonical) === 1 && filter_var($canonical, FILTER_VALIDATE_URL) !== false;

        return $valid
            ? $this->check('canonical', 'pass', 'Canonical URL is set.')
            : $this->check('canonical', 'warn', "Canonical URL doesn't look like a valid absolute URL.");
    }

    private function indexableCheck(Post $post, string $robots): array
    {
        $noindex = str_contains(strtolower($robots), 'noindex');

        if ($post->status === 'published' && $noindex) {
            return $this->check('indexable', 'fail', "This post is published but marked noindex — search engines won't index it.");
        }

        return $this->check('indexable', 'pass', 'Post is indexable.');
    }

    private function ogImageCheck(string $ogImage): array
    {
        return $ogImage !== ''
            ? $this->check('og-image', 'pass', 'Social share image (og:image) is set.')
            : $this->check('og-image', 'warn', 'Add a social share image for better link previews.');
    }

    // ── Readability ──────────────────────────────────────────────────────

    private function readabilityCheck(string $plainText, int $wordCount, string $locale): array
    {
        if ($wordCount === 0) {
            return $this->check('readability', 'warn', 'Not enough content to measure readability.');
        }

        $flesch = $this->fleschReadingEase($plainText);
        if ($flesch === null) {
            return $this->check('readability', 'warn', 'Not enough content to measure readability.');
        }

        $score = round($flesch, 1);

        // FR thresholds sit ~5 points below EN: the Flesch formula's coefficients are calibrated
        // on English syllable/sentence statistics, and French text — more syllables per word on
        // average (grammatical -e endings, liaisons) — reads lower on the SAME formula even when
        // it is equally easy to read. This is a pragmatic shift, not the (differently-coefficiented)
        // validated French Flesch adaptation (Kandel & Moles) — good enough for a directional signal.
        [$passMin, $warnMin] = $locale === 'fr' ? [55, 35] : [60, 40];

        if ($score >= $passMin) {
            return $this->check('readability', 'pass', "Readability score is {$score} — easy to read.", ['score' => $score]);
        }
        if ($score >= $warnMin) {
            return $this->check('readability', 'warn', "Readability score is {$score} — fairly difficult, consider shorter sentences and words.", ['score' => $score]);
        }

        return $this->check('readability', 'fail', "Readability score is {$score} — difficult to read.", ['score' => $score]);
    }

    private function fleschReadingEase(string $text): ?float
    {
        $words = $this->words($text);
        $wordCount = count($words);
        if ($wordCount === 0) {
            return null;
        }

        $sentences = max(1, preg_match_all('/[.!?]+/u', $text) ?: 0);
        $syllables = 0;
        foreach ($words as $word) {
            $syllables += $this->countSyllables($word);
        }

        return 206.835 - 1.015 * ($wordCount / $sentences) - 84.6 * ($syllables / $wordCount);
    }

    /** Vowel-group heuristic (EN/FR): count runs of vowels (accented included) in the word, minimum 1. */
    private function countSyllables(string $word): int
    {
        $word = mb_strtolower($word, 'UTF-8');
        $letters = preg_replace('/[^a-zàâäéèêëïîôöùûüÿœæç]/u', '', $word) ?? '';
        if ($letters === '') {
            return 1;
        }

        $groups = preg_match_all('/[aeiouyàâäéèêëïîôöùûüÿœæ]+/u', $letters);

        return max(1, $groups ?: 1);
    }

    // ── Block-tree extraction ────────────────────────────────────────────

    /**
     * Flattens the post's stored block tree into plain text + structure for the checks above.
     * Deterministic, no rendering: walks the RAW attribute values (not through BlockRenderer's
     * sanitizing pipeline — see that class + resources/blocks/*.json for the attribute names
     * relied on here: heading `content`+`level`, paragraph/quote `content`, list's plain-text
     * `content` (one item per line), image `alt`/`href`/`caption`, button `text`+`url`; group/
     * columns/column carry no text of their own and are recursed into via `innerBlocks`).
     *
     * @return array{plainText:string,wordCount:int,headings:list<array{level:int,text:string}>,paragraphs:list<string>,images:list<array{hasAlt:bool}>,links:list<array{href:string,internal:bool}>}
     */
    private function extractContent(Post $post, string $locale): array
    {
        $acc = ['headings' => [], 'paragraphs' => [], 'bodyText' => [], 'images' => [], 'links' => []];

        $blocks = $post->blocks->map(fn ($block) => $block->content)->filter(static fn ($c) => is_array($c))->values()->all();
        $this->walkBlocks($blocks, $locale, $acc);

        $plainText = trim(implode(' ', $acc['bodyText']));

        return [
            'plainText' => $plainText,
            'wordCount' => count($this->words($plainText)),
            'headings' => $acc['headings'],
            'paragraphs' => $acc['paragraphs'],
            'images' => $acc['images'],
            'links' => $acc['links'],
        ];
    }

    private function walkBlocks(array $blocks, string $locale, array &$acc): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['name'] ?? null)) {
                continue;
            }

            $attributes = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];
            $this->walkBlock((string) $block['name'], $attributes, $locale, $acc);

            $inner = $block['innerBlocks'] ?? null;
            if (is_array($inner) && $inner !== []) {
                $this->walkBlocks($inner, $locale, $acc);
            }
        }
    }

    private function walkBlock(string $name, array $attributes, string $locale, array &$acc): void
    {
        switch ($name) {
            case 'heisenberg/heading':
                [$text, $links] = $this->richText($this->localizedAttribute($attributes, 'content', $locale));
                if ($text !== '') {
                    $level = (int) ($attributes['level'] ?? 2);
                    $acc['headings'][] = ['level' => $level, 'text' => $text];
                    $acc['bodyText'][] = $text;
                }
                $this->collectLinks($links, $acc);
                break;

            case 'heisenberg/paragraph':
            case 'heisenberg/quote':
                [$text, $links] = $this->richText($this->localizedAttribute($attributes, 'content', $locale));
                if ($text !== '') {
                    $acc['paragraphs'][] = $text;
                    $acc['bodyText'][] = $text;
                }
                $this->collectLinks($links, $acc);
                if ($name === 'heisenberg/quote') {
                    [$citation] = $this->richText($this->localizedAttribute($attributes, 'citation', $locale));
                    if ($citation !== '') {
                        $acc['bodyText'][] = $citation;
                    }
                }
                break;

            case 'heisenberg/list':
                $raw = (string) $this->localizedAttribute($attributes, 'content', $locale);
                foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $acc['paragraphs'][] = $line;
                        $acc['bodyText'][] = $line;
                    }
                }
                break;

            case 'heisenberg/image':
                $alt = trim((string) ($attributes['alt'] ?? ''));
                if ($alt === '') {
                    $alt = $this->resolveImageAltFromFileReference($attributes, $locale);
                }
                $acc['images'][] = ['hasAlt' => $alt !== ''];

                $href = trim((string) ($attributes['href'] ?? ''));
                if ($href !== '') {
                    $this->collectLinks([$href], $acc);
                }

                [$caption, $captionLinks] = $this->richText($this->localizedAttribute($attributes, 'caption', $locale));
                if ($caption !== '') {
                    $acc['bodyText'][] = $caption;
                }
                $this->collectLinks($captionLinks, $acc);
                break;

            case 'heisenberg/button':
                $url = trim((string) ($attributes['url'] ?? ''));
                if ($url !== '') {
                    $this->collectLinks([$url], $acc);
                }
                break;

            case 'heisenberg/group':
            case 'heisenberg/columns':
            case 'heisenberg/column':
            case 'heisenberg/embed':
            case 'heisenberg/separator':
            case 'heisenberg/icon':
                // No text of their own; group/columns/column contribute via the innerBlocks
                // recursion in walkBlocks(), the rest carry nothing analyzable.
                break;

            default:
                // A future/custom block: best-effort — a plain or rich-text `content` attribute
                // is common enough across contracts to be worth reading generically.
                $raw = $this->localizedAttribute($attributes, 'content', $locale);
                if (is_string($raw) && $raw !== '') {
                    [$text, $links] = $this->richText($raw);
                    if ($text !== '') {
                        $acc['paragraphs'][] = $text;
                        $acc['bodyText'][] = $text;
                    }
                    $this->collectLinks($links, $acc);
                }
                break;
        }
    }

    /**
     * A block schema has no `fileId`/`imageId` attribute referencing {@see PublicFile} today (the
     * media picker copies alt text straight into the block's own `alt` attribute at insert time —
     * see resources/blocks/image/image.json), but the doc anticipates one landing later. Read any
     * of the plausible attribute names defensively so this keeps working the day one does, without
     * a required schema change here.
     */
    private function resolveImageAltFromFileReference(array $attributes, string $locale): string
    {
        foreach (['fileId', 'file_id', 'imageId', 'image_id'] as $key) {
            $value = $attributes[$key] ?? null;
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $class = (string) config('heisenberg.models.public_file', PublicFile::class);
                $file = $class::query()->find((int) $value);
                if ($file !== null) {
                    return $file->getAlt($locale);
                }
            }
        }

        return '';
    }

    /** Locale-aware attribute lookup: `key_<locale>` then bare `key` (mirrors BlockRenderer). */
    private function localizedAttribute(array $attributes, string $key, string $locale): mixed
    {
        if (array_key_exists($key . '_' . $locale, $attributes)) {
            return $attributes[$key . '_' . $locale];
        }

        return $attributes[$key] ?? '';
    }

    /**
     * Rich-text attribute values may carry the editor's inline HTML (b/i/strong/a/…, see
     * BlockRenderer::RICH_TEXT_ALLOWED). Extracts `<a href>` targets before stripping all markup
     * down to plain text, so link analysis sees them even though the returned text doesn't.
     *
     * @return array{0:string,1:list<string>}
     */
    private function richText(mixed $value): array
    {
        $html = (string) $value;
        $links = [];
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/i', $html, $m) > 0) {
            $links = $m[2];
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return [$text, $links];
    }

    /** Classifies + appends each href onto $acc['links']; mailto:/tel: and empty hrefs are skipped. */
    private function collectLinks(array $hrefs, array &$acc): void
    {
        foreach ($hrefs as $href) {
            $href = trim((string) $href);
            if ($href === '' || str_starts_with($href, '#')) {
                continue; // empty or same-page anchor -- not a meaningful link/outbound signal
            }

            $scheme = parse_url($href, PHP_URL_SCHEME);
            if ($scheme !== null && ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                continue; // mailto:/tel:/etc -- not a webpage link
            }

            $acc['links'][] = ['href' => $href, 'internal' => $this->isInternalLink($href)];
        }
    }

    /** Relative/scheme-relative -> internal; absolute http(s) -> internal only when same host as `app.url`. */
    private function isInternalLink(string $href): bool
    {
        $scheme = parse_url($href, PHP_URL_SCHEME);
        if ($scheme === null) {
            return true;
        }

        $host = parse_url($href, PHP_URL_HOST);
        if ($host === null) {
            return true;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($appHost) && $appHost !== '' && strcasecmp($host, $appHost) === 0;
    }

    // ── Shared helpers ───────────────────────────────────────────────────

    /** @return list<string> */
    private function words(string $text): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: [], static fn (string $w): bool => $w !== ''));
    }

    /** Case/diacritic-insensitive "does $haystack contain $needle" (folds via Str::ascii()). */
    private function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return str_contains($this->normalize($haystack), $this->normalize($needle));
    }

    /** Whole-word(s) occurrence count of $needle within $haystack, same normalization as contains(). */
    private function countOccurrences(string $haystack, string $needle): int
    {
        $needle = trim($this->normalize($needle));
        if ($needle === '') {
            return 0;
        }

        $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';

        return preg_match_all($pattern, $this->normalize($haystack)) ?: 0;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Str::ascii($value), 'UTF-8');
    }

    private function check(string $id, string $status, string $message, array $params = []): array
    {
        [$group, $weight] = self::WEIGHTS[$id];

        return [
            'id' => $id,
            'group' => $group,
            'status' => $status,
            'weight' => $weight,
            'message' => $message,
            'message_key' => 'heisenberg::seo.checks.' . $id . '.' . $status,
            'params' => $params,
        ];
    }

    /** A keyphrase-dependent check when no keyphrase is set: warn, HALF weight, uniform message. */
    private function notApplicable(string $id): array
    {
        $result = $this->check($id, 'warn', 'Set a focus keyphrase to unlock this check.');
        $result['weight'] = $result['weight'] / 2;

        return $result;
    }

    /** @param list<array<string,mixed>> $checks */
    private function score(array $checks): int
    {
        $totalWeight = array_sum(array_column($checks, 'weight'));
        if ($totalWeight <= 0) {
            return 0;
        }

        $achieved = array_sum(array_map(static function (array $c): float {
            $factor = match ($c['status']) {
                'pass' => 1.0,
                'warn' => 0.5,
                default => 0.0,
            };

            return $c['weight'] * $factor;
        }, $checks));

        return (int) round($achieved / $totalWeight * 100);
    }

    private function rating(int $score): string
    {
        return match (true) {
            $score < 45 => 'poor',
            $score < 65 => 'needs-work',
            $score < 85 => 'good',
            default => 'excellent',
        };
    }
}
