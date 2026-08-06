<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Support\SupportsStyle;
use Heisenberg\Tests\TestCase;

/**
 * Guards every served stylesheet against comment poisoning: a literal star-slash
 * sequence INSIDE a CSS comment terminates it early, the leftover text becomes
 * garbage tokens, and the parser's error recovery silently swallows the next
 * rule. This exact bug killed both text blocks' base rules (color/size/spacing)
 * in every real browser while the files read fine to humans.
 */
class CssIntegrityTest extends TestCase
{
    public function test_every_served_stylesheet_has_balanced_comment_markers(): void
    {
        $sources = [
            'SupportsStyle::css()' => SupportsStyle::css(),
            'BlockViewData::blocksCss()' => BlockViewData::blocksCss(app(BlockRegistryService::class)),
        ];

        $base = dirname(__DIR__, 2) . '/resources';
        foreach (glob($base . '/blocks/*/*.css') ?: [] as $file) {
            $sources[basename(dirname($file)) . '/' . basename($file)] = (string) file_get_contents($file);
        }
        foreach (array_merge(glob($base . '/css/*.css') ?: [], glob($base . '/css/editor/*.css') ?: []) as $file) {
            $sources[basename($file)] = (string) file_get_contents($file);
        }

        $this->assertGreaterThan(4, count($sources));

        foreach ($sources as $name => $css) {
            $opens = substr_count($css, '/*');
            $closes = substr_count($css, '*/');
            $this->assertSame(
                $opens,
                $closes,
                "{$name}: {$opens} comment opens vs {$closes} closes — a stray star-slash inside a comment silently swallows the following rule in real browsers."
            );

            // No comment content may itself contain a close marker mid-comment: strip
            // well-formed comments lazily and require no '*/' to survive.
            $stripped = preg_replace('#/\*.*?\*/#s', '', $css);
            $this->assertStringNotContainsString('*/', (string) $stripped, "{$name}: stray comment terminator outside a well-formed comment");
        }
    }
}
