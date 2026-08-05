<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

/**
 * Supplies icon SVGs and blade component names, decoupling the renderer and the
 * editor picker from the `mallardduck/blade-lucide-icons` vendor package. A host
 * without Lucide can bind its own provider (e.g. Heroicons). (Blueprint §4.7, §13.5)
 */
interface IconProvider
{
    /**
     * Traversal-safe SVG lookup by slug; null when unknown.
     */
    public function svg(string $name): ?string;

    /**
     * Icon slugs available for the editor picker.
     *
     * @return string[]
     */
    public function available(): array;

    /**
     * The blade component name for an icon, e.g. "lucide-arrow-up-right".
     */
    public function bladeComponent(string $name): string;
}
