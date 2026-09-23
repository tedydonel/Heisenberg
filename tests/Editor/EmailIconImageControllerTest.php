<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * POST /editor/email-icon — where the editor's rasterized icon PNG lands.
 *
 * The bytes come from a browser, so this endpoint's whole job is to distrust them: the icon must
 * be manifest-listed, the colour a plain hex, the size sane, and the payload a REAL PNG of the
 * expected 2x dimensions. The stored name is DERIVED from those validated parts, never taken
 * from the request, which is what makes the same icon reusable across blocks and documents
 * without letting a caller choose where anything is written.
 */
class EmailIconImageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrfProtection();
        // Gated on the `authors` tier, like every other editor write.
        $this->actingAs(new FakeActor(1, 'author'));
    }

    /** A real 64x64 PNG (2x of a 32px icon). */
    private function png(int $side = 64): string
    {
        $image = imagecreatetruecolor($side, $side);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($bytes);
    }

    private function send(array $overrides = [])
    {
        return $this->postJson('/editor/email-icon', array_merge([
            'icon' => 'material-design/home',
            'color' => '#e91e63',
            'size' => 32,
            'png' => $this->png(),
        ], $overrides));
    }

    public function test_it_stores_the_png_under_a_derived_name_and_returns_its_url(): void
    {
        Storage::fake('uploads');

        $response = $this->send()->assertCreated();

        $path = 'email-icons/material-design-home-e91e63-32.png';
        Storage::disk('uploads')->assertExists($path);
        $this->assertSame('/uploads/' . $path, $response->json('url'));
        $this->assertSame(32, $response->json('width'));
        $this->assertSame(32, $response->json('height'));
    }

    /** The point of the derived name: the second block wanting this icon writes nothing new. */
    public function test_the_same_icon_colour_and_size_is_stored_once_and_reused(): void
    {
        Storage::fake('uploads');

        $first = $this->send()->assertCreated();
        $written = Storage::disk('uploads')->lastModified('email-icons/material-design-home-e91e63-32.png');

        $second = $this->send(['png' => $this->png(8)])->assertOk(); // 200, not 201: nothing decoded
        $this->assertSame($first->json('url'), $second->json('url'));
        $this->assertSame($written, Storage::disk('uploads')->lastModified('email-icons/material-design-home-e91e63-32.png'));
    }

    public function test_an_unlisted_icon_reference_is_refused(): void
    {
        Storage::fake('uploads');

        $this->send(['icon' => 'made-up/nonsense'])->assertStatus(422);
        $this->send(['icon' => '../../etc/passwd'])->assertStatus(422);
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_a_bad_colour_or_size_is_refused(): void
    {
        Storage::fake('uploads');

        $this->send(['color' => 'red'])->assertStatus(422);
        $this->send(['color' => '#fff'])->assertStatus(422);
        $this->send(['size' => 4])->assertStatus(422);
        $this->send(['size' => 1024])->assertStatus(422);
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    /**
     * The declared type is irrelevant — the DECODED bytes' own header decides. A payload that
     * merely claims to be a PNG (say, an SVG or a script) is refused.
     */
    public function test_a_payload_that_is_not_really_a_png_is_refused(): void
    {
        Storage::fake('uploads');

        $this->send(['png' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);
        $this->send(['png' => base64_encode('GIF89a plain bytes')])->assertStatus(422);
        $this->send(['png' => 'not base64 at all !!'])->assertStatus(422);
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    /** A write endpoint under the editor's bare `['web']` default gate carries its own check. */
    public function test_a_visitor_who_may_not_author_writes_nothing(): void
    {
        Storage::fake('uploads');
        app()->forgetInstance('auth');
        $this->app['auth']->forgetGuards();

        $this->postJson('/editor/email-icon', [
            'icon' => 'material-design/home',
            'color' => '#e91e63',
            'size' => 32,
            'png' => $this->png(),
        ])->assertStatus(403);

        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    /** A PNG of the wrong size would ship a blurry or mis-scaled icon into the send. */
    public function test_a_png_that_is_not_twice_the_icon_size_is_refused(): void
    {
        Storage::fake('uploads');

        $this->send(['png' => $this->png(32)])->assertStatus(422); // 1x, not 2x
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }
}
