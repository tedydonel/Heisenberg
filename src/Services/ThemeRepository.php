<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * User theme (design tokens) storage — the Style panel's backend.
 *
 * A theme is five token lists (colors, fontSizes, spaces, radii, fonts). Each
 * token has a machine `name` (becomes the CSS custom property `--<name>`), a
 * human `label` (shown in pickers) and a `value` (validated per kind — the
 * same strict grammars the block renderers enforce). Fonts carry a `family`
 * (public font family name) and optional `weights`.
 *
 * Stored as one JSON file (config: heisenberg.theme_path, default
 * storage/app/heisenberg/theme.json) so a host without a database still
 * keeps user styling. Reads are cheap; writes re-validate everything.
 */
class ThemeRepository
{
    public function __construct(private ?string $path = null)
    {
        $this->path = $path
            ?? config('heisenberg.theme_path')
            ?? storage_path('app/heisenberg/theme.json');
    }

    /** @return array{colors: list<array<string, mixed>>, fontSizes: list<array<string, mixed>>, spaces: list<array<string, mixed>>, radii: list<array<string, mixed>>, fonts: list<array<string, mixed>>} */
    public function defaults(): array
    {
        return [
            'colors' => [
                ['name' => 'ink', 'label' => 'Ink', 'value' => '#0a0a0a'],
                ['name' => 'accent-1', 'label' => 'Accent', 'value' => '#3d68f5'],
                ['name' => 'faint', 'label' => 'Faint', 'value' => '#9a9a9a'],
                ['name' => 'paper', 'label' => 'Paper', 'value' => '#ffffff'],
            ],
            'fontSizes' => [
                ['name' => 'fs-sm', 'label' => 'Small', 'value' => '13px'],
                ['name' => 'fs-md', 'label' => 'Medium', 'value' => '14px'],
                ['name' => 'fs-lg', 'label' => 'Large', 'value' => '16px'],
                ['name' => 'fs-xl', 'label' => 'Extra large', 'value' => '20px'],
            ],
            'spaces' => [
                ['name' => 'sp-1', 'label' => 'Small', 'value' => '4px'],
                ['name' => 'sp-2', 'label' => 'Medium', 'value' => '8px'],
                ['name' => 'sp-3', 'label' => 'Large', 'value' => '16px'],
                ['name' => 'sp-4', 'label' => 'Extra large', 'value' => '24px'],
            ],
            'radii' => [
                ['name' => 'radius-xs', 'label' => 'XS', 'value' => '2px'],
                ['name' => 'radius-sm', 'label' => 'Small', 'value' => '3px'],
                ['name' => 'radius-md', 'label' => 'Medium', 'value' => '5px'],
                ['name' => 'radius-lg', 'label' => 'Large', 'value' => '8px'],
            ],
            'fonts' => [
                ['name' => 'font-sans', 'label' => 'Sans', 'family' => 'Rubik', 'weights' => [400, 500, 600, 700]],
                ['name' => 'font-serif', 'label' => 'Serif', 'family' => 'Georgia', 'weights' => [400, 700]],
                ['name' => 'font-mono', 'label' => 'Mono', 'family' => 'JetBrains Mono', 'weights' => [400, 500]],
            ],
        ];
    }

    /** @return array{colors: list<array<string, mixed>>, fontSizes: list<array<string, mixed>>, spaces: list<array<string, mixed>>, radii: list<array<string, mixed>>, fonts: list<array<string, mixed>>} */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return $this->defaults();
        }

        $raw = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($raw)) {
            return $this->defaults();
        }

        $checked = $this->validate($raw);

        return $checked['valid'] ? $checked['theme'] : $this->defaults();
    }

    /**
     * Validate + persist. Returns ['saved' => bool, 'errors' => string[]].
     *
     * @param array<string, mixed> $theme
     * @return array{saved: bool, errors: string[], theme: array<string, mixed>}
     */
    public function save(array $theme): array
    {
        $checked = $this->validate($theme);
        if (! $checked['valid']) {
            return ['saved' => false, 'errors' => $checked['errors'], 'theme' => $checked['theme']];
        }

        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $this->path,
            json_encode($checked['theme'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX, // exclusive lock: concurrent saves must not interleave/lose writes
        );

        return ['saved' => true, 'errors' => [], 'theme' => $checked['theme']];
    }

    /**
     * All user tokens live under the reserved `--hb-t-` namespace so a
     * token named "ink" or "bg" can never shadow an editor chrome
     * variable (the dark theme broke exactly that way).
     */
    public const CSS_PREFIX = 'hb-t-';

    /**
     * The theme as CSS custom properties for a `:root` block (editor canvas
     * + public preview). Values were validated on save; the grammars keep
     * them injection-safe, but everything is re-checked here anyway.
     */
    public function css(?array $theme = null): string
    {
        $theme = $theme ?? $this->load();
        $p = self::CSS_PREFIX;
        $lines = [];

        foreach ($theme['colors'] ?? [] as $token) {
            $lines[] = "  --{$p}{$token['name']}: {$token['value']};";
        }
        foreach ($theme['fontSizes'] ?? [] as $token) {
            $lines[] = "  --{$p}{$token['name']}: {$token['value']};";
        }
        foreach ($theme['spaces'] ?? [] as $token) {
            $lines[] = "  --{$p}{$token['name']}: {$token['value']};";
        }
        foreach ($theme['radii'] ?? [] as $token) {
            $lines[] = "  --{$p}{$token['name']}: {$token['value']};";
        }
        foreach ($theme['fonts'] ?? [] as $token) {
            $family = $token['family'];
            $quoted = str_contains($family, ' ') ? "'{$family}'" : $family;
            $lines[] = "  --{$p}{$token['name']}: {$quoted}, sans-serif;";
        }

        return ":root {\n" . implode("\n", $lines) . "\n}";
    }

    /**
     * Merge theme tokens over the config token registry so the inspector's
     * pickers offer the user's palette. Returns the merged tokens map.
     *
     * @return array<string, array<string, string>>
     */
    public function tokens(?array $theme = null): array
    {
        $theme = $theme ?? $this->load();
        $tokens = (array) config('heisenberg.tokens', []);

        $color = ['' => 'Default'];
        foreach ($theme['colors'] ?? [] as $token) {
            $color['var(--' . self::CSS_PREFIX . "{$token['name']})"] = $token['label'];
        }
        $tokens['color'] = $color;

        $fontSize = ['' => 'Default'];
        foreach ($theme['fontSizes'] ?? [] as $token) {
            $fontSize['var(--' . self::CSS_PREFIX . "{$token['name']})"] = $token['label'];
        }
        $tokens['fontSize'] = $fontSize;

        $space = ['' => 'None'];
        foreach ($theme['spaces'] ?? [] as $token) {
            $space['var(--' . self::CSS_PREFIX . "{$token['name']})"] = $token['label'];
        }
        $tokens['space'] = $space;

        $radius = ['' => 'Default'];
        foreach ($theme['radii'] ?? [] as $token) {
            $radius['var(--' . self::CSS_PREFIX . "{$token['name']})"] = $token['label'];
        }
        $tokens['radius'] = $radius;

        $fontFamily = ['' => 'Default'];
        foreach ($theme['fonts'] ?? [] as $token) {
            $fontFamily['var(--' . self::CSS_PREFIX . "{$token['name']})"] = $token['label'];
        }
        $tokens['fontFamily'] = $fontFamily;

        return $tokens;
    }

    /**
     * Families (with weights) that need font-face links in the editor and
     * preview. Only multi-word/public families matter — the loader skips
     * generic/system names it can't serve.
     *
     * @return list<array{family: string, weights: list<int>}>
     */
    public function fontFaces(?array $theme = null): array
    {
        $theme = $theme ?? $this->load();
        $faces = [];
        foreach ($theme['fonts'] ?? [] as $token) {
            $faces[] = [
                'family' => (string) $token['family'],
                'weights' => array_values(array_map('intval', (array) ($token['weights'] ?? [400]))),
            ];
        }

        return $faces;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{valid: bool, errors: string[], theme: array<string, mixed>}
     */
    public function validate(array $raw): array
    {
        $errors = [];
        $theme = ['colors' => [], 'fontSizes' => [], 'spaces' => [], 'radii' => [], 'fonts' => []];

        $kinds = [
            'colors' => '/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)|hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/',
            'fontSizes' => '/^\d+(\.\d+)?(px|rem|em|%|vw|vh)$/',
            'spaces' => '/^\d+(\.\d+)?(px|rem|em|%|vw|vh)$/',
            'radii' => '/^\d+(\.\d+)?(px|rem|em|%|vw|vh)$/',
        ];

        foreach (['colors', 'fontSizes', 'spaces', 'radii'] as $section) {
            $seen = [];
            foreach ((array) ($raw[$section] ?? []) as $i => $token) {
                if (! is_array($token)) {
                    $errors[] = "{$section}.{$i} must be an object";
                    continue;
                }
                $name = (string) ($token['name'] ?? '');
                $label = (string) ($token['label'] ?? $name);
                $value = trim((string) ($token['value'] ?? ''));
                if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name) !== 1) {
                    $errors[] = "{$section}.{$i}: name must be kebab-case (a-z, 0-9, -)";
                    continue;
                }
                if (isset($seen[$name])) {
                    $errors[] = "{$section}.{$i}: duplicate name '{$name}'";
                    continue;
                }
                if (preg_match($kinds[$section], $value) !== 1) {
                    $errors[] = "{$section}.{$i} ('{$name}'): invalid value '{$value}'";
                    continue;
                }
                if (mb_strlen($label) > 40) {
                    $label = mb_substr($label, 0, 40);
                }
                $seen[$name] = true;
                $theme[$section][] = ['name' => $name, 'label' => $label, 'value' => $value];
            }
        }

        $seen = [];
        foreach ((array) ($raw['fonts'] ?? []) as $i => $token) {
            if (! is_array($token)) {
                $errors[] = "fonts.{$i} must be an object";
                continue;
            }
            $name = (string) ($token['name'] ?? '');
            $label = (string) ($token['label'] ?? $name);
            $family = trim((string) ($token['family'] ?? ''));
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name) !== 1) {
                $errors[] = "fonts.{$i}: name must be kebab-case (a-z, 0-9, -)";
                continue;
            }
            if (isset($seen[$name])) {
                $errors[] = "fonts.{$i}: duplicate name '{$name}'";
                continue;
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{0,80}$/', $family) !== 1) {
                $errors[] = "fonts.{$i} ('{$name}'): invalid family '{$family}'";
                continue;
            }
            $weights = [];
            foreach ((array) ($token['weights'] ?? [400]) as $weight) {
                $weight = (int) $weight;
                if ($weight >= 100 && $weight <= 900 && $weight % 100 === 0) {
                    $weights[] = $weight;
                }
            }
            $seen[$name] = true;
            $theme['fonts'][] = [
                'name' => $name,
                'label' => mb_substr($label, 0, 40),
                'family' => $family,
                'weights' => $weights === [] ? [400] : array_values(array_unique($weights)),
            ];
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'theme' => $theme];
    }
}
