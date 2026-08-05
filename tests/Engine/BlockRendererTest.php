<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;

/**
 * The generic contract-template renderer (blueprint §4.2–4.7, §4.10). ONE walk
 * renders any block from its contract `render.template` — no per-block code.
 *
 * NOTE: reproduced from docs/BLUEPRINT.md, NOT verified against live GTC source.
 * These assertions are the safety net for the render-side XSS model.
 */
class BlockRendererTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            $this->deleteTree($this->fixtureRoot);
        }
        parent::tearDown();
    }

    private function renderer(): BlockRenderer
    {
        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'));

        return new BlockRenderer($registry);
    }

    private function block(string $name, array $attributes = [], array $supports = []): array
    {
        return ['id' => 'b1', 'name' => $name, 'attributes' => $attributes, 'supports' => $supports, 'innerBlocks' => []];
    }

    /**
     * A renderer against small synthetic contracts standing in for the (now-deleted,
     * builder-only) `heisenberg/image` and `heisenberg/button` — see resources/blocks/
     * git history for the originals. What these regression tests actually exercise is
     * BlockRenderer's GENERIC url/attribute sanitization and the reverse-tabnabbing
     * `rel` guard, none of which is block-specific, so a minimal fixture contract
     * proves the same thing the real ones did.
     */
    private function fixtureRenderer(): BlockRenderer
    {
        $this->fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-renderer-fixture-' . uniqid('', true);
        mkdir($this->fixtureRoot, 0775, true);

        $this->writeFixtureContract('renderer-image-fixture', [
            'category' => 'media',
            'icon' => 'image',
            'attributes' => [
                'url' => ['type' => 'url', 'default' => '', 'sanitize' => 'url'],
                'alt' => ['type' => 'string', 'default' => '', 'sanitize' => 'text'],
            ],
            'template' => [
                'tag' => 'figure',
                'class' => 'hb-block hb-block-renderer-image-fixture',
                'attributes' => ['data-block-name' => '{{name}}', 'data-block-id' => '{{id}}'],
                'children' => [
                    ['type' => 'element', 'tag' => 'img', 'attributes' => ['src' => '{{attributes.url}}', 'alt' => '{{attributes.alt}}']],
                    [
                        'type' => 'element', 'tag' => 'button',
                        'class' => 'hb-block-renderer-image-fixture__picker',
                        'attributes' => ['type' => 'button', 'data-image-picker' => 'true'],
                        'children' => [['type' => 'text', 'content' => 'Select image']],
                    ],
                ],
            ],
        ]);

        $this->writeFixtureContract('renderer-link-fixture', [
            'category' => 'design',
            'icon' => 'mouse-pointer-click',
            'attributes' => [
                'text' => ['type' => 'string', 'default' => '', 'sanitize' => 'text'],
                'url' => ['type' => 'url', 'default' => '#', 'sanitize' => 'url'],
                'target' => ['type' => 'string', 'default' => '_self', 'enum' => ['_self', '_blank'], 'sanitize' => 'text'],
            ],
            'template' => [
                'tag' => 'div',
                'class' => 'hb-block hb-block-renderer-link-fixture',
                'attributes' => ['data-block-name' => '{{name}}', 'data-block-id' => '{{id}}'],
                'children' => [
                    [
                        'type' => 'element', 'tag' => 'a',
                        'attributes' => ['href' => '{{attributes.url}}', 'target' => '{{attributes.target}}'],
                        'children' => [['type' => 'text', 'content' => '{{attributes.text}}']],
                    ],
                ],
            ],
        ]);

        $registry = new BlockRegistryService(new BlockContractValidator('heisenberg'), $this->fixtureRoot);

        return new BlockRenderer($registry);
    }

    private function writeFixtureContract(string $slug, array $overrides): void
    {
        $contract = [
            '$schema' => '../../../docs/block-schema.md',
            'apiVersion' => 1,
            'name' => "heisenberg/{$slug}",
            'title' => "Renderer fixture: {$slug}",
            'category' => $overrides['category'],
            'icon' => $overrides['icon'],
            'description' => "Renderer fixture: {$slug}",
            'keywords' => ['fixture'],
            'version' => '1.0.0',
            'attributes' => $overrides['attributes'],
            'supports' => [],
            'style' => ['css' => "./{$slug}.css", 'className' => "hb-block-{$slug}"],
            'render' => [
                'template' => $overrides['template'],
                'publicPartial' => "blocks.{$slug}",
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'none', 'allowRawHtml' => false, 'allowedUrlProtocols' => ['http', 'https', 'mailto', 'tel'], 'allowCustomCss' => false],
        ];

        $dir = $this->fixtureRoot . DIRECTORY_SEPARATOR . $slug;
        mkdir($dir, 0775, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . "{$slug}.json",
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_renders_a_paragraph_from_its_contract_template(): void
    {
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Hello <strong>world</strong>'], ['color' => ['text' => 'var(--accent-2)']]),
            'en'
        );

        $this->assertStringContainsString('<p', $html);
        $this->assertStringContainsString('class="hb-block hb-block-paragraph hb-supports hb-ease-smooth"', $html);
        $this->assertStringContainsString('data-block-name="heisenberg/paragraph"', $html);
        $this->assertStringContainsString('data-block-id="b1"', $html);
        $this->assertStringContainsString('<span class="hb-block-paragraph__text">', $html);
        $this->assertStringContainsString('Hello <strong>world</strong>', $html);
        $this->assertStringContainsString('--hb-paragraph-color: var(--accent-2)', $html);
    }

    public function test_paragraph_drop_cap_class_is_emitted_only_when_enabled(): void
    {
        $enabled = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Alpha', 'dropCap' => true]),
            'en'
        );
        $disabled = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Alpha', 'dropCap' => false]),
            'en'
        );

        $this->assertStringContainsString('has-drop-cap', $enabled);
        $this->assertStringNotContainsString('has-drop-cap', $disabled);
    }

    public function test_supported_alignment_is_emitted_on_the_block_root(): void
    {
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Alpha'], ['align' => 'center']),
            'en'
        );

        $this->assertStringContainsString('class="hb-block hb-block-paragraph hb-supports hb-ease-smooth hb-align-center"', $html);
    }

    public function test_unknown_alignment_is_not_emitted(): void
    {
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Alpha'], ['align' => 'weird']),
            'en'
        );

        $this->assertStringNotContainsString('hb-align-', $html);
    }

    public function test_paragraph_color_token_materializes_through_the_study_binding(): void
    {
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Alpha'], ['color' => ['text' => 'var(--ink)']]),
            'en'
        );

        $this->assertStringContainsString('--hb-paragraph-color: var(--ink);', $html);
    }

    public function test_strips_dangerous_tags_from_richtext(): void
    {
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Hi<script>x</script><img src=y>']),
            'en'
        );

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Hi', $html);
    }

    public function test_resolves_locale_for_richtext(): void
    {
        $block = $this->block('heisenberg/paragraph', ['content_en' => 'Hello', 'content_fr' => 'Bonjour']);

        $this->assertStringContainsString('Hello', $this->renderer()->renderBlock($block, 'en'));
        $this->assertStringContainsString('Bonjour', $this->renderer()->renderBlock($block, 'fr'));
    }

    public function test_renders_dynamic_heading_tag_from_level(): void
    {
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/heading', ['content' => 'Title', 'level' => 3]),
            'en'
        );

        $this->assertStringContainsString('<h3', $html);
        $this->assertStringContainsString('Title', $html);
    }

    public function test_skips_editor_only_nodes(): void
    {
        $html = $this->fixtureRenderer()->renderBlock(
            $this->block('heisenberg/renderer-image-fixture', ['url' => 'https://cdn.test/p.jpg', 'alt' => 'A photo']),
            'en'
        );

        $this->assertStringContainsString('src="https://cdn.test/p.jpg"', $html);
        $this->assertStringContainsString('alt="A photo"', $html);
        $this->assertStringNotContainsString('data-image-picker', $html);
        $this->assertStringNotContainsString('Select image', $html);
    }

    public function test_drops_dangerous_image_urls(): void
    {
        $html = $this->fixtureRenderer()->renderBlock(
            $this->block('heisenberg/renderer-image-fixture', ['url' => 'javascript:alert(1)', 'alt' => 'x']),
            'en'
        );

        $this->assertStringNotContainsString('javascript:', $html);
    }

    /**
     * Regression: a control character inside the scheme (tab/newline/CR/NUL)
     * makes parse_url() report no scheme, so an unpatched allow-list treats
     * "java\tscript:…" as a relative URL and passes it through. Browsers strip
     * the control char and run it as "javascript:". safeUrl() must scrub C0
     * controls before checking the scheme.
     */
    public function test_drops_control_char_obfuscated_javascript_urls(): void
    {
        $renderer = $this->fixtureRenderer();
        foreach (["java\tscript:alert(1)", "java\nscript:alert(1)", "java\rscript:alert(1)", "java\0script:alert(1)"] as $payload) {
            $html = $renderer->renderBlock(
                $this->block('heisenberg/renderer-image-fixture', ['url' => $payload, 'alt' => 'x']),
                'en'
            );

            $this->assertStringNotContainsString('script:', $html, 'leaked payload: ' . json_encode($payload));
            $this->assertStringNotContainsString('alert(1)', $html, 'leaked payload: ' . json_encode($payload));
        }
    }

    public function test_escapes_attribute_values(): void
    {
        $html = $this->fixtureRenderer()->renderBlock(
            $this->block('heisenberg/renderer-image-fixture', ['url' => 'https://cdn.test/p.jpg', 'alt' => '"><x']),
            'en'
        );

        $this->assertStringNotContainsString('"><x', $html);   // the raw injection must not survive
        $this->assertStringContainsString('&quot;&gt;', $html); // it is escaped instead
    }

    public function test_scrubs_attributes_from_richtext_formatting_tags(): void
    {
        // strip_tags keeps attributes on allowed tags; the renderer must scrub them (GTC parity).
        $html = $this->renderer()->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'Hi <strong onmouseover="alert(1)">there</strong>']),
            'en'
        );

        $this->assertStringContainsString('<strong>there</strong>', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
    }

    /**
     * Overhaul 2026-07-18: colour values widened from the fixed accent
     * palette to `color-value` (any var(--token) reference — user themes
     * define their own names — plus strict hex/rgb/hsl literals). The value
     * shape is still a hard allowlist: anything outside it is dropped.
     */
    public function test_colour_values_allow_tokens_and_literals_but_reject_junk(): void
    {
        $renderer = $this->renderer();

        $tokenHtml = $renderer->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'x'], ['color' => ['text' => 'var(--brand-primary)']]),
            'en'
        );
        $this->assertStringContainsString('--hb-paragraph-color: var(--brand-primary)', $tokenHtml);

        $hexHtml = $renderer->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'x'], ['color' => ['text' => '#3d68f5']]),
            'en'
        );
        $this->assertStringContainsString('--hb-paragraph-color: #3d68f5', $hexHtml);

        $junkHtml = $renderer->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'x'], ['color' => ['text' => 'red; background: url(javascript:1)']]),
            'en'
        );
        $this->assertStringNotContainsString('url(', $junkHtml);
        $this->assertStringContainsString('--hb-paragraph-color: var(--accent-1)', $junkHtml);
    }

    /**
     * Overhaul 2026-07-18: `size-value` accepts number+unit (px/rem/em/%/
     * vw/vh) and var() tokens. calc()/expressions/free text stay rejected.
     */
    public function test_size_values_allow_units_but_reject_expressions(): void
    {
        $renderer = $this->renderer();

        $vhHtml = $renderer->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'x'], ['typography' => ['fontSize' => '10vh']]),
            'en'
        );
        $this->assertStringContainsString('--hb-paragraph-fs: 10vh', $vhHtml);

        $calcHtml = $renderer->renderBlock(
            $this->block('heisenberg/paragraph', ['content' => 'x'], ['typography' => ['fontSize' => 'calc(100vw - 10px)']]),
            'en'
        );
        $this->assertStringNotContainsString('calc(', $calcHtml);
        $this->assertStringContainsString('--hb-paragraph-fs: var(--fs-md)', $calcHtml);
    }

    public function test_renders_a_list_of_blocks(): void
    {
        $html = $this->renderer()->renderBlocks([
            $this->block('heisenberg/paragraph', ['content' => 'One']),
            $this->block('heisenberg/paragraph', ['content' => 'Two']),
        ], 'en');

        $this->assertStringContainsString('One', $html);
        $this->assertStringContainsString('Two', $html);
    }

    public function test_unknown_block_renders_nothing(): void
    {
        $this->assertSame('', $this->renderer()->renderBlock($this->block('heisenberg/nope'), 'en'));
    }

    /**
     * Reverse tabnabbing (§4.10): a button whose target opens a new browsing
     * context must carry rel="noopener noreferrer" so the new page can't reach
     * back into window.opener. Enforced generically in BlockRenderer for every
     * <a target="_blank">, not hand-wired per contract.
     */
    public function test_blank_target_link_gets_noopener_noreferrer_rel(): void
    {
        $html = $this->fixtureRenderer()->renderBlock(
            $this->block('heisenberg/renderer-link-fixture', ['text' => 'Go', 'url' => 'https://example.com', 'target' => '_blank']),
            'en'
        );

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_self_target_link_does_not_get_a_forced_rel(): void
    {
        $html = $this->fixtureRenderer()->renderBlock(
            $this->block('heisenberg/renderer-link-fixture', ['text' => 'Go', 'url' => 'https://example.com', 'target' => '_self']),
            'en'
        );

        $this->assertStringContainsString('target="_self"', $html);
        $this->assertStringNotContainsString('rel="noopener noreferrer"', $html);
    }
}
