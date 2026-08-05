<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\IconProvider;

/**
 * Default icon provider: wraps `mallardduck/blade-lucide-icons` (an optional
 * dependency) with the traversal-safe SVG lookup from blueprint §4.7. When the
 * vendor package is absent every lookup degrades gracefully (svg() => null,
 * available() => []). A host without Lucide binds its own IconProvider. (§13.5)
 */
class LucideIconProvider implements IconProvider
{
    public function __construct(protected ?string $svgRoot = null)
    {
    }

    public function svg(string $name): ?string
    {
        $root = $this->resolveRoot();
        if ($root === null) {
            return null;
        }

        // 1. Allow-list the slug; strip any "lucide-" prefix. `..` / separators
        //    cannot survive the character class.
        $slug = $this->slug($name);
        if ($slug === null) {
            return null;
        }

        // 2. realpath() both the candidate and the root.
        $candidate = realpath($root . DIRECTORY_SEPARATOR . $slug . '.svg');
        $realRoot  = realpath($root);
        if ($candidate === false || $realRoot === false) {
            return null;
        }

        // 3. Confine to the root — the trailing separator is critical so a sibling
        //    "<root>-evil" cannot pass.
        if (! str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $svg = @file_get_contents($candidate);

        return $svg === false ? null : $svg;
    }

    public function available(): array
    {
        $root = $this->resolveRoot();
        if ($root === null || ! is_dir($root)) {
            return [];
        }

        $slugs = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . '*.svg') ?: [] as $file) {
            $base = basename($file, '.svg');
            if (preg_match('/^[a-z0-9-]+$/', $base) === 1) {
                $slugs[] = $base;
            }
        }
        sort($slugs);

        return $slugs;
    }

    public function bladeComponent(string $name): string
    {
        return 'lucide-' . ($this->slug($name) ?? '');
    }

    /**
     * Normalize an icon name to a safe slug, or null if it cannot be one.
     */
    protected function slug(string $name): ?string
    {
        $slug = preg_replace('/^lucide-/', '', strtolower(trim($name)));

        return is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1 ? $slug : null;
    }

    /**
     * The Lucide SVG directory, or null when the vendor package is not installed.
     */
    protected function resolveRoot(): ?string
    {
        if ($this->svgRoot !== null) {
            return $this->svgRoot;
        }

        if (! function_exists('base_path')) {
            return null;
        }

        $guess = base_path('vendor/mallardduck/blade-lucide-icons/resources/svg');

        return is_dir($guess) ? $guess : null;
    }
}
