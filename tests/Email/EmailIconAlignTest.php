<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Where an icon sits in the sent email (docs/email-system.md §4.1, §4.2).
 *
 * The icon's email template used to force `align="left"` on its own cell and span 100% of its
 * container, so an icon centred on the canvas — by its own alignment or by a centred group or
 * column — drifted to the left edge of the message. It now follows the button's rule: no
 * alignment of its own means none is emitted, and inside a cross-aligned column it hugs its
 * glyph so the container's cell can place it.
 */
class EmailIconAlignTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = '/uploads/email-icons/remix-icon-earth-fill-5b8def-32.png';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        Storage::disk('uploads')->put('email-icons/remix-icon-earth-fill-5b8def-32.png', (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8//8/AzJgYkAD5AsAAJ0GAwoLSPgAAAAASUVORK5CYII=',
            true,
        ));
    }

    /** @param array<string, mixed> $supports */
    private function icon(array $supports = []): array
    {
        return [
            'id' => 'icon',
            'name' => 'heisenberg/icon',
            'schemaVersion' => '1.0.0',
            'attributes' => [
                'icon' => 'remix-icon/earth-fill',
                'emailImage' => self::PNG,
                'emailImageW' => '32',
                'emailImageH' => '32',
            ],
            'supports' => $supports,
            'innerBlocks' => [],
        ];
    }

    /** @param array<string, mixed> $root */
    private function render(array $root): string
    {
        $post = Post::create(['title_en' => 'Align', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();
        Block::create(['post_id' => $post->id, 'type' => 'group', 'content' => $root, 'order' => 0]);

        return $this->app->make(EmailRenderer::class)->render($post, 'en')->html;
    }

    /** The `<td>` that directly holds the icon's `<img>`, and every cell enclosing it, innermost first. */
    private function alignsAroundIcon(string $html): array
    {
        $at = strpos($html, '<img');
        $this->assertNotFalse($at, 'the icon rendered no image');
        $open = [];
        $depth = 0;
        // Walk back from the image, keeping only cells still open at that point.
        $tokens = preg_split('/(<td\b[^>]*>|<\/td>)/i', substr($html, 0, $at), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach (array_reverse((array) $tokens) as $token) {
            if (stripos($token, '</td>') === 0) {
                $depth++;
            } elseif (stripos($token, '<td') === 0) {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }
                $open[] = preg_match('/\balign="([a-z]+)"/i', $token, $m) === 1 ? $m[1] : '';
            }
        }

        return $open;
    }

    /** The nearest alignment that actually applies to the icon: the innermost cell that sets one. */
    private function effectiveAlign(string $html): string
    {
        foreach ($this->alignsAroundIcon($html) as $align) {
            if ($align !== '') {
                return $align;
            }
        }

        return 'left';
    }

    private function group(array $supports, array $children): array
    {
        return [
            'id' => 'g',
            'name' => 'heisenberg/group',
            'schemaVersion' => '1.0.0',
            'attributes' => [],
            'supports' => $supports,
            'innerBlocks' => $children,
        ];
    }

    public function test_an_icon_centred_by_its_own_alignment_stays_centred(): void
    {
        $html = $this->render($this->group([], [$this->icon(['align' => 'center'])]));

        $this->assertSame('center', $this->effectiveAlign($html));
    }

    public function test_an_icon_in_a_centred_column_stays_centred(): void
    {
        $html = $this->render($this->group(['layout' => ['align' => 'center']], [$this->icon()]));

        $this->assertSame('center', $this->effectiveAlign($html));
    }

    /** A sized icon (w/h set, as the inspector does) still hugs its glyph rather than spanning the row. */
    public function test_a_sized_icon_in_a_centred_column_hugs_and_stays_centred(): void
    {
        $html = $this->render($this->group(['layout' => ['align' => 'center']], [
            $this->icon(['size' => ['width' => '32px', 'height' => '32px']]),
        ]));

        $this->assertSame('center', $this->effectiveAlign($html));
        $at = (int) strpos($html, '<img');
        $iconTable = (int) strrpos(substr($html, 0, $at), '<table');
        $this->assertStringNotContainsString('width="100%"', substr($html, $iconTable, $at - $iconTable));
    }

    public function test_an_icon_in_a_right_aligned_column_goes_right(): void
    {
        $html = $this->render($this->group(['layout' => ['align' => 'end']], [$this->icon()]));

        $this->assertSame('right', $this->effectiveAlign($html));
    }

    public function test_the_icons_own_alignment_beats_its_containers(): void
    {
        $html = $this->render($this->group(['layout' => ['align' => 'center']], [$this->icon(['align' => 'right'])]));

        $this->assertSame('right', $this->effectiveAlign($html));
    }

    public function test_an_unaligned_icon_in_an_unaligned_group_stays_left(): void
    {
        $html = $this->render($this->group([], [$this->icon()]));

        $this->assertSame('left', $this->effectiveAlign($html));
    }
}
