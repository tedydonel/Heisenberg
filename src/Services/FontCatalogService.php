<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * The public font catalog behind the font pickers — a vendored index of
 * every Google Fonts family (resources/fonts/google-fonts.json: family,
 * category, weights). Search is a simple ranked substring match; the
 * editor loads actual font faces on demand via fonts.googleapis.com.
 */
class FontCatalogService
{
    /**
     * Curated popularity head for the empty-query listing. The vendored
     * catalog is alphabetical, so without a rank the picker's default page
     * was just the "A" fonts (ABeeZee, Abel, …) — the bug users read as
     * "fonts don't load". Order approximates Google Fonts trending.
     *
     * @var list<string>
     */
    private const POPULAR = [
        'Roboto', 'Open Sans', 'Inter', 'Montserrat', 'Lato', 'Poppins',
        'Rubik', 'Oswald', 'Raleway', 'Nunito', 'Playfair Display',
        'Merriweather', 'Ubuntu', 'Work Sans', 'PT Sans', 'Noto Sans',
        'DM Sans', 'Manrope', 'Fira Sans', 'Quicksand', 'Mulish', 'Barlow',
        'Karla', 'Josefin Sans', 'Space Grotesk', 'Outfit', 'Sora',
        'Plus Jakarta Sans', 'Lora', 'EB Garamond', 'Cormorant Garamond',
        'Libre Baskerville', 'Crimson Text', 'Bitter', 'Source Serif 4',
        'IBM Plex Sans', 'IBM Plex Serif', 'IBM Plex Mono', 'JetBrains Mono',
        'Space Mono', 'Inconsolata', 'Fira Code', 'Bebas Neue', 'Anton',
        'Archivo', 'Cabin', 'Heebo', 'Kanit', 'Exo 2', 'Teko',
        'Titillium Web', 'Zilla Slab', 'Arvo', 'Domine', 'Abril Fatface',
        'Pacifico', 'Lobster', 'Caveat', 'Dancing Script', 'Satisfy',
        'Amatic SC', 'Shadows Into Light', 'Righteous', 'Comfortaa',
        'Fredoka', 'Baloo 2', 'Varela Round', 'Signika', 'Overpass',
        'Chivo', 'Asap', 'Catamaran', 'Assistant', 'Cairo', 'Hind',
        'Nanum Gothic', 'Orbitron', 'Press Start 2P',
    ];

    /** @var list<array{f: string, c: string, w: list<int>}>|null */
    private ?array $catalog = null;

    /** @var array<string, int>|null lowercase family -> popularity rank */
    private ?array $popularRank = null;

    public function __construct(private ?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . '/resources/fonts/google-fonts.json';
    }

    /** @return list<array{f: string, c: string, w: list<int>}> */
    public function all(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $raw = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;

        return $this->catalog = is_array($raw) ? $raw : [];
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Ranked, paginated search: prefix matches first, then substring matches, both
     * alphabetical. An empty query returns the curated popular head (falling back to the
     * catalog's own order for anything not in the curated list). `$offset` pages through
     * the SAME ranked ordering rather than re-ranking per page, so scrolling a picker past
     * page 1 (see ui/combobox's `loadmore` event) never reshuffles what's already loaded —
     * offset 0..limit-1 is always the same slice offset limit..2*limit-1 continues from.
     *
     * @return list<array{family: string, category: string, weights: list<int>}>
     */
    public function search(string $query, int $limit = 40, int $offset = 0): array
    {
        $query = mb_strtolower(trim($query));
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        if ($query === '') {
            $hits = $this->popularHead($limit, $offset);
        } else {
            $prefix = [];
            $substring = [];
            foreach ($this->all() as $entry) {
                $family = mb_strtolower($entry['f']);
                if (str_starts_with($family, $query)) {
                    $prefix[] = $entry;
                } elseif (str_contains($family, $query)) {
                    $substring[] = $entry;
                }
            }
            $hits = array_slice(array_merge($prefix, $substring), $offset, $limit);
        }

        return array_map(static fn (array $entry): array => [
            'family' => $entry['f'],
            'category' => $entry['c'],
            'weights' => $entry['w'],
        ], $hits);
    }

    /**
     * The curated-popularity ordering of the WHOLE catalog: every curated family first (in
     * curated order), then everything else in catalog order — sliced by offset/limit so a
     * caller paging through it (offset 0, then limit, then 2*limit, …) sees a single stable
     * ranking instead of each page independently re-deciding its own "rest" cutoff.
     *
     * @return list<array{f: string, c: string, w: list<int>}>
     */
    private function popularHead(int $limit, int $offset): array
    {
        if ($this->popularRank === null) {
            $this->popularRank = [];
            foreach (self::POPULAR as $i => $family) {
                $this->popularRank[mb_strtolower($family)] = $i;
            }
        }

        $curated = [];
        $rest = [];
        foreach ($this->all() as $entry) {
            $rank = $this->popularRank[mb_strtolower($entry['f'])] ?? null;
            if ($rank !== null) {
                $curated[$rank] = $entry;
            } else {
                $rest[] = $entry;
            }
        }
        ksort($curated);

        return array_slice(array_merge(array_values($curated), $rest), $offset, $limit);
    }

    /** Whether a family exists in the catalog (exact, case-insensitive). */
    public function has(string $family): bool
    {
        $needle = mb_strtolower(trim($family));
        foreach ($this->all() as $entry) {
            if (mb_strtolower($entry['f']) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fonts.googleapis.com css2 URL covering the given faces (family +
     * weights). Families not in the catalog (system fonts) are skipped.
     *
     * @param list<array{family: string, weights: list<int>}> $faces
     */
    public function css2Url(array $faces): ?string
    {
        $parts = [];
        foreach ($faces as $face) {
            $family = trim((string) ($face['family'] ?? ''));
            if ($family === '' || ! $this->has($family)) {
                continue;
            }
            $weights = array_values(array_filter(array_map('intval', (array) ($face['weights'] ?? [400]))));
            sort($weights);
            $spec = 'family=' . str_replace(' ', '+', $family);
            if ($weights !== [] && $weights !== [400]) {
                $spec .= ':wght@' . implode(';', $weights);
            }
            $parts[] = $spec;
        }

        if ($parts === []) {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
    }
}
