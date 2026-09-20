<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\ThemeRepository;

/** `get_theme` — the active theme's design tokens, as the CSS custom properties authored content should reference. */
final class ThemeTools implements McpToolProvider
{
    public function __construct(private ThemeRepository $themes)
    {
    }

    public function definitions(): array
    {
        return [
            'get_theme' => [
                'description' => 'Read the active theme\'s design tokens as CSS custom properties (colors, font sizes, spacing, radii, fonts) under the --hb-t- namespace, with their current values. Use these variable names in authored content (e.g. var(--hb-t-accent-1) as a color/style value) instead of hardcoded values, so content honors the site theme.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([]),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return $tool === 'get_theme';
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        $theme = $this->themes->load();
        $prefix = ThemeRepository::CSS_PREFIX;
        $variables = [];

        foreach (['colors', 'fontSizes', 'spaces', 'radii'] as $group) {
            foreach ($theme[$group] ?? [] as $token) {
                $variables["--{$prefix}{$token['name']}"] = (string) $token['value'];
            }
        }
        foreach ($theme['fonts'] ?? [] as $token) {
            $family = (string) $token['family'];
            $quoted = str_contains($family, ' ') ? "'{$family}'" : $family;
            $variables["--{$prefix}{$token['name']}"] = "{$quoted}, sans-serif";
        }

        return ['variables' => $variables, 'groups' => $theme];
    }
}
