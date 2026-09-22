<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\IconLibraryService;
use Heisenberg\Tests\TestCase;

/**
 * The icon block paints by setting `color` on its wrapper, which only reaches the glyph through
 * `currentColor`. Two shipped sets could not paint at all as imported — remix-icon's drawn paths
 * carry no `fill` (so they default to black) and iconsax's carry an empty `fill=""` the importer
 * left behind — so the Fill colour did nothing to them, on the canvas and in the render alike.
 *
 * Normalizing happens in svg(), the one read every consumer goes through (server render, the
 * per-icon asset route, the picker's inlined feed), so all three agree.
 */
class IconColorizationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-icons-color-' . uniqid('', true);
        mkdir($this->root . '/demo', 0775, true);
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

    private function svgFor(string $markup): string
    {
        file_put_contents($this->root . '/demo/icon.svg', $markup);
        file_put_contents($this->root . '/manifest.json', json_encode([
            'sets' => ['demo' => ['icons' => ['icon']]],
            'total' => 1,
        ]));

        return (string) app(IconLibraryService::class)->svg('demo/icon');
    }

    /** remix-icon's shape: an invisible bounding-box path plus a drawn path with no fill at all. */
    public function test_a_shape_with_no_fill_of_its_own_inherits_the_block_colour(): void
    {
        $svg = $this->svgFor('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g><path fill="none" d="M0 0h24v24H0z"/><path d="M21 20H3V9l9-7 9 7z"/></g></svg>');

        $this->assertStringContainsString('<svg fill="currentColor"', $svg);
        // The path that deliberately paints nothing still paints nothing.
        $this->assertStringContainsString('<path fill="none"', $svg);
    }

    /** iconsax's shape: the importer left `fill=""`, invalid and unpaintable. */
    public function test_an_empty_fill_attribute_becomes_the_block_colour(): void
    {
        $svg = $this->svgFor('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M20 10l-8-8v20z" fill=""/></svg>');

        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringNotContainsString('fill=""', $svg);
        // The root keeps `none`: only the drawn path was meant to paint.
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">', $svg);
    }

    public function test_a_single_fixed_colour_becomes_the_block_colour(): void
    {
        $svg = $this->svgFor('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#292D32" d="M4 4h16v16H4z"/></svg>');

        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringNotContainsString('#292D32', $svg);
    }

    public function test_an_icon_that_already_uses_currentcolor_is_untouched(): void
    {
        $markup = '<svg fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';

        $this->assertSame($markup, $this->svgFor($markup));
    }

    /**
     * The conservative half of the rule: recolouring MULTI-colour artwork would flatten a choice
     * the icon's author made, so anything naming more than one paint — or painting with a
     * gradient/pattern reference — is left exactly as it is.
     */
    public function test_multi_colour_and_gradient_artwork_is_left_alone(): void
    {
        $multi = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#ff0000" d="M0 0h12v24H0z"/><path fill="#00ff00" d="M12 0h12v24H12z"/></svg>';
        $this->assertSame($multi, $this->svgFor($multi));

        $gradient = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop stop-color="#ff0000"/></linearGradient></defs><path fill="url(#g)" d="M0 0h24v24H0z"/></svg>';
        $this->assertSame($gradient, $this->svgFor($gradient));
    }
}
