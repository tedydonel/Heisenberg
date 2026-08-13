<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `TranslationStatusService::statuses()` (docs/content-translation.md §0) — per-locale
 * COMPLETENESS for a single-row post, replacing the split-row `source|missing|draft|published|
 * outdated` sibling-status shape (see the deleted PostSiblingsTest/PostTranslationOutdatedTest/
 * TranslationStatusServiceTest history for that model).
 */
class TranslationStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TranslationStatusService
    {
        return new TranslationStatusService();
    }

    private function heading(string $text): array
    {
        return ['name' => 'heisenberg/heading', 'attributes' => ['content' => $text]];
    }

    public function test_a_post_with_only_default_locale_content_is_complete_for_the_default_locale_and_incomplete_elsewhere(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'heading', 'content' => $this->heading('Hello world'), 'order' => 0]);

        $byLocale = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale');

        $en = $byLocale['en'];
        $this->assertTrue($en['is_default']);
        $this->assertTrue($en['title']);
        $this->assertSame(1, $en['blocks_total']);
        $this->assertSame(1, $en['blocks_translated']);
        $this->assertTrue($en['complete']);

        $fr = $byLocale['fr'];
        $this->assertFalse($fr['is_default']);
        $this->assertFalse($fr['title']);
        $this->assertSame(1, $fr['blocks_total']);
        $this->assertSame(0, $fr['blocks_translated']);
        $this->assertFalse($fr['complete']);
    }

    public function test_a_fully_translated_post_is_complete_in_both_locales(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'en', 'status' => 'draft']);
        $post->blocks()->create([
            'type' => 'heading',
            'content' => ['name' => 'heisenberg/heading', 'attributes' => ['content' => 'Hello world', 'content_fr' => 'Bonjour le monde']],
            'order' => 0,
        ]);

        $byLocale = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale');

        $this->assertTrue($byLocale['en']['complete']);
        $this->assertTrue($byLocale['fr']['complete']);
        $this->assertSame(1, $byLocale['fr']['blocks_translated']);
    }

    public function test_a_partially_translated_post_reports_the_correct_ratio_and_is_not_complete(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'en', 'status' => 'draft']);
        $post->blocks()->create([
            'type' => 'heading',
            'content' => ['name' => 'heisenberg/heading', 'attributes' => ['content' => 'One', 'content_fr' => 'Un']],
            'order' => 0,
        ]);
        $post->blocks()->create([
            // No content_fr variant: this block is untranslated.
            'type' => 'paragraph',
            'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Two']],
            'order' => 1,
        ]);

        $fr = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale')['fr'];

        $this->assertSame(2, $fr['blocks_total']);
        $this->assertSame(1, $fr['blocks_translated']);
        $this->assertFalse($fr['complete']);
        // Title WAS translated — only the block count is holding completeness back.
        $this->assertTrue($fr['title']);
    }

    public function test_nested_inner_blocks_are_walked_and_counted(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'en', 'status' => 'draft']);
        $post->blocks()->create([
            'type' => 'group',
            'content' => [
                'name' => 'heisenberg/group',
                'attributes' => [],
                'innerBlocks' => [
                    ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Nested', 'content_fr' => 'Imbriqué']],
                ],
            ],
            'order' => 0,
        ]);

        $byLocale = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale');

        $this->assertSame(1, $byLocale['fr']['blocks_total']);
        $this->assertSame(1, $byLocale['fr']['blocks_translated']);
    }

    public function test_an_unused_optional_translatable_attribute_never_blocks_completeness(): void
    {
        // titleAttr is translatable on every contract but is empty here — it must not count
        // against `en` (its own default locale) or force the block into blocks_total at all.
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $post->blocks()->create([
            'type' => 'separator',
            'content' => ['name' => 'heisenberg/separator', 'attributes' => []],
            'order' => 0,
        ]);

        $en = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale')['en'];

        $this->assertSame(0, $en['blocks_total']);
        $this->assertSame(0, $en['blocks_translated']);
        $this->assertTrue($en['complete']);
    }

    public function test_excerpt_does_not_gate_completeness_when_unused_in_every_locale(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'en', 'status' => 'draft']);

        $byLocale = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale');

        $this->assertFalse($byLocale['en']['excerpt']);
        $this->assertTrue($byLocale['en']['complete']);
        $this->assertFalse($byLocale['fr']['excerpt']);
        $this->assertTrue($byLocale['fr']['complete']);
    }

    public function test_excerpt_gates_completeness_once_it_is_used_in_any_locale(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'excerpt_en' => 'A summary',
            'locale' => 'en', 'status' => 'draft',
        ]);

        $byLocale = collect($this->service()->statuses($post->fresh(['blocks'])))->keyBy('locale');

        $this->assertTrue($byLocale['en']['excerpt']);
        $this->assertTrue($byLocale['en']['complete']);
        $this->assertFalse($byLocale['fr']['excerpt']);
        $this->assertFalse($byLocale['fr']['complete']);
    }

    public function test_statuses_cover_exactly_the_configured_locales_in_order(): void
    {
        config(['heisenberg.locales' => ['en', 'fr']]);
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $locales = array_column($this->service()->statuses($post), 'locale');

        $this->assertSame(['en', 'fr'], $locales);
    }
}
