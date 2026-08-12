<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `TranslationStatusService::statuses()` (docs/content-translation.md §2, §9): one row per
 * configured locale, one of source|missing|draft|published|outdated.
 */
class TranslationStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TranslationStatusService
    {
        return new TranslationStatusService();
    }

    public function test_the_rows_own_locale_reports_source(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $rows = $this->service()->statuses($en);
        $byLocale = collect($rows)->keyBy('locale');

        $this->assertSame('source', $byLocale['en']['status']);
        $this->assertSame($en->getKey(), $byLocale['en']['post_id']);
    }

    public function test_an_untranslated_locale_reports_missing(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $rows = $this->service()->statuses($en);
        $byLocale = collect($rows)->keyBy('locale');

        $this->assertSame('missing', $byLocale['fr']['status']);
        $this->assertNull($byLocale['fr']['post_id']);
    }

    public function test_a_draft_sibling_reports_draft(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $byLocale = collect($this->service()->statuses($en))->keyBy('locale');

        $this->assertSame('draft', $byLocale['fr']['status']);
        $this->assertSame($fr->getKey(), $byLocale['fr']['post_id']);
    }

    public function test_a_published_sibling_reports_published(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'published',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $byLocale = collect($this->service()->statuses($en))->keyBy('locale');

        $this->assertSame('published', $byLocale['fr']['status']);
    }

    public function test_an_outdated_sibling_reports_outdated_even_when_published(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'published',
            'translation_group_id' => $en->translation_group_id,
        ]);
        // content_version isn't fillable — read it off a fresh reload, not the just-created
        // in-memory instance (see PostTranslationOutdatedTest::pair()'s docblock for why).
        $fr->translated_from_version = $en->fresh()->content_version;
        $fr->save();

        $en->bumpContentVersion();

        $byLocale = collect($this->service()->statuses($en->fresh()))->keyBy('locale');

        $this->assertSame('outdated', $byLocale['fr']['status']);
        $this->assertSame($fr->getKey(), $byLocale['fr']['post_id']);
    }

    public function test_statuses_cover_exactly_the_configured_locales_in_order(): void
    {
        config(['heisenberg.locales' => ['en', 'fr']]);
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $locales = array_column($this->service()->statuses($en), 'locale');

        $this->assertSame(['en', 'fr'], $locales);
    }
}
