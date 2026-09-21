<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fixture dump for tests/js/email-canvas-parity.mjs: ONE styled, nested document, saved twice —
 * as an email and as a post — and each opened in its own editor. The harness runs both pages'
 * real inline scripts in jsdom and requires the two CANVASES to be identical: an email is edited
 * on the same canvas as a post, and only its palette and its export differ. The real
 * {@see EmailRenderer} export is written alongside so the harness can also confirm the export
 * is where the email-specific markup lives.
 *
 *   HB_EMAIL_DUMP_PATH=build/js-harness/email-editor.html vendor/bin/phpunit --filter test_dump_email
 *   node tests/js/email-canvas-parity.mjs build/js-harness/email-editor.html
 */
class DumpEmailEditorHtmlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local'; // LocalDevRoleGate: opening an existing draft is role-gated
    }

    public function test_dump_email(): void
    {
        $out = getenv('HB_EMAIL_DUMP_PATH');
        if (! $out) {
            $this->markTestSkipped('Set HB_EMAIL_DUMP_PATH to dump the email editor page + export for the jsdom parity harness.');
        }

        $post = Post::create(['title_en' => 'Parity', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();
        $twin = Post::create(['title_en' => 'Parity', 'locale' => 'en']); // the same document, as a post

        // Explicit colour + size everywhere: a DEFAULT is a design token (`var(--ink)`), which the
        // export resolves to a literal and the canvas leaves to the browser — not a divergence,
        // and not what this fixture is measuring.
        $text = static fn (string $colour): array => ['color' => ['text' => $colour], 'typography' => ['fontSize' => '15px']];
        $block = static fn (string $id, string $slug, array $attributes, array $supports, array $inner = []): array => [
            'id' => $id, 'name' => 'heisenberg/' . $slug, 'schemaVersion' => '1.0.0',
            'attributes' => $attributes, 'supports' => $supports, 'innerBlocks' => $inner,
        ];

        $blocks = [
            $block('solo', 'paragraph', ['content' => 'SOLO'], array_replace_recursive($text('#112233'), [
                'color' => ['background' => 'linear-gradient(45deg, #ff0000 0%, #0000ff 100%)'],
                'spacing' => ['padding' => ['top' => '40px', 'left' => '8px'], 'margin' => ['bottom' => '32px']],
                'typography' => ['textAlign' => 'center', 'fontWeight' => '700', 'letterSpacing' => '2px', 'fontFamily' => 'Press Start 2P'],
                'size' => ['width' => '400px'],
            ])),
            $block('grp', 'group', [], [
                'color' => ['background' => '#eeeeee', 'text' => '#222222'],
                'spacing' => ['padding' => ['top' => '12px', 'right' => '12px', 'bottom' => '12px', 'left' => '12px']],
                'border' => ['width' => ['top' => '3px'], 'color' => '#00ff00', 'radius' => ['topLeft' => '6px']],
                'layout' => ['justify' => 'center', 'align' => 'end', 'gap' => '14px'],
            ], [
                $block('red', 'paragraph', ['content' => 'RED'], $text('#ff0000')),
                $block('blue', 'paragraph', ['content' => 'BLUE'], $text('#0000ff')),
                $block('cols', 'columns', [], ['layout' => ['align' => 'center', 'gap' => '10px']], [
                    $block('c1', 'column', [], ['color' => ['background' => '#fafafa']], [$block('left', 'paragraph', ['content' => 'LEFT'], $text('#333333'))]),
                    $block('c2', 'column', [], ['spacing' => ['padding' => ['left' => '20px']], 'layout' => ['justify' => 'end', 'align' => 'center']], [$block('right', 'paragraph', ['content' => 'RIGHT'], $text('#444444'))]),
                ]),
            ]),
            $block('rowgrp', 'group', [], ['layout' => ['direction' => 'row', 'justify' => 'space-between', 'align' => 'end', 'gap' => '6px']], [
                $block('r1', 'paragraph', ['content' => 'ROW1'], $text('#101010')),
                $block('r2', 'paragraph', ['content' => 'ROW2'], $text('#202020')),
            ]),
            $block('head', 'heading', ['content' => 'HEAD', 'level' => 3], array_replace_recursive($text('#550055'), ['typography' => ['fontSize' => '28px', 'lineHeight' => '1.1']])),
            $block('btn', 'button', ['text' => 'GO', 'url' => 'https://example.com'], [
                'align' => 'right', 'color' => ['text' => '#000000', 'background' => '#ffcc00'],
                'spacing' => ['padding' => ['left' => '40px']], 'border' => ['radius' => ['topLeft' => '12px']],
            ]),
        ];

        foreach ($blocks as $order => $content) {
            foreach ([$post, $twin] as $document) {
                Block::create(['post_id' => $document->id, 'type' => substr($content['name'], 11), 'content' => $content, 'order' => $order]);
            }
        }

        file_put_contents($out, $this->get('/editor/email/' . $post->id)->assertOk()->getContent());
        file_put_contents($out . '.post.html', $this->get('/editor/' . $twin->id)->assertOk()->getContent());
        file_put_contents($out . '.export.html', $this->app->make(EmailRenderer::class)->render($post->fresh(), 'en', true)->html);

        $this->assertFileExists($out . '.export.html');
    }
}
