<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\FontCatalogService;
use Heisenberg\Tests\TestCase;

/**
 * FontCatalogService::search()'s offset/limit pagination (2026-08-03) — the Fonts picker
 * (panel-style-themes.blade.php's ui/combobox) pages through the catalog as the user scrolls
 * rather than being capped at a single `limit`-sized page until a new query is typed. The real
 * vendored catalog (resources/fonts/google-fonts.json) has ~1942 entries and an evolving
 * POPULAR ranking, so these tests build a small fixture catalog instead — deterministic
 * coverage of "does paging return a stable, gap-free, duplicate-free slice of the SAME
 * ordering" without depending on the exact shipped font list or its ranking.
 */
class FontCatalogServiceTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /** @param list<array{f: string, c: string, w: list<int>}> $entries */
    private function service(array $entries): FontCatalogService
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-fonts-' . uniqid('', true) . '.json';
        file_put_contents($this->path, json_encode($entries));

        return new FontCatalogService($this->path);
    }

    private static function entry(string $family): array
    {
        return ['f' => $family, 'c' => 'sans-serif', 'w' => [400]];
    }

    public function test_empty_query_pages_through_the_full_catalog_without_gaps_or_duplicates(): void
    {
        // None of these are in the curated POPULAR list, so popularHead() falls entirely into
        // its "$rest" branch (catalog order) — the simplest case to assert stable slicing on.
        $families = ['Zeta Sans', 'Yankee Serif', 'Xray Mono', 'Whiskey Display', 'Victor Slab'];
        $service = $this->service(array_map(self::entry(...), $families));

        $page1 = array_column($service->search('', 2, 0), 'family');
        $page2 = array_column($service->search('', 2, 2), 'family');
        $page3 = array_column($service->search('', 2, 4), 'family');

        $this->assertSame($families, [...$page1, ...$page2, ...$page3]);
    }

    public function test_empty_query_offset_beyond_total_returns_empty(): void
    {
        $service = $this->service([self::entry('Only Family')]);

        $this->assertSame([], $service->search('', 40, 5));
    }

    public function test_curated_families_are_ranked_ahead_of_uncurated_ones_across_pages(): void
    {
        // 'Roboto' is in the real POPULAR const; the other two are not, so this exercises the
        // curated-then-rest merge (not just the all-uncurated case above).
        $service = $this->service([
            self::entry('Aardvark Sans'),
            self::entry('Zamboni Slab'),
            self::entry('Roboto'),
        ]);

        $page1 = array_column($service->search('', 1, 0), 'family');
        $page2 = array_column($service->search('', 2, 1), 'family');

        $this->assertSame(['Roboto'], $page1);
        $this->assertSame(['Aardvark Sans', 'Zamboni Slab'], $page2);
    }

    public function test_query_search_pages_through_matches_in_a_stable_order(): void
    {
        $families = ['Bar Prefix One', 'Bar Prefix Two', 'Something Bar Substring'];
        $service = $this->service(array_map(self::entry(...), $families));

        $page1 = array_column($service->search('bar', 2, 0), 'family');
        $page2 = array_column($service->search('bar', 2, 2), 'family');

        // Prefix matches ('Bar Prefix …') rank ahead of substring matches ('Something Bar …'),
        // and that ranking must hold steady across the offset boundary between pages.
        $this->assertSame(['Bar Prefix One', 'Bar Prefix Two'], $page1);
        $this->assertSame(['Something Bar Substring'], $page2);
    }

    public function test_query_search_offset_beyond_matches_returns_empty(): void
    {
        $service = $this->service([self::entry('Bar Prefix One')]);

        $this->assertSame([], $service->search('bar', 40, 5));
    }
}
