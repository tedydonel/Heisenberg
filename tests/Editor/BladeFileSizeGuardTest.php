<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use FilesystemIterator;
use Heisenberg\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression guard for the /editor 500: Livewire's SupportMorphAwareBladeCompilation runs a
 * single preg_match()/preg_replace() over each COMPILED view, and PCRE's compiled-pattern size
 * limit gets tripped once a view is big enough. resources/views/components/live/inspector.blade.php
 * hit that limit at 202,393 bytes ("preg_match(): Compilation failed: regular expression is too
 * large"), 500ing every /editor/{id} load in a real host app. The package's own test suite never
 * caught it — rendering /editor via testbench doesn't exercise the same Blade-cache/Livewire pass
 * a fresh host install does — so this test exists purely as a static size guard, independent of
 * whether anything actually renders.
 *
 * The threshold (65536 bytes = 64 KiB) is a conservative ceiling well under where the crash was
 * observed, not the exact PCRE limit itself (that varies by php.ini pcre.* settings) — any single
 * blade file anywhere near inspector.blade.php's old size is a latent instance of this same bug.
 *
 * KNOWN_OVERSIZED lists files that already exceeded the threshold before this guard was added and
 * were out of scope for the inspector.blade.php split this test was written alongside. They are
 * pre-existing conditions, not something this guard is meant to newly flag — remove an entry once
 * that file is split.
 */
class BladeFileSizeGuardTest extends TestCase
{
    private const MAX_BYTES = 65536;

    /**
     * @var array<int, string> paths relative to resources/views, forward-slash separated
     */
    private const KNOWN_OVERSIZED = [
        // 96,910 bytes as of this guard's introduction. Not part of the inspector.blade.php split
        // this test was added alongside; flagged here rather than left to silently regress the
        // whole suite. Needs its own decomposition pass before this exception can be removed.
        'components/live/block-runtime.blade.php',
    ];

    public function test_no_blade_view_exceeds_the_livewire_morph_compile_ceiling(): void
    {
        $viewsRoot = dirname(__DIR__, 2) . '/resources/views';
        $this->assertDirectoryExists($viewsRoot);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewsRoot) + 1));

            if (in_array($relative, self::KNOWN_OVERSIZED, true)) {
                continue;
            }

            $size = (int) $file->getSize();

            if ($size > self::MAX_BYTES) {
                $offenders[] = sprintf('%s (%d bytes)', $relative, $size);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "The following blade view(s) exceed %d bytes:\n%s\n\n" .
            "Livewire's SupportMorphAwareBladeCompilation runs a single regex over each compiled " .
            "view; once a compiled view gets large enough, PCRE's compiled-pattern size limit is " .
            "exceeded and the page 500s with \"preg_match(): Compilation failed: regular expression " .
            "is too large\" (this happened for real at resources/views/components/live/inspector.blade.php " .
            "when it hit 202,393 bytes). Split the offending file into sibling partials/components " .
            "instead of letting it grow past this ceiling.",
            self::MAX_BYTES,
            implode("\n", $offenders)
        ));
    }
}
