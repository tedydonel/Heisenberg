<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media\Fakes;

use Heisenberg\Contracts\SvgSanitizer;

/**
 * A deliberately trivial SvgSanitizer for tests: strips `<script>` elements
 * only, so a test can assert the STORED bytes differ from the raw upload
 * (proof the sanitizer actually ran) without depending on a real sanitizing
 * library being installed.
 */
class FakeSvgSanitizer implements SvgSanitizer
{
    public function sanitize(string $svg): string
    {
        return (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
    }
}
