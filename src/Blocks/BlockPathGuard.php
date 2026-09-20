<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;

/**
 * Resolves the block-contract root directory and confines any candidate file
 * to it (SECURITY-CRITICAL — path traversal / symlink escape guard, named in
 * SECURITY.md). Extracted verbatim from {@see BlockRegistryService}
 * so the realpath-confinement logic has exactly one owner; both the registry's
 * directory scan and its public {@see self::validatePath()} share this single
 * root resolution and confinement check.
 */
final class BlockPathGuard
{
    public function __construct(
        private ?string $blockRootPath = null,
    ) {
    }

    public function rootPath(): string
    {
        if ($this->blockRootPath !== null) {
            return $this->blockRootPath;
        }

        $configured = function_exists('config') ? config('heisenberg.block_root') : null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return __DIR__ . '/../../resources/blocks';
    }

    /** Path-traversal guard: realpath-confine a candidate file to the block root. */
    public function validatePath(string $path): bool
    {
        $root = realpath($this->rootPath());
        $real = realpath($path);

        if ($root === false || $real === false) {
            return false;
        }

        return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }
}
