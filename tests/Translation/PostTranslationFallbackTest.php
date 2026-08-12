<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `Post::title()`/`excerptText()` — own-locale-first, cross-locale-fallback resolution
 * (docs/content-translation.md §2, mirrors `PublicFile::getAlt()`'s posture).
 */
class PostTranslationFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_reads_its_own_locale_column_first(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
        ]);

        $this->assertSame('Bonjour', $post->title());
    }

    public function test_title_falls_back_to_the_other_locale_when_its_own_is_empty(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $this->assertSame('Hello', $en->title());

        // fr row with no title_fr set falls back to title_en.
        $fr = Post::create(['title_en' => 'Hello', 'locale' => 'fr', 'status' => 'draft']);
        $this->assertSame('Hello', $fr->title());
    }

    public function test_title_accepts_an_explicit_locale_override(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'en', 'status' => 'draft',
        ]);

        $this->assertSame('Hello', $post->title('en'));
        $this->assertSame('Bonjour', $post->title('fr'));
    }

    public function test_title_never_returns_null_falling_back_to_empty_string(): void
    {
        $post = new Post(['locale' => 'en']);

        $this->assertSame('', $post->title());
    }

    public function test_excerpt_text_reads_its_own_locale_column_first(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'excerpt_en' => 'An excerpt', 'excerpt_fr' => 'Un extrait',
            'locale' => 'fr', 'status' => 'draft',
        ]);

        $this->assertSame('Un extrait', $post->excerptText());
    }

    public function test_excerpt_text_falls_back_to_the_other_locale(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'excerpt_en' => 'An excerpt', 'locale' => 'fr', 'status' => 'draft',
        ]);

        $this->assertSame('An excerpt', $post->excerptText());
    }

    public function test_excerpt_text_is_null_when_neither_locale_has_one(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $this->assertNull($post->excerptText());
    }
}
