<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\SavedThemeRepository;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Themes panel's two halves, and the line between them.
 *
 * PRESETS are shipped code: three starting points, nothing more. Clicking one seeds the colour
 * swatches — it never makes the author's theme "a preset".
 *
 * A SAVED THEME is the author's own: {@see SavedThemeRepository} writes a full, validated copy of
 * every token, so it owes nothing to whichever preset happened to seed it and survives any change
 * to the shipped list. The panel used to leave the preset card highlighted after a save, which
 * read as "this IS still that preset" even though the stored data was already independent.
 */
class ThemePresetsTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    public function test_exactly_three_presets_ship(): void
    {
        $html = $this->editorHtml();

        $this->assertElementCount($html, '[data-hb-theme-preset]', 3);
        foreach (['Default', 'Midnight', 'Sunset'] as $label) {
            $this->assertStringContainsString('>' . $label . '</span>', $html);
        }
        // The four that were dropped must not linger anywhere on the page.
        foreach (['Ocean', 'Forest', 'Blush'] as $gone) {
            $this->assertStringNotContainsString('>' . $gone . '</span>', $html);
        }
    }

    /** Saving under a name deselects every preset: the author's own card is the active one. */
    public function test_saving_under_a_name_detaches_from_the_preset(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, 'const clearPresetSelection = () => {');
        $this->assertInlineScriptMatches(
            $html,
            "/root\.__hbPanelStyle\.activeSavedTheme = \{ name \};\s*(\/\/[^\n]*\n\s*)*clearPresetSelection\(\);/",
        );
    }

    /**
     * The author's themes are DATA, not code: a saved theme is a complete token set that keeps
     * working whatever happens to the shipped presets.
     */
    public function test_a_saved_theme_stores_the_whole_token_set_independently(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-saved-themes-' . uniqid('', true) . '.json';
        config()->set('heisenberg.saved_themes_path', $path);

        try {
            $repo = $this->app->make(SavedThemeRepository::class);
            $theme = $this->app->make(ThemeRepository::class)->defaults();
            $theme['colors'][0]['value'] = '#123456';

            $result = $repo->save('My own theme', $theme);
            $this->assertTrue($result['saved'], implode('; ', $result['errors']));

            $stored = collect($this->app->make(SavedThemeRepository::class)->all())->firstWhere('name', 'My own theme');
            $this->assertIsArray($stored);
            $this->assertSame('#123456', $stored['theme']['colors'][0]['value']);
            // Every section, not a reference to something shipped.
            foreach (['colors', 'fontSizes', 'spaces', 'radii', 'fonts'] as $section) {
                $this->assertNotSame([], $stored['theme'][$section], "{$section} is missing from the stored copy");
            }
            $this->assertArrayNotHasKey('preset', $stored);
        } finally {
            @unlink($path);
        }
    }
}
