<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Golden-output safety net for the BlockRegistryService / BlockRenderer split
 * (chore/review-fixes refactor). This test is NOT about behaviour correctness —
 * it is a byte-identity tripwire: it renders a broad corpus of block trees
 * (every shipped block type, nesting, both locales, supports/style, state CSS,
 * and hostile input) through the real container-bound renderer and registry,
 * and diffs the result against committed fixtures.
 *
 * REGENERATING FIXTURES: fixtures were captured from the UNMODIFIED, pre-split
 * source. They must never be regenerated against a refactored renderer/registry
 * as a way to "fix" a failure — a failure here means behaviour changed. The
 * only legitimate reason to regenerate is a deliberate, reviewed behaviour
 * change to the renderer/registry. To do so:
 *
 *   HEISENBERG_REGENERATE_GOLDEN=1 php vendor/bin/phpunit tests/Engine/RendererGoldenOutputTest.php
 *
 * then diff the fixture changes under tests/Fixtures/render-golden/ like any
 * other reviewed change.
 */
class RendererGoldenOutputTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__ . '/../Fixtures/render-golden';

    private function renderer(): BlockRenderer
    {
        return $this->app->make(BlockRenderer::class);
    }

    private function registry(): BlockRegistryService
    {
        return $this->app->make(BlockRegistryService::class);
    }

    /** id/name/attributes/supports/innerBlocks block shape shared by every scenario. */
    private static function block(string $name, array $attributes = [], array $supports = [], array $innerBlocks = [], ?string $id = null): array
    {
        static $counter = 0;
        $counter++;

        return [
            'id' => $id ?? ('b' . $counter),
            'name' => $name,
            'attributes' => $attributes,
            'supports' => $supports,
            'innerBlocks' => $innerBlocks,
        ];
    }

    /** @return array<string, array{0: array{blocks: list<array>, locale: string, surface: string}}> */
    public static function scenarios(): array
    {
        $named = [
            'all-block-types-en' => self::scenarioAllBlockTypes('en'),
            'all-block-types-fr-fallback' => self::scenarioAllBlockTypes('fr'),
            'locale-variants' => self::scenarioLocaleVariants(),
            'nested-groups-columns' => self::scenarioNestedContainers(),
            'supports-and-style' => self::scenarioSupportsAndStyle(),
            'state-styles' => self::scenarioStateStyles(),
            'embed-urls' => self::scenarioEmbedUrls(),
            'hostile-input' => self::scenarioHostileInput(),
            'oversized-depth' => self::scenarioOversizedDepth(),
            'missing-attribute-keys' => self::scenarioMissingAttributeKeys(),
            'email-surface-style' => self::scenarioEmailSurfaceStyle(),
        ];

        $cases = [];
        foreach ($named as $name => $scenario) {
            $cases[$name] = [$name, $scenario];
        }

        return $cases;
    }

    private static function scenarioAllBlockTypes(string $locale): array
    {
        $blocks = [
            self::block('heisenberg/heading', ['content' => 'A Heading', 'level' => 2], [], [], 'heading1'),
            self::block('heisenberg/paragraph', ['content' => 'Some <b>bold</b> text.'], [], [], 'paragraph1'),
            self::block('heisenberg/list', ['content' => "One\nTwo\nThree", 'ordered' => true, 'start' => 2, 'reversed' => true], [], [], 'list1'),
            self::block('heisenberg/quote', ['content' => 'To be or not to be.', 'citation' => 'Shakespeare'], [], [], 'quote1'),
            self::block('heisenberg/image', ['url' => 'https://example.com/a.jpg', 'alt' => 'Alt text', 'caption' => 'A caption', 'href' => 'https://example.com', 'target' => '_blank', 'lightboxEnabled' => true], [], [], 'image1'),
            self::block('heisenberg/button', ['text' => 'Click me', 'url' => 'https://example.com/go', 'target' => '_blank', 'variant' => 'primary'], [], [], 'button1'),
            self::block('heisenberg/separator', ['style' => 'solid'], [], [], 'separator1'),
            self::block('heisenberg/icon', ['icon' => 'feather/star'], [], [], 'icon1'),
            self::block('heisenberg/embed', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], [], [], 'embed1'),
            self::block('heisenberg/group', ['titleAttr' => 'Group tip'], [], [
                self::block('heisenberg/paragraph', ['content' => 'Nested in group.'], [], [], 'nestedp1'),
            ], 'group1'),
            self::block('heisenberg/columns', ['columns' => 2], [], [
                self::block('heisenberg/column', [], [], [
                    self::block('heisenberg/paragraph', ['content' => 'Column one.'], [], [], 'colp1'),
                ], 'col1'),
                self::block('heisenberg/column', [], [], [
                    self::block('heisenberg/paragraph', ['content' => 'Column two.'], [], [], 'colp2'),
                ], 'col2'),
            ], 'columns1'),
        ];

        return ['blocks' => $blocks, 'locale' => $locale, 'surface' => 'render'];
    }

    /**
     * Attributes carrying both a `_fr` variant and a bare fallback, exercising
     * `localizedAttribute()`'s `key_<locale>` then bare `key` precedence both ways.
     */
    private static function scenarioLocaleVariants(): array
    {
        $blocks = [
            self::block('heisenberg/heading', [
                'content' => 'English heading',
                'content_fr' => 'Titre français',
                'level' => 3,
                'titleAttr' => 'Only English tooltip', // no _fr variant: must fall back for both locales
            ], [], [], 'h1'),
            self::block('heisenberg/paragraph', [
                'content' => 'English only paragraph (no _fr variant).',
            ], [], [], 'p1'),
        ];

        // Rendered twice (en, fr) by the test method itself; this scenario entry
        // is rendered under 'fr' here, the English-locale companion assertion
        // lives in scenarioAllBlockTypes('en') / the dedicated fr-vs-en comparison below.
        return ['blocks' => $blocks, 'locale' => 'fr', 'surface' => 'render'];
    }

    private static function scenarioNestedContainers(): array
    {
        $inner = self::block('heisenberg/paragraph', ['content' => 'Deepest paragraph.'], [], [], 'deepp');

        $column1 = self::block('heisenberg/column', [], [], [
            self::block('heisenberg/heading', ['content' => 'Col heading', 'level' => 4], [], [], 'colh'),
            $inner,
        ], 'nc-col1');
        $column2 = self::block('heisenberg/column', ['hideMobile' => true, 'fillWidth' => true], [], [
            self::block('heisenberg/paragraph', ['content' => 'Second column.'], [], [], 'colp'),
        ], 'nc-col2');

        $columns = self::block('heisenberg/columns', ['columns' => 2], [
            'align' => 'wide',
        ], [$column1, $column2], 'nc-columns');

        $outerGroup = self::block('heisenberg/group', ['hideTablet' => true], [
            'align' => 'wide',
        ], [$columns], 'nc-outer-group');

        return ['blocks' => [$outerGroup], 'locale' => 'en', 'surface' => 'render'];
    }

    private static function scenarioSupportsAndStyle(): array
    {
        $group = self::block('heisenberg/group', [], [
            'color' => ['text' => '#112233', 'background' => 'linear-gradient(45deg, #ff0000 0%, rgba(0,0,255,0.5) 100%)'],
            'size' => ['width' => '320px', 'minHeight' => '10rem', 'clip' => 'hidden'],
            'spacing' => ['margin' => ['top' => '8px', 'right' => '1rem', 'bottom' => '0', 'left' => '2%'], 'padding' => ['top' => '4px', 'right' => '4px', 'bottom' => '4px', 'left' => '4px']],
            'border' => ['width' => ['top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px'], 'style' => 'dashed', 'color' => '#000000', 'radius' => ['topLeft' => '4px', 'topRight' => '4px', 'bottomRight' => '4px', 'bottomLeft' => '4px']],
            'appearance' => ['opacity' => '0.75'],
            'position' => ['x' => '10px', 'y' => '-5px', 'rotation' => '15deg', 'mode' => 'relative'],
            'effects' => ['shadow' => '0 2px 4px rgba(0,0,0,0.3)'],
            'layout' => ['direction' => 'row', 'justify' => 'center', 'align' => 'center', 'gap' => '12px', 'padding' => '8px'],
        ], [
            self::block('heisenberg/heading', [
                'content' => 'Styled heading',
                'level' => 2,
            ], [
                'typography' => ['fontFamily' => 'Press Start 2P', 'fontWeight' => '700', 'fontSize' => '24px', 'lineHeight' => '1.4', 'letterSpacing' => '0.05em', 'textAlign' => 'center', 'textAlignVertical' => 'center'],
                'color' => ['text' => 'var(--accent-1)'],
            ], [], 'style-h1'),
        ], 'style-group1');

        // Invalid/unsafe values that must degrade to '' or the declared default,
        // never leak through sanitizeCssValue.
        $unsafeGroup = self::block('heisenberg/group', [], [
            'color' => ['text' => 'javascript:alert(1)', 'background' => 'expression(alert(1))'],
            'size' => ['width' => '100vw; background:url(x)'],
            'effects' => ['shadow' => '10px 10px red green'],
            'appearance' => ['opacity' => '5'],
        ], [], 'style-group2-unsafe');

        return ['blocks' => [$group, $unsafeGroup], 'locale' => 'en', 'surface' => 'render'];
    }

    private static function scenarioStateStyles(): array
    {
        $childWithStates = self::block('heisenberg/paragraph', ['content' => 'Hover me'], [
            'states' => [
                'hover' => ['color' => ['text' => '#ff00ff']],
                'active' => ['color' => ['text' => '#00ff00']],
                'focus' => ['color' => ['text' => '#0000ff']],
            ],
        ], [], 'state-child');

        $group = self::block('heisenberg/group', [], [
            'states' => [
                'hover' => ['size' => ['width' => '500px']],
            ],
        ], [$childWithStates], 'state-group');

        // No id -> stateStylesCss must skip it (id charset guard).
        $noId = self::block('heisenberg/paragraph', ['content' => 'No styled state'], [
            'states' => ['hover' => ['color' => ['text' => '#ffffff']]],
        ], [], '');

        return ['blocks' => [$group, $noId], 'locale' => 'en', 'surface' => 'render'];
    }

    private static function scenarioEmbedUrls(): array
    {
        $urls = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90s',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://vimeo.com/76979871',
            'https://player.vimeo.com/video/76979871?h=abc123def4',
            'https://www.dailymotion.com/video/x8abc12_a-title',
            'https://loom.com/share/0123456789abcdef0123456789abcdef',
            'https://streamable.com/e/moo',
            'https://www.tiktok.com/@user/video/1234567890123456789',
            'https://customer-abc123.cloudflarestream.com/deadbeefdeadbeefdeadbeefdeadbeef/watch',
            'https://not-a-real-video-site.example.com/video/1', // rejected -> empty src
            'https://cdn.example.com/media/clip.mp4', // self-hosted file, video tag
        ];

        $blocks = [];
        foreach ($urls as $i => $url) {
            $blocks[] = self::block('heisenberg/embed', ['url' => $url], [], [], 'embed-url-' . $i);
        }

        return ['blocks' => $blocks, 'locale' => 'en', 'surface' => 'render'];
    }

    private static function scenarioHostileInput(): array
    {
        $blocks = [
            self::block('heisenberg/paragraph', [
                'content' => '<script>alert(1)</script>Safe text<img src=x onerror="alert(2)"><a href="javascript:alert(3)">click</a>'
                    . '<span onclick="alert(4)" style="color:red;background-color:rgba(0,0,0,0.5);position:fixed">colored</span>'
                    . '<b><i>unclosed formatting',
            ], [], [], 'hostile-p1'),
            self::block('heisenberg/heading', ['content' => '<h1>nested heading tag</h1><style>body{}</style>', 'level' => 2], [], [], 'hostile-h1'),
            self::block('heisenberg/image', [
                'url' => 'javascript:alert(1)',
                'alt' => '"><script>alert(1)</script>',
                'href' => "java\tscript:alert(1)",
                'target' => '_blank',
            ], [], [], 'hostile-img1'),
            self::block('heisenberg/button', [
                'text' => 'Bad link',
                'url' => 'data:text/html,<script>alert(1)</script>',
                'target' => '_blank',
            ], [], [], 'hostile-btn1'),
            // Unknown block name: must render as empty, never fatal.
            self::block('heisenberg/does-not-exist', ['content' => 'nope'], [], [], 'hostile-unknown'),
            // Malformed: no 'name' key at all.
            ['id' => 'hostile-noname', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
            // Malformed innerBlocks: non-array entries mixed with a valid child.
            self::block('heisenberg/group', [], [], [
                'not-an-array',
                null,
                42,
                self::block('heisenberg/paragraph', ['content' => 'Still renders.'], [], [], 'hostile-valid-child'),
            ], 'hostile-group-malformed-children'),
            self::block('heisenberg/embed', ['url' => 'javascript:alert(1)//https://www.youtube.com/watch?v=dQw4w9WgXcQ'], [], [], 'hostile-embed1'),
        ];

        return ['blocks' => $blocks, 'locale' => 'en', 'surface' => 'render'];
    }

    /** Builds a group nested {@see BlockRenderer::MAX_NESTING_DEPTH} + 5 levels deep. */
    private static function scenarioOversizedDepth(): array
    {
        $depth = BlockRenderer::MAX_NESTING_DEPTH + 5;

        $leaf = self::block('heisenberg/paragraph', ['content' => 'Bottom of the well.'], [], [], 'depth-leaf');
        $node = $leaf;
        for ($i = 0; $i < $depth; $i++) {
            $node = self::block('heisenberg/group', [], [], [$node], 'depth-' . $i);
        }

        return ['blocks' => [$node], 'locale' => 'en', 'surface' => 'render'];
    }

    /**
     * Block instances missing whole keys entirely (not merely empty) — `attributes`,
     * `supports`, `innerBlocks` all absent from some instance, rather than deliberately
     * `[]`. Exercises every `?? []` / `?? null` default the renderer applies when a
     * caller's block shape is incomplete instead of just empty.
     */
    private static function scenarioMissingAttributeKeys(): array
    {
        $blocks = [
            // No 'attributes', 'supports', or 'innerBlocks' key at all.
            ['id' => 'missing-all', 'name' => 'heisenberg/paragraph'],
            // No 'supports' key.
            ['id' => 'missing-supports', 'name' => 'heisenberg/heading', 'attributes' => ['content' => 'No supports key', 'level' => 2]],
            // No 'innerBlocks' key on a container block.
            ['id' => 'missing-inner', 'name' => 'heisenberg/group', 'attributes' => [], 'supports' => []],
        ];

        return ['blocks' => $blocks, 'locale' => 'en', 'surface' => 'render'];
    }

    /**
     * Surface-conditional divergences (§ resolveClass / blockStyleDeclarations docblocks)
     * exercised on the `'email'` surface directly through `renderBlocks()` (not the full
     * {@see EmailRenderer} pipeline): a `classNames` binding (`hideMobile`) and the contract's
     * `align` support must NOT be auto-applied on `'email'` (defect 5), while the block's
     * OWN `style.variables` (background gradient degrading to its first colour stop per
     * §Bug A step 5, plus opacity) still materialize into the root's inline `style`.
     */
    private static function scenarioEmailSurfaceStyle(): array
    {
        $button = self::block('heisenberg/button', [
            'text' => 'Email CTA',
            'url' => 'https://example.com/cta',
            'hideMobile' => true,
        ], [
            'color' => ['text' => '#ffffff', 'background' => 'linear-gradient(45deg, #ff0000 0%, #0000ff 100%)'],
            'appearance' => ['opacity' => '0.5'],
        ], [], 'email-style-button');

        return ['blocks' => [$button], 'locale' => 'en', 'surface' => 'email'];
    }

    #[DataProvider('scenarios')]
    public function test_render_output_matches_the_golden_fixture(string $name, array $scenario): void
    {
        $renderer = $this->renderer();

        $html = $renderer->renderBlocks($scenario['blocks'], $scenario['locale'], $scenario['surface']);
        $css = $renderer->stateStylesCss($scenario['blocks']);

        $this->assertFixture($name, ['html' => $html, 'css' => $css]);
    }

    public function test_locale_fallback_diverges_only_where_a_fr_variant_exists(): void
    {
        $renderer = $this->renderer();
        $scenario = self::scenarioLocaleVariants();

        $en = $renderer->renderBlocks($scenario['blocks'], 'en', 'render');
        $fr = $renderer->renderBlocks($scenario['blocks'], 'fr', 'render');

        $this->assertFixture('locale-variants-en-vs-fr', ['en' => $en, 'fr' => $fr]);
    }

    /**
     * Rides the SAME renderer as the web surface ({@see EmailRenderer} docblock):
     * exercises the `'email'` surface parameter end to end, including the
     * table-based email templates and the (deterministic, preview-mode)
     * image URL rewrite.
     */
    public function test_email_renderer_output_matches_the_golden_fixture(): void
    {
        $post = Post::create(['title_en' => 'Golden Fixture Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $this->addEmailBlock($post, 1, 'heisenberg/heading', ['content' => 'Hello Golden Fixture', 'level' => 1]);
        $this->addEmailBlock($post, 2, 'heisenberg/paragraph', ['content' => 'Body <b>text</b> with <script>alert(1)</script> hostile content.']);
        $this->addEmailBlock($post, 3, 'heisenberg/button', ['text' => 'Read more', 'url' => 'https://example.com/landing', 'target' => '_blank']);
        $this->addEmailBlock($post, 4, 'heisenberg/separator', []);
        $this->addEmailBlock($post, 5, 'heisenberg/columns', ['columns' => 2], [
            [
                'id' => 'ecol1', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    ['id' => 'ecol1p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => 'Left column.'], 'supports' => [], 'innerBlocks' => []],
                ],
            ],
            [
                'id' => 'ecol2', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    ['id' => 'ecol2p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => 'Right column.'], 'supports' => [], 'innerBlocks' => []],
                ],
            ],
        ]);
        // Not part of the email surface -> must render empty, never crash the pipeline.
        $this->addEmailBlock($post, 6, 'heisenberg/embed', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        $result = $this->app->make(EmailRenderer::class)->render($post, 'en', preview: true);

        $this->assertFixture('email-document', [
            'html' => $result->html,
            'text' => $result->text,
            'subject' => $result->subject,
            'embeds' => $result->embeds,
            'sizeBytes' => $result->sizeBytes,
        ]);
    }

    private function addEmailBlock(Post $post, int $order, string $name, array $attributes, array $innerBlocks = []): Block
    {
        return Block::create([
            'post_id' => $post->id,
            'type' => substr($name, strrpos($name, '/') + 1),
            'content' => [
                'id' => 'eb' . $order,
                'name' => $name,
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => [],
                'innerBlocks' => $innerBlocks,
            ],
            'order' => $order,
        ]);
    }

    /**
     * Registry-hash invariant (§4 of the refactor plan): the hash the editor's
     * save payload uses to detect catalogue drift must be byte-identical
     * before/after the registry split, for the shipped contracts.
     */
    public function test_registry_hash_of_shipped_contracts_matches_the_golden_fixture(): void
    {
        $this->assertFixture('registry-hash', ['hash' => $this->registry()->computeHash()]);
    }

    /**
     * @param array<string, mixed> $actual
     */
    private function assertFixture(string $name, array $actual): void
    {
        $path = self::FIXTURE_DIR . '/' . $name . '.json';

        if (getenv('HEISENBERG_REGENERATE_GOLDEN') === '1') {
            if (! is_dir(self::FIXTURE_DIR)) {
                mkdir(self::FIXTURE_DIR, 0775, true);
            }
            file_put_contents(
                $path,
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            $this->markTestSkipped("Fixture regenerated: {$path}");
        }

        $this->assertFileExists($path, "Missing golden fixture: {$path}. Regenerate with HEISENBERG_REGENERATE_GOLDEN=1 (only against unmodified/behaviour-approved source).");

        $expected = json_decode((string) file_get_contents($path), true);

        $this->assertSame($expected, $actual, "Golden output drifted for fixture '{$name}'.");
    }
}
