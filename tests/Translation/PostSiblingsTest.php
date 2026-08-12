<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `Post::sibling()`/`siblings()` + `scopeForLocale()`/`scopeInGroup()`
 * (docs/content-translation.md §2, ported from blueprint §2.3.1).
 */
class PostSiblingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sibling_with_no_locale_returns_the_other_row_in_the_group(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $this->assertTrue($en->sibling()->is($fr));
        $this->assertTrue($fr->sibling()->is($en));
    }

    public function test_sibling_with_a_locale_returns_that_locale_or_null(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $this->assertNull($en->sibling('fr'));

        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $this->assertTrue($en->sibling('fr')->is($fr));
        $this->assertNull($en->sibling('de'));
    }

    public function test_sibling_never_returns_the_row_itself(): void
    {
        $solo = Post::create(['title_en' => 'Solo', 'locale' => 'en', 'status' => 'draft']);

        $this->assertNull($solo->sibling());
        $this->assertNull($solo->sibling('en'));
    }

    public function test_siblings_returns_every_other_row_in_the_group_excluding_self(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $siblings = $en->siblings();

        $this->assertCount(1, $siblings);
        $this->assertTrue($siblings->first()->is($fr));

        // An unrelated post (different group) never shows up.
        Post::create(['title_en' => 'Unrelated', 'locale' => 'en', 'status' => 'draft']);
        $this->assertCount(1, $en->fresh()->siblings());
    }

    public function test_siblings_is_empty_for_an_untranslated_post(): void
    {
        $solo = Post::create(['title_en' => 'Solo', 'locale' => 'en', 'status' => 'draft']);

        $this->assertCount(0, $solo->siblings());
    }

    public function test_scope_for_locale_filters_by_locale(): void
    {
        Post::create(['title_en' => 'A', 'locale' => 'en', 'status' => 'draft']);
        Post::create(['title_en' => 'B', 'title_fr' => 'B fr', 'locale' => 'fr', 'status' => 'draft']);

        $frOnly = Post::query()->forLocale('fr')->get();

        $this->assertCount(1, $frOnly);
        $this->assertSame('fr', $frOnly->first()->locale);
    }

    public function test_scope_in_group_filters_by_translation_group_id(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);
        Post::create(['title_en' => 'Unrelated', 'locale' => 'en', 'status' => 'draft']);

        $group = Post::query()->inGroup($en->translation_group_id)->get();

        $this->assertCount(2, $group);
    }
}
