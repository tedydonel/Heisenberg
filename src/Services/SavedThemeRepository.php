<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * The user's named theme library — the Themes tab's "Save to Themes" backend
 * (panel-style-themes.blade.php, 2026-08-03). Distinct from {@see ThemeRepository},
 * which owns the single *active* theme: this stores any number of full, named
 * snapshots of that same token shape (colors/fontSizes/spaces/radii/fonts), so a
 * user can capture a look and re-apply it later without losing the others.
 *
 * Each snapshot is re-validated through ThemeRepository::validate() — a saved
 * theme must be exactly as trustworthy as the active one, since applying it
 * (client-side) makes it the active one.
 *
 * Stored as one JSON file (config: heisenberg.saved_themes_path, default
 * storage/app/heisenberg/themes.json): a flat list of {name, theme}, ordered by
 * save time. Names are deduplicated case-insensitively — saving under an
 * existing name overwrites that entry in place rather than growing the list.
 */
class SavedThemeRepository
{
    public const MAX_NAME_LENGTH = 60;

    public function __construct(private ThemeRepository $themes, private ?string $path = null)
    {
        $this->path = $path
            ?? config('heisenberg.saved_themes_path')
            ?? storage_path('app/heisenberg/themes.json');
    }

    /** @return list<array{name: string, theme: array<string, mixed>}> */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry['name'], $entry['theme']) || ! is_array($entry['theme'])) {
                continue;
            }
            $list[] = ['name' => (string) $entry['name'], 'theme' => $entry['theme']];
        }

        return $list;
    }

    /**
     * Validate + upsert (case-insensitive name match) + persist.
     *
     * @param array<string, mixed> $theme
     * @return array{saved: bool, errors: string[], name: string, themes: list<array{name: string, theme: array<string, mixed>}>}
     */
    public function save(string $name, array $theme): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return ['saved' => false, 'errors' => ['Name must be 1-' . self::MAX_NAME_LENGTH . ' characters'], 'name' => $name, 'themes' => $this->all()];
        }

        $checked = $this->themes->validate($theme);
        if (! $checked['valid']) {
            return ['saved' => false, 'errors' => $checked['errors'], 'name' => $name, 'themes' => $this->all()];
        }

        $list = array_values(array_filter(
            $this->all(),
            static fn (array $entry): bool => mb_strtolower($entry['name']) !== mb_strtolower($name)
        ));
        $list[] = ['name' => $name, 'theme' => $checked['theme']];
        $this->persist($list);

        return ['saved' => true, 'errors' => [], 'name' => $name, 'themes' => $list];
    }

    /** @return list<array{name: string, theme: array<string, mixed>}> the remaining list */
    public function delete(string $name): array
    {
        $list = array_values(array_filter(
            $this->all(),
            static fn (array $entry): bool => mb_strtolower($entry['name']) !== mb_strtolower(trim($name))
        ));
        $this->persist($list);

        return $list;
    }

    /** @param list<array{name: string, theme: array<string, mixed>}> $list */
    private function persist(array $list): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $this->path,
            json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
