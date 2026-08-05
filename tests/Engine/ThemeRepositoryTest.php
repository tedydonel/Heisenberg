<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\ThemeRepository;
use Heisenberg\Tests\TestCase;

/**
 * The Style/Themes panel's backend (panel-style-themes.blade.php, 2026-08-03) — five token
 * lists (colors, fontSizes, spaces, radii, fonts), validated per kind and persisted as one
 * JSON file. `radii` is the newest section: it mirrors `spaces` (same length-unit grammar)
 * since the panel always shipped a Radius section but the repository never backed it.
 */
class ThemeRepositoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-theme-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function repo(): ThemeRepository
    {
        return new ThemeRepository($this->path);
    }

    public function test_load_returns_defaults_when_nothing_is_saved_yet(): void
    {
        $theme = $this->repo()->load();

        $this->assertNotSame([], $theme['radii']);
        $this->assertSame($this->repo()->defaults(), $theme);
    }

    public function test_defaults_include_all_five_token_sections(): void
    {
        $defaults = $this->repo()->defaults();

        foreach (['colors', 'fontSizes', 'spaces', 'radii', 'fonts'] as $section) {
            $this->assertArrayHasKey($section, $defaults);
            $this->assertNotSame([], $defaults[$section]);
        }
    }

    public function test_save_then_load_round_trips_a_radius_token(): void
    {
        $theme = $this->repo()->defaults();
        $theme['radii'][] = ['name' => 'radius-pill', 'label' => 'Pill', 'value' => '999px'];

        $result = $this->repo()->save($theme);
        $this->assertTrue($result['saved'], implode(', ', $result['errors']));

        $loaded = $this->repo()->load();
        $names = array_column($loaded['radii'], 'name');
        $this->assertContains('radius-pill', $names);
    }

    public function test_invalid_radius_value_is_rejected_and_nothing_is_written(): void
    {
        $theme = $this->repo()->defaults();
        $theme['radii'][] = ['name' => 'radius-bad', 'label' => 'Bad', 'value' => 'not-a-length'];

        $result = $this->repo()->save($theme);

        $this->assertFalse($result['saved']);
        $this->assertNotSame([], $result['errors']);
        $this->assertFileDoesNotExist($this->path);
    }

    public function test_duplicate_radius_name_is_rejected(): void
    {
        $theme = $this->repo()->defaults();
        $theme['radii'][] = ['name' => 'radius-sm', 'label' => 'Dup', 'value' => '4px'];

        $result = $this->repo()->save($theme);

        $this->assertFalse($result['saved']);
        $this->assertNotSame([], $result['errors']);
    }

    public function test_non_kebab_radius_name_is_rejected(): void
    {
        $theme = $this->repo()->defaults();
        $theme['radii'][] = ['name' => 'Radius XL', 'label' => 'XL', 'value' => '12px'];

        $result = $this->repo()->save($theme);

        $this->assertFalse($result['saved']);
    }

    public function test_css_emits_a_custom_property_per_radius_token(): void
    {
        $css = $this->repo()->css($this->repo()->defaults());

        $this->assertStringContainsString('--hb-t-radius-sm: 3px;', $css);
        $this->assertStringContainsString('--hb-t-radius-lg: 8px;', $css);
    }

    public function test_tokens_exposes_a_radius_picker_map_keyed_by_css_variable(): void
    {
        $tokens = $this->repo()->tokens($this->repo()->defaults());

        $this->assertArrayHasKey('radius', $tokens);
        $this->assertSame('Small', $tokens['radius']['var(--hb-t-radius-sm)']);
    }

    public function test_a_corrupt_theme_file_falls_back_to_defaults_instead_of_erroring(): void
    {
        file_put_contents($this->path, 'not json at all');

        $this->assertSame($this->repo()->defaults(), $this->repo()->load());
    }
}
