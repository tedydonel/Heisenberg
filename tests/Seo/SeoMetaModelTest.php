<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * SeoMeta domain coverage: the migration/model, unique-per-entity enforcement, the
 * own-locale-first/cross-locale-fallback accessors (PublicFile::getAlt()'s posture), and
 * getJsonLd() (defaults, schema_data override, empty model). Mirrors
 * NativeCommentProviderTest's style for this namespace (docs/seo-system.md Wave S1).
 */
class SeoMetaModelTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(): Post
    {
        return Post::create(['title_en' => 'A post', 'status' => 'draft']);
    }

    // -- Migration --

    public function test_the_migration_creates_the_seo_meta_table_with_the_expected_columns(): void
    {
        $table = config('heisenberg.tables.seo_meta', 'seo_meta');

        $this->assertTrue(Schema::hasTable($table));
        $this->assertTrue(Schema::hasColumns($table, [
            'id', 'able_type', 'able_id',
            'meta_title_en', 'meta_title_fr', 'meta_description_en', 'meta_description_fr',
            'og_image', 'canonical_url', 'robots', 'schema_type', 'schema_data',
            'og_title_en', 'og_title_fr', 'og_description_en', 'og_description_fr',
            'focus_keyphrase_en', 'focus_keyphrase_fr', 'in_sitemap',
            'created_at', 'updated_at',
        ]));
    }

    public function test_the_table_name_is_unprefixed_seo_meta_by_default(): void
    {
        $this->assertSame('seo_meta', (new SeoMeta())->getTable());
    }

    public function test_robots_defaults_to_index_follow(): void
    {
        $post = $this->makePost();

        $row = SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()]);

        $this->assertSame('index, follow', $row->fresh()->robots);
        $this->assertTrue($row->fresh()->in_sitemap);
    }

    // -- Uniqueness --

    public function test_a_second_row_for_the_same_entity_is_rejected(): void
    {
        $post = $this->makePost();
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()]);

        $this->expectException(QueryException::class);
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()]);
    }

    public function test_update_or_create_keeps_one_row_per_entity(): void
    {
        $post = $this->makePost();

        SeoMeta::query()->updateOrCreate(
            ['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()],
            ['meta_title_en' => 'First']
        );
        SeoMeta::query()->updateOrCreate(
            ['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()],
            ['meta_title_en' => 'Second']
        );

        $this->assertSame(1, SeoMeta::query()->count());
        $this->assertSame('Second', SeoMeta::query()->first()->meta_title_en);
    }

    // -- able() relation --

    public function test_able_resolves_the_owning_post(): void
    {
        $post = $this->makePost();
        $row = SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()]);

        $this->assertTrue($row->able()->getResults()->is($post));
    }

    // -- Locale accessors: own-locale first, cross-locale fallback, null when both empty --

    public function test_meta_title_prefers_the_requested_locale(): void
    {
        $row = new SeoMeta(['meta_title_en' => 'English title', 'meta_title_fr' => 'Titre français']);

        $this->assertSame('English title', $row->metaTitle('en'));
        $this->assertSame('Titre français', $row->metaTitle('fr'));
    }

    public function test_meta_title_falls_back_to_the_other_locale_when_own_is_empty(): void
    {
        $enOnly = new SeoMeta(['meta_title_en' => 'English title']);
        $this->assertSame('English title', $enOnly->metaTitle('fr'));

        $frOnly = new SeoMeta(['meta_title_fr' => 'Titre français']);
        $this->assertSame('Titre français', $frOnly->metaTitle('en'));
    }

    public function test_meta_title_is_null_when_both_locales_are_empty(): void
    {
        $row = new SeoMeta();

        $this->assertNull($row->metaTitle('en'));
        $this->assertNull($row->metaTitle('fr'));
    }

    public function test_every_locale_accessor_follows_the_same_fallback_posture(): void
    {
        $row = new SeoMeta([
            'meta_description_en' => 'EN description',
            'og_title_fr' => 'Titre OG',
            'og_description_en' => 'EN OG description',
            'focus_keyphrase_fr' => 'mot-clé',
        ]);

        $this->assertSame('EN description', $row->metaDescription('en'));
        $this->assertSame('EN description', $row->metaDescription('fr')); // fallback

        $this->assertSame('Titre OG', $row->ogTitle('fr'));
        $this->assertSame('Titre OG', $row->ogTitle('en')); // fallback

        $this->assertSame('EN OG description', $row->ogDescription('en'));
        $this->assertSame('mot-clé', $row->focusKeyphrase('en')); // fallback
        $this->assertSame('mot-clé', $row->focusKeyphrase('fr'));
    }

    public function test_locale_accessor_treats_an_unknown_locale_as_english(): void
    {
        $row = new SeoMeta(['meta_title_en' => 'English title']);

        $this->assertSame('English title', $row->metaTitle('de'));
    }

    // -- getJsonLd() --

    public function test_get_json_ld_builds_defaults_from_title_description_and_image(): void
    {
        $row = new SeoMeta([
            'meta_title_en' => 'A great post',
            'meta_description_en' => 'A great description',
            'og_image' => 'https://cdn.example/hero.jpg',
        ]);

        $jsonLd = $row->getJsonLd('en', 'https://example.com/posts/a-great-post');

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'A great post',
            'description' => 'A great description',
            'image' => 'https://cdn.example/hero.jpg',
            'url' => 'https://example.com/posts/a-great-post',
        ], $jsonLd);
    }

    public function test_get_json_ld_uses_schema_type_when_set(): void
    {
        $row = new SeoMeta(['meta_title_en' => 'A post', 'schema_type' => 'NewsArticle']);

        $this->assertSame('NewsArticle', $row->getJsonLd('en')['@type']);
    }

    public function test_get_json_ld_omits_url_when_none_given(): void
    {
        $row = new SeoMeta(['meta_title_en' => 'A post']);

        $this->assertArrayNotHasKey('url', $row->getJsonLd('en'));
    }

    public function test_stored_schema_data_keys_win_over_computed_defaults(): void
    {
        $row = new SeoMeta([
            'meta_title_en' => 'A great post',
            'schema_data' => ['headline' => 'A hand-authored headline', 'author' => ['@type' => 'Person', 'name' => 'A. Writer']],
        ]);

        $jsonLd = $row->getJsonLd('en');

        $this->assertSame('A hand-authored headline', $jsonLd['headline']);
        $this->assertSame(['@type' => 'Person', 'name' => 'A. Writer'], $jsonLd['author']);
    }

    public function test_get_json_ld_returns_empty_array_when_the_model_has_nothing_meaningful(): void
    {
        $row = new SeoMeta();

        $this->assertSame([], $row->getJsonLd('en'));
    }

    public function test_get_json_ld_is_not_empty_when_only_schema_data_is_set(): void
    {
        $row = new SeoMeta(['schema_data' => ['author' => 'A. Writer']]);

        $jsonLd = $row->getJsonLd('en');

        $this->assertNotSame([], $jsonLd);
        $this->assertSame('A. Writer', $jsonLd['author']);
        $this->assertSame('Article', $jsonLd['@type']); // still gets the default @type
    }
}
