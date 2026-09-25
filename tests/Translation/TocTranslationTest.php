<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Ai\TranslationSource;
use Heisenberg\Models\Post;
use Heisenberg\Models\TocEntry;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The translatable table of contents (docs/content-translation.md §0.3).
 *
 * The TOC used to have one `label` per entry, so every language showed the home-language labels
 * and no translation (by hand, or by AI) could reach them. Entries now carry `label_en`/`label_fr`;
 * the list itself (anchors, order) still belongs to the home locale, the same rule blocks follow.
 */
class TocTranslationTest extends TestCase
{
    use RefreshDatabase;

    /** Public routes are opt-in and load at provider boot, so they are switched on here. */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('heisenberg.public.routes', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutCsrfProtection();
    }

    private function makePost(): Post
    {
        $post = Post::create(['title_en' => 'Guide', 'locale' => 'en', 'status' => 'published', 'slug' => 'guide']);
        $post->type = 'post';
        $post->save();
        $this->putJson("/editor/posts/{$post->id}/toc", ['entries' => [
            ['label' => 'Introduction', 'anchor' => 'intro'],
            ['label' => 'Setup', 'anchor' => 'setup'],
        ]])->assertOk();

        return $post->fresh();
    }

    /** @param array<string, mixed> $args */
    private function tool(string $name, array $args): array
    {
        $result = app(McpToolRegistry::class)->call($name, $args, McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL);

        return ['isError' => (bool) ($result['isError'] ?? false), 'data' => json_decode((string) ($result['content'][0]['text'] ?? ''), true), 'text' => (string) ($result['content'][0]['text'] ?? '')];
    }

    public function test_the_home_locale_save_writes_its_own_label_column_and_the_legacy_label(): void
    {
        $entry = $this->makePost()->tocEntries()->first();

        $this->assertSame('Introduction', $entry->label);
        $this->assertSame('Introduction', $entry->label_en);
        $this->assertNull($entry->label_fr);
    }

    public function test_a_translation_save_relabels_without_touching_the_list(): void
    {
        $post = $this->makePost();

        $response = $this->putJson("/editor/posts/{$post->id}/toc", ['locale' => 'fr', 'entries' => [
            ['label' => 'Présentation', 'anchor' => 'intro'],
            ['label' => 'Installation', 'anchor' => 'setup'],
        ]]);

        $response->assertOk();
        $this->assertSame('Présentation', $response->json('entries.0.label'));
        $this->assertSame(['en' => 'Introduction', 'fr' => 'Présentation'], $response->json('entries.0.labels'));
        $this->assertSame(['intro', 'setup'], $post->tocEntries()->pluck('anchor')->all());
        $this->assertSame('Introduction', $post->tocEntries()->first()->label, 'the home label is untouched');
    }

    /** A translation changes text, never structure: an anchor the post does not have is refused. */
    public function test_a_translation_with_an_unknown_anchor_is_refused_and_nothing_is_written(): void
    {
        $post = $this->makePost();

        $this->putJson("/editor/posts/{$post->id}/toc", ['locale' => 'fr', 'entries' => [
            ['label' => 'A', 'anchor' => 'intro'],
            ['label' => 'C', 'anchor' => 'extra'],
        ]])->assertStatus(422)->assertJsonValidationErrors('entries');

        $this->assertSame([null, null], $post->tocEntries()->pluck('label_fr')->all());
    }

    /** A partial translation is fine: the untranslated entry keeps reading as its home label. */
    public function test_a_partial_translation_writes_only_the_labels_it_has(): void
    {
        $post = $this->makePost();

        $response = $this->putJson("/editor/posts/{$post->id}/toc", ['locale' => 'fr', 'entries' => [
            ['label' => 'Présentation', 'anchor' => 'intro'],
        ]]);

        $response->assertOk();
        $this->assertSame(['Présentation', null], $post->tocEntries()->pluck('label_fr')->all());
        $this->assertSame(['Présentation', 'Setup'], array_column($response->json('entries'), 'label'));
        $this->assertSame(['intro', 'setup'], $post->tocEntries()->pluck('anchor')->all(), 'the list is untouched');
    }

    /** Restructuring in the home locale keeps the translation of every entry that survives. */
    public function test_a_home_restructure_keeps_translations_of_surviving_anchors(): void
    {
        $post = $this->makePost();
        $this->putJson("/editor/posts/{$post->id}/toc", ['locale' => 'fr', 'entries' => [
            ['label' => 'Présentation', 'anchor' => 'intro'],
            ['label' => 'Installation', 'anchor' => 'setup'],
        ]])->assertOk();

        $this->putJson("/editor/posts/{$post->id}/toc", ['entries' => [
            ['label' => 'Setup steps', 'anchor' => 'setup'],
            ['label' => 'FAQ', 'anchor' => 'faq'],
        ]])->assertOk();

        $entries = $post->tocEntries()->get()->keyBy('anchor');
        $this->assertSame(['setup', 'faq'], $entries->keys()->all());
        $this->assertSame('Installation', $entries['setup']->label_fr);
        $this->assertSame('Setup steps', $entries['setup']->label_en);
        $this->assertNull($entries['faq']->label_fr);
    }

    public function test_the_public_page_shows_each_locales_label_and_falls_back_to_the_home_label(): void
    {
        $post = $this->makePost();
        $post->tocEntries()->where('anchor', 'intro')->update(['label_fr' => 'Présentation']);

        $fr = $this->get('/posts/en/guide?locale=fr')->assertOk()->getContent();
        $this->assertStringContainsString('Présentation', $fr);
        $this->assertStringContainsString('Setup', $fr, 'an untranslated entry falls back to the home label');

        $en = $this->get('/posts/en/guide')->assertOk()->getContent();
        $this->assertStringContainsString('Introduction', $en);
        $this->assertStringNotContainsString('Présentation', $en);
    }

    public function test_completeness_counts_toc_labels_and_gates_complete(): void
    {
        $post = $this->makePost();
        $post->title_fr = 'Guide';
        $post->save();

        $fr = collect(app(TranslationStatusService::class)->statuses($post->fresh()))->firstWhere('locale', 'fr');
        $this->assertSame(0, $fr['toc_translated']);
        $this->assertSame(2, $fr['toc_total']);
        $this->assertFalse($fr['complete'], 'untranslated TOC labels keep the locale incomplete');

        $post->tocEntries()->update(['label_fr' => 'X']);
        $fr = collect(app(TranslationStatusService::class)->statuses($post->fresh()))->firstWhere('locale', 'fr');
        $this->assertSame(2, $fr['toc_translated']);
        $this->assertTrue($fr['complete']);

        $en = collect(app(TranslationStatusService::class)->statuses($post->fresh()))->firstWhere('locale', 'en');
        $this->assertSame(2, $en['toc_translated'], 'the home locale always has its own labels');
    }

    public function test_create_translation_translates_the_toc_and_get_post_reports_it(): void
    {
        $post = $this->makePost();

        $call = $this->tool('create_translation', ['post_id' => $post->id, 'target_locale' => 'fr', 'toc' => [
            ['anchor' => 'intro', 'label' => 'Présentation'],
            ['anchor' => 'setup', 'label' => 'Installation'],
        ]]);
        $this->assertFalse($call['isError'], $call['text']);
        $this->assertSame(2, $call['data']['toc_translated']);
        $this->assertSame(['Présentation', 'Installation'], $post->tocEntries()->pluck('label_fr')->all());

        $get = $this->tool('get_post', ['id' => $post->id]);
        $this->assertSame([
            ['anchor' => 'intro', 'labels' => ['en' => 'Introduction', 'fr' => 'Présentation']],
            ['anchor' => 'setup', 'labels' => ['en' => 'Setup', 'fr' => 'Installation']],
        ], $get['data']['toc']);
        $this->assertSame(2, $get['data']['translations']['fr']['toc_total']);
    }

    /** An anchor the post does not have refuses the WHOLE call: the title is not written either. */
    public function test_create_translation_refuses_an_unknown_anchor_before_writing_anything(): void
    {
        $post = $this->makePost();

        $call = $this->tool('create_translation', ['post_id' => $post->id, 'target_locale' => 'fr', 'title' => 'Guide FR', 'toc' => [
            ['anchor' => 'nope', 'label' => 'Rien'],
        ]]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('nope', $call['text']);
        $this->assertStringContainsString('intro, setup', $call['text']);
        $this->assertNull($post->fresh()->title_fr);
        $this->assertSame([null, null], $post->tocEntries()->pluck('label_fr')->all());
    }

    /** The saved TOC reaches the model through translation_source, in the home locale's labels. */
    public function test_translation_source_hands_the_model_the_saved_toc(): void
    {
        $source = TranslationSource::fromContext([
            'homeLocale' => 'en',
            'toc' => [
                ['anchor' => 'intro', 'label' => 'Introduction', 'labels' => ['en' => 'Introduction', 'fr' => 'Présentation']],
                ['anchor' => 'setup', 'label' => 'Setup', 'labels' => ['en' => 'Setup', 'fr' => null]],
            ],
        ]);

        $this->assertSame([
            ['anchor' => 'intro', 'label' => 'Introduction'],
            ['anchor' => 'setup', 'label' => 'Setup'],
        ], $source->toc);
    }

    public function test_label_for_falls_back_to_the_home_label(): void
    {
        $entry = new TocEntry(['label' => 'Intro', 'label_en' => 'Intro', 'anchor' => 'intro', 'order' => 0]);

        $this->assertSame('Intro', $entry->labelFor('fr', 'en'));
        $entry->label_fr = 'Présentation';
        $this->assertSame('Présentation', $entry->labelFor('fr', 'en'));
        $this->assertSame('Intro', $entry->labelFor('en', 'en'));
    }
}
