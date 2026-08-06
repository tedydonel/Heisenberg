<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Temporary utility: dumps the rendered /editor page for the jsdom pipeline harness. */
class DumpEditorHtmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump(): void
    {
        $out = getenv('HB_DUMP_PATH');
        if (! $out) {
            $this->markTestSkipped('Set HB_DUMP_PATH to dump the rendered /editor page for the jsdom harness.');
        }

        file_put_contents($out, $this->get('/editor')->getContent());
        $this->assertFileExists($out);
    }
}
