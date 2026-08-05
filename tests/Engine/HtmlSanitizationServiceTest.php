<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\HtmlSanitizationService;
use Heisenberg\Tests\TestCase;

/**
 * The server-side XSS boundary (blueprint §5). Two HTMLPurifier configs: a
 * hardened one for `html_raw`, a strict inline one for everything else.
 *
 * NOTE: reproduced from docs/BLUEPRINT.md §5, NOT verified against live GTC
 * source — these assertions are the safety net (see memory: port-fidelity).
 */
class HtmlSanitizationServiceTest extends TestCase
{
    private function sanitizer(): HtmlSanitizationService
    {
        return new HtmlSanitizationService();
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer()->purify(''));
        $this->assertSame('', $this->sanitizer()->purifyForBlockType('', 'html_raw'));
    }

    public function test_script_tags_are_always_stripped(): void
    {
        $out = $this->sanitizer()->purifyForBlockType('<script>alert(1)</script>Hello', 'html_raw');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('Hello', $out);
    }

    public function test_event_handlers_are_stripped(): void
    {
        $out = $this->sanitizer()->purifyForBlockType('<a href="https://x.test" onclick="bad()">link</a>', 'html_raw');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('href="https://x.test"', $out);
    }

    public function test_javascript_scheme_urls_are_dropped(): void
    {
        $out = $this->sanitizer()->purifyForBlockType('<a href="javascript:alert(1)">x</a>', 'paragraph');

        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_raw_config_allows_figure_and_figcaption(): void
    {
        $out = $this->sanitizer()->purifyForBlockType(
            '<figure><img src="https://x.test/y.jpg" alt="a"><figcaption>cap</figcaption></figure>',
            'html_raw'
        );

        $this->assertStringContainsString('<figure', $out);
        $this->assertStringContainsString('<figcaption', $out);
        $this->assertStringContainsString('<img', $out);
    }

    public function test_raw_config_allows_only_youtube_and_vimeo_iframes(): void
    {
        $sanitizer = $this->sanitizer();

        $youtube = $sanitizer->purifyForBlockType('<iframe src="https://www.youtube.com/embed/abc123"></iframe>', 'html_raw');
        $this->assertStringContainsString('youtube.com/embed/abc123', $youtube);

        $evil = $sanitizer->purifyForBlockType('<iframe src="https://evil.test/x"></iframe>', 'html_raw');
        $this->assertStringNotContainsString('evil.test', $evil);
    }

    public function test_raw_config_strips_h1_but_keeps_h2(): void
    {
        $out = $this->sanitizer()->purifyForBlockType('<h1>Big</h1><h2>Sub</h2>', 'html_raw');

        $this->assertStringNotContainsString('<h1', $out);
        $this->assertStringContainsString('<h2', $out);
    }

    public function test_inline_config_strips_headings_and_images(): void
    {
        $out = $this->sanitizer()->purifyForBlockType('<h2>Title</h2><img src="https://x.test/y.jpg"><p>Body</p>', 'paragraph');

        $this->assertStringNotContainsString('<h2', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('Body', $out);
    }

    public function test_inline_config_keeps_basic_formatting(): void
    {
        $out = $this->sanitizer()->purifyForBlockType('<strong>bold</strong> <em>it</em> <a href="https://x.test">link</a>', 'paragraph');

        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<em>it</em>', $out);
        $this->assertStringContainsString('href="https://x.test"', $out);
    }

    public function test_purify_uses_the_hardened_config(): void
    {
        // purify() (no type) is the final render-cache pass — uses the raw config,
        // so it keeps an h2 that the inline config would strip.
        $out = $this->sanitizer()->purify('<h2>Kept</h2>');

        $this->assertStringContainsString('<h2', $out);
    }
}
