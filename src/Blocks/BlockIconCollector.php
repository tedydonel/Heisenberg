<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;

/**
 * Collects the distinct Lucide icon slugs referenced by a set of contracts —
 * a contract's own top-level `icon`, plus any `data-lucide` attribute in its
 * render template (recursing `children`). Extracted verbatim from
 * {@see BlockRegistryService}.
 */
final class BlockIconCollector
{
    /** @return string[] sorted, distinct Lucide slugs referenced by the contracts */
    public function referencedIcons(array $blocks): array
    {
        $icons = [];
        foreach ($blocks as $contract) {
            if (isset($contract['icon']) && is_string($contract['icon'])) {
                $icons[] = $contract['icon'];
            }
            if (isset($contract['render']['template']) && is_array($contract['render']['template'])) {
                $this->collectLucide($contract['render']['template'], $icons);
            }
        }
        $icons = array_values(array_unique($icons));
        sort($icons);

        return $icons;
    }

    /** @param string[] $icons */
    private function collectLucide(array $node, array &$icons): void
    {
        $lucide = $node['attributes']['data-lucide'] ?? null;
        if (is_string($lucide) && preg_match('/^[a-z0-9-]+$/', $lucide) === 1) {
            $icons[] = $lucide;
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $this->collectLucide($child, $icons);
                }
            }
        }
    }
}
