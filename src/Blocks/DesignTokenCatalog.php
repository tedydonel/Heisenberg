<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;
use Illuminate\Support\Str;

/**
 * Design-token options for a supports token-select control, read from the
 * configured value-to-label registry (`heisenberg.tokens.<kind>`). Extracted
 * verbatim from {@see BlockRegistryService}; shared by
 * both the attribute-derived controls and the supports-derived style panels,
 * which is why it stands alone rather than living inside either deriver.
 */
final class DesignTokenCatalog
{
    /**
     * Numeric lists remain supported for compatibility and receive humanized
     * token-name labels.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(string $kind): array
    {
        $tokens = function_exists('config') ? config("heisenberg.tokens.{$kind}", []) : [];
        if (! is_array($tokens)) {
            return [];
        }

        $options = [];
        foreach ($tokens as $value => $label) {
            if (is_int($value)) {
                $value = (string) $label;
                $name = preg_replace('/^var\(--|\)$/', '', $value);
                $label = Str::headline((string) $name);
            }

            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }
}
