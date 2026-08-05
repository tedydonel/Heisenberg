<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\SavedThemeRepository;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Tests\TestCase;

/**
 * The Themes tab's "Save to Themes" backend (2026-08-03) — a named library of full
 * theme snapshots, separate from ThemeRepository's single active theme. Every
 * snapshot is re-validated through ThemeRepository::validate() on the way in.
 */
class SavedThemeRepositoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-saved-themes-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function repo(): SavedThemeRepository
    {
        return new SavedThemeRepository(new ThemeRepository(), $this->path);
    }

    private function theme(): array
    {
        return (new ThemeRepository())->defaults();
    }

    public function test_all_is_empty_when_nothing_has_been_saved_yet(): void
    {
        $this->assertSame([], $this->repo()->all());
    }

    public function test_save_then_all_round_trips_a_named_theme(): void
    {
        $result = $this->repo()->save('My Brand', $this->theme());

        $this->assertTrue($result['saved'], implode(', ', $result['errors']));
        $this->assertSame('My Brand', $result['name']);

        $all = $this->repo()->all();
        $this->assertCount(1, $all);
        $this->assertSame('My Brand', $all[0]['name']);
        $this->assertSame($this->theme(), $all[0]['theme']);
    }

    public function test_saving_under_an_existing_name_overwrites_it_in_place_case_insensitively(): void
    {
        $this->repo()->save('Dark Mode', $this->theme());

        $second = $this->theme();
        $second['colors'][0]['value'] = '#123456';
        $result = $this->repo()->save('dark mode', $second);

        $this->assertTrue($result['saved']);
        $all = $this->repo()->all();
        $this->assertCount(1, $all, 'saving under an existing name (any case) must overwrite, not duplicate');
        $this->assertSame('dark mode', $all[0]['name'], 'the most recent save wins for casing too');
        $this->assertSame('#123456', $all[0]['theme']['colors'][0]['value']);
    }

    public function test_empty_name_is_rejected(): void
    {
        $result = $this->repo()->save('   ', $this->theme());

        $this->assertFalse($result['saved']);
        $this->assertNotSame([], $result['errors']);
        $this->assertSame([], $this->repo()->all());
    }

    public function test_name_over_max_length_is_rejected(): void
    {
        $result = $this->repo()->save(str_repeat('x', SavedThemeRepository::MAX_NAME_LENGTH + 1), $this->theme());

        $this->assertFalse($result['saved']);
    }

    public function test_an_invalid_theme_payload_is_rejected_and_nothing_is_saved(): void
    {
        $theme = $this->theme();
        $theme['colors'][0]['value'] = 'not-a-color';

        $result = $this->repo()->save('Broken', $theme);

        $this->assertFalse($result['saved']);
        $this->assertNotSame([], $result['errors']);
        $this->assertSame([], $this->repo()->all());
    }

    public function test_delete_removes_a_theme_case_insensitively(): void
    {
        $this->repo()->save('Ocean', $this->theme());
        $this->repo()->save('Forest', $this->theme());

        $remaining = $this->repo()->delete('OCEAN');

        $this->assertCount(1, $remaining);
        $this->assertSame('Forest', $remaining[0]['name']);
        $this->assertCount(1, $this->repo()->all());
    }

    public function test_deleting_an_unknown_name_is_a_harmless_no_op(): void
    {
        $this->repo()->save('Ocean', $this->theme());

        $remaining = $this->repo()->delete('Does Not Exist');

        $this->assertCount(1, $remaining);
    }

    public function test_a_corrupt_library_file_degrades_to_an_empty_list(): void
    {
        file_put_contents($this->path, 'not json');

        $this->assertSame([], $this->repo()->all());
    }
}
