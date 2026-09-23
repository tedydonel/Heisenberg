<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What a theme font becomes on the email surface (docs/email-system.md §2).
 *
 * It used to become a web-safe stack and NOTHING else — the author's family never reached the
 * message, so no client could render it even when it could load the face. Worse, which stack you
 * got was decided by keyword-matching the TOKEN's name: a token called `font-serif` holding a
 * sans family was handed Georgia, so picking the second theme font changed the email into a
 * serif that looked nothing like the font on the canvas. That is the "only the first font works
 * in email" report: the first token happened to be a sans called `font-sans`, so its wrong-by-
 * accident Arial looked right.
 *
 * Now every stack LEADS with the real family and falls back on a stack chosen from the catalog's
 * own category for it, so the clients that can load a linked face (Apple Mail, iOS Mail) show
 * what the author picked, and the ones that cannot (Gmail, Outlook) land on exactly the stack
 * they were getting before.
 */
class EmailFontStackTest extends TestCase
{
    use RefreshDatabase;

    private function themed(array $fonts): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-theme-fonts-' . uniqid('', true) . '.json';
        config()->set('heisenberg.theme_path', $path);

        $repo = new ThemeRepository($path);
        $theme = $repo->defaults();
        $theme['fonts'] = $fonts;
        $repo->save($theme);

        $this->app->singleton(ThemeRepository::class, fn (): ThemeRepository => new ThemeRepository($path));
        $this->app->forgetInstance(EmailRenderer::class);
    }

    private function renderWith(string $fontToken): string
    {
        $post = Post::create(['title_en' => 'Fonts', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        Block::create([
            'post_id' => $post->id,
            'type' => 'paragraph',
            'content' => [
                'id' => 'b1',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Body copy'],
                'supports' => ['typography' => ['fontFamily' => $fontToken]],
                'innerBlocks' => [],
            ],
            'order' => 0,
        ]);

        return $this->app->make(EmailRenderer::class)->render($post, 'en')->html;
    }

    public function test_every_theme_font_reaches_the_email_not_just_the_first(): void
    {
        $this->themed([
            ['name' => 'font-sans', 'label' => 'Sans', 'family' => 'Space Grotesk', 'weights' => [400, 700]],
            ['name' => 'font-serif', 'label' => 'Serif', 'family' => 'Geist', 'weights' => [400, 700]],
        ]);

        $this->assertStringContainsString("font-family: 'Space Grotesk', Arial, Helvetica, sans-serif", $this->renderWith('var(--hb-t-font-sans)'));
        $this->assertStringContainsString('font-family: Geist, Arial, Helvetica, sans-serif', $this->renderWith('var(--hb-t-font-serif)'));
    }

    /**
     * The fallback follows what the font IS, not what its token is called. Geist is a sans-serif
     * catalogued as such; its token being named `font-serif` must not drag Georgia in behind it.
     */
    public function test_the_fallback_comes_from_the_catalog_category_not_the_token_name(): void
    {
        $this->themed([
            ['name' => 'font-serif', 'label' => 'Serif', 'family' => 'Geist', 'weights' => [400]],
        ]);
        $html = $this->renderWith('var(--hb-t-font-serif)');

        $this->assertStringContainsString('font-family: Geist, Arial, Helvetica, sans-serif', $html);
        $this->assertStringNotContainsString('Georgia', $html);

        // And a real serif still gets a serif fallback, whatever its token is called.
        $this->themed([
            ['name' => 'body', 'label' => 'Body', 'family' => 'Playfair Display', 'weights' => [400]],
        ]);
        $this->assertStringContainsString(
            "font-family: 'Playfair Display', Georgia, 'Times New Roman', serif",
            $this->renderWith('var(--hb-t-body)'),
        );
    }

    /** A monospace family keeps a monospace fallback. */
    public function test_a_monospace_family_falls_back_to_courier(): void
    {
        $this->themed([
            ['name' => 'code', 'label' => 'Code', 'family' => 'JetBrains Mono', 'weights' => [400]],
        ]);

        $this->assertStringContainsString(
            "font-family: 'JetBrains Mono', 'Courier New', Courier, monospace",
            $this->renderWith('var(--hb-t-code)'),
        );
    }

    /** The linked face is what lets a capable client render the family the stack names first. */
    public function test_the_message_links_the_theme_faces(): void
    {
        $this->themed([
            ['name' => 'font-sans', 'label' => 'Sans', 'family' => 'Space Grotesk', 'weights' => [400, 700]],
        ]);
        $html = $this->renderWith('var(--hb-t-font-sans)');

        $this->assertMatchesRegularExpression('/<link href="https:\/\/fonts\.googleapis\.com\/css2\?[^"]*Space\+Grotesk[^"]*" rel="stylesheet"/', $html);
    }

    /** A theme naming no catalogued family links nothing — a link that fetches nothing is noise. */
    public function test_a_theme_with_no_catalogued_family_links_nothing(): void
    {
        $this->themed([
            ['name' => 'house', 'label' => 'House', 'family' => 'Totally Made Up Face', 'weights' => [400]],
        ]);
        $html = $this->renderWith('var(--hb-t-house)');

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        // Uncatalogued, so the keyword heuristic decides — and the family still leads.
        $this->assertStringContainsString("font-family: 'Totally Made Up Face', Arial, Helvetica, sans-serif", $html);
    }
}
