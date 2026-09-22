<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\TestCase;

/**
 * GET /editor/icons — the icon picker's feed.
 *
 * The picker used to render one `<img src>` per result, so a page of 96 icons was 97 requests,
 * each booting the framework to hand back a ~500-byte file: the grid took ~37s to actually paint
 * against the dev server. The feed now carries each icon's markup so the grid paints from the one
 * request it already makes, and `url` stays for the canvas runtime (which fetches a single icon
 * and caches it for a year) and as the fallback for any file inlineSvg() refuses.
 *
 * Rebinds the library onto a fixture directory rather than asserting against the ~29k shipped
 * icons, so these stay true whatever the vendored library contains — including the hostile files
 * a host could point `heisenberg.icon_root` at, which no shipped icon exercises.
 */
class IconSearchFeedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-icons-' . uniqid('', true);
        mkdir($this->root . '/demo', 0775, true);

        $icons = [
            'star' => '<svg fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5 5 2 8-7-4-7 4 2-8-5-5h7z"/></svg>',
            // Fixed-colour sets exist (iconsax, remix-icon): still inlined, but the picker marks
            // them so the dark theme keeps inverting them as it did for <img>.
            'fixed' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#292D32" d="M4 4h16v16H4z"/></svg>',
            // Must NOT be inlined: inline markup runs what an <img> would have isolated.
            'scripted' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg>',
            'handler' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path onload="alert(1)" d="M0 0h24v24H0z"/></svg>',
            'remote' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><image href="https://evil.test/x.png"/></svg>',
        ];
        foreach ($icons as $slug => $markup) {
            file_put_contents($this->root . '/demo/' . $slug . '.svg', $markup);
        }
        file_put_contents($this->root . '/manifest.json', json_encode([
            'sets' => ['demo' => ['icons' => array_keys($icons)]],
            'total' => count($icons),
        ]));

        config()->set('heisenberg.icon_root', $this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/demo/*.svg') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->root . '/manifest.json');
        @rmdir($this->root . '/demo');
        @rmdir($this->root);

        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> reference => row */
    private function rows(): array
    {
        $body = $this->get('/editor/icons?limit=50')->assertOk()->json();

        return collect($body['icons'])->keyBy('reference')->all();
    }

    public function test_the_feed_carries_each_icon_s_markup_so_the_grid_paints_in_one_request(): void
    {
        $rows = $this->rows();

        $this->assertArrayHasKey('demo/star', $rows);
        $this->assertStringStartsWith('<svg', (string) $rows['demo/star']['svg']);
        $this->assertStringContainsString('currentColor', (string) $rows['demo/star']['svg']);
        // The per-icon asset URL survives: the canvas runtime still fetch-injects single icons.
        $this->assertStringContainsString('/heisenberg-assets/icon/demo/star.svg', (string) $rows['demo/star']['url']);
    }

    public function test_a_fixed_colour_icon_is_still_inlined(): void
    {
        $this->assertStringContainsString('#292D32', (string) $this->rows()['demo/fixed']['svg']);
    }

    /**
     * Fail-closed: an <img> isolates whatever it renders, inline markup does not. A refused file
     * simply arrives with svg=null and the picker falls back to its URL.
     */
    public function test_scripts_handlers_and_remote_references_are_never_inlined(): void
    {
        $rows = $this->rows();

        foreach (['demo/scripted', 'demo/handler', 'demo/remote'] as $reference) {
            $this->assertNull($rows[$reference]['svg'], "{$reference} must not be inlined");
            $this->assertNotSame('', (string) $rows[$reference]['url'], "{$reference} still needs its <img> URL");
        }
    }
}
