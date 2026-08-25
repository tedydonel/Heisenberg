<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;

/**
 * Graded rich-text enforcement (engine-requirements R-CTL-RT): the renderer must
 * honor each block's declared `security.richText` tier, not a single fixed
 * allow-list. Three tiers:
 *   - `none`                 → no inline formatting (escaped plain text; e.g. code)
 *   - `inline-basic`         → bold / italic / link (body, captions)
 *   - `inline-basic-no-link` → bold / italic, NO link (button text, headings)
 */
class BlockRendererRichTextTierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-tier-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    private function renderWithTier(string $tier, string $content): string
    {
        $this->writeLeaf('tiered', $tier);
        $renderer = new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg'), $this->root));

        return $renderer->renderBlock(
            ['id' => 'b1', 'name' => 'heisenberg/tiered', 'attributes' => ['content' => $content], 'supports' => [], 'innerBlocks' => []],
            'en'
        );
    }

    public function test_inline_basic_keeps_bold_and_links(): void
    {
        $html = $this->renderWithTier('inline-basic', 'A <strong>b</strong> <a href="https://x.test">l</a>');

        $this->assertStringContainsString('<strong>b</strong>', $html);
        $this->assertStringContainsString('<a href="https://x.test">l</a>', $html);
    }

    public function test_inline_basic_no_link_strips_anchors_but_keeps_bold(): void
    {
        $html = $this->renderWithTier('inline-basic-no-link', 'A <strong>b</strong> <a href="https://x.test">link</a>');

        $this->assertStringContainsString('<strong>b</strong>', $html);
        $this->assertStringNotContainsString('<a', $html);   // the anchor tag is gone
        $this->assertStringContainsString('link', $html);     // its inner text is kept
    }

    public function test_none_escapes_all_formatting_as_literal_text(): void
    {
        $html = $this->renderWithTier('none', '<b>x</b>');

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_inline_basic_rejects_javascript_href(): void
    {
        $html = $this->renderWithTier('inline-basic', '<a href="javascript:alert(1)">unsafe</a>');

        $this->assertStringContainsString('<a>unsafe</a>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_unknown_tier_falls_back_to_inline_basic(): void
    {
        $html = $this->renderWithTier('unknown-tier', '<b>bold</b> <a href="https://x.test">link</a>');

        $this->assertStringContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('<a href="https://x.test">link</a>', $html);
    }

    // ── cPGss toolbar formats (2026-07-18 extension) ──────────

    public function test_inline_basic_keeps_underline_strike_code_and_mark(): void
    {
        $html = $this->renderWithTier('inline-basic', '<u>u</u> <s>s</s> <code>c()</code> <mark>hi</mark>');

        $this->assertStringContainsString('<u>u</u>', $html);
        $this->assertStringContainsString('<s>s</s>', $html);
        $this->assertStringContainsString('<code>c()</code>', $html);
        $this->assertStringContainsString('<mark>hi</mark>', $html);
    }

    public function test_span_keeps_only_validated_color_styles(): void
    {
        $html = $this->renderWithTier(
            'inline-basic',
            '<span style="color: rgb(225, 29, 72)">red</span>'
            . '<span style="background-color:#ffd166; position:fixed; color:url(evil)">hl</span>'
        );

        $this->assertStringContainsString('<span style="color: rgb(225, 29, 72)">red</span>', $html);
        $this->assertStringContainsString('<span style="background-color: #ffd166">hl</span>', $html);
        $this->assertStringNotContainsString('position', $html);
        $this->assertStringNotContainsString('url(', $html);
    }

    public function test_span_event_handlers_and_classes_are_scrubbed(): void
    {
        $html = $this->renderWithTier(
            'inline-basic',
            '<span onclick="alert(1)" class="x" data-y="z">plain</span>'
        );

        $this->assertStringContainsString('<span>plain</span>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<span class', $html);
        $this->assertStringNotContainsString('data-y', $html);
    }

    public function test_formatting_tags_never_carry_attributes(): void
    {
        $html = $this->renderWithTier('inline-basic', '<code onmouseover="x()">c</code><mark id="m">h</mark>');

        $this->assertStringContainsString('<code>c</code>', $html);
        $this->assertStringContainsString('<mark>h</mark>', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
    }

    private function writeLeaf(string $slug, string $tier): void
    {
        $contract = [
            '$schema' => './schema.md', 'apiVersion' => 1, 'name' => "heisenberg/{$slug}",
            'title' => 'Tiered', 'category' => 'text', 'icon' => 'square', 'description' => 'x',
            'keywords' => [], 'version' => '1.0.0',
            'attributes' => ['content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block']],
            'supports' => [],
            'style' => ['className' => "hb-block-{$slug}"],
            'render' => ['template' => ['tag' => 'p', 'class' => "hb-block hb-block-{$slug}", 'children' => [['type' => 'rich-text', 'attribute' => 'content']]], 'script' => null],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json'],
            'security' => ['richText' => $tier, 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];

        $dir = $this->root . DIRECTORY_SEPARATOR . $slug;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $slug . '.json', json_encode($contract, JSON_UNESCAPED_SLASHES));
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
