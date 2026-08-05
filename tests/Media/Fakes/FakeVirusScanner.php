<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media\Fakes;

use Heisenberg\Contracts\VirusScanner;

/**
 * Forces the scan result for MediaLibraryService's upload pipeline tests:
 * 'clean' (default), 'infected', 'suspicious', or 'unavailable'.
 */
class FakeVirusScanner implements VirusScanner
{
    public string $result = 'clean';

    public function scan(string $path): string
    {
        return $this->result;
    }
}
