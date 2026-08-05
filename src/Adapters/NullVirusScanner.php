<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\VirusScanner;

/**
 * Default scanner: always reports the upload clean. A host without ClamAV/clamd
 * (or any scanner) still gets a working media library out of the box — wire a
 * real scanner via config('heisenberg.media.virus_scanner') for production.
 * Tests bind a fake VirusScanner directly to exercise the infected/unavailable/
 * fail-open paths (this adapter never returns anything but 'clean').
 */
class NullVirusScanner implements VirusScanner
{
    public function scan(string $path): string
    {
        return 'clean';
    }
}
