<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\FontCatalogService;
use Heisenberg\Tests\TestCase;

/**
 * Acceptance tests for GET /editor/fonts (FontController::search) — the offset/has_more
 * pagination wiring added alongside FontCatalogService's own offset support (2026-08-03).
 * The real vendored catalog is ~1942 entries; rebinds FontCatalogService onto a small fixture
 * file (like SavedThemeControllerTest isolates its own file-backed repository) so `has_more`
 * assertions don't depend on the exact shipped font count.
 */
class FontControllerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-fonts-http-' . uniqid('', true) . '.json';
        file_put_contents($this->path, json_encode([
            ['f' => 'Alpha Sans', 'c' => 'sans-serif', 'w' => [400]],
            ['f' => 'Bravo Sans', 'c' => 'sans-serif', 'w' => [400]],
            ['f' => 'Charlie Sans', 'c' => 'sans-serif', 'w' => [400]],
        ]));
        $this->app->singleton(FontCatalogService::class, fn () => new FontCatalogService($this->path));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_first_page_reports_has_more_when_a_further_page_exists(): void
    {
        $response = $this->getJson('/editor/fonts?limit=2&offset=0');

        $response->assertOk();
        $this->assertSame(['Alpha Sans', 'Bravo Sans'], array_column($response->json('fonts'), 'family'));
        $this->assertTrue($response->json('has_more'));
        $this->assertSame(3, $response->json('total'));
    }

    public function test_second_page_continues_from_the_offset_and_reports_no_further_page(): void
    {
        $response = $this->getJson('/editor/fonts?limit=2&offset=2');

        $response->assertOk();
        $this->assertSame(['Charlie Sans'], array_column($response->json('fonts'), 'family'));
        $this->assertFalse($response->json('has_more'));
    }

    public function test_negative_offset_is_clamped_to_zero(): void
    {
        $response = $this->getJson('/editor/fonts?limit=2&offset=-5');

        $response->assertOk();
        $this->assertSame(['Alpha Sans', 'Bravo Sans'], array_column($response->json('fonts'), 'family'));
    }
}
