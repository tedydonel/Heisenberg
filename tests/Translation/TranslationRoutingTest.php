<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Support\LocalizedAttributes;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Translations land where they belong and survive edits (docs/content-translation.md §0).
 *
 * Two failures this pins: a translation used to go into whichever locale was on screen (English
 * into French slots), and rebuilding a post from code — which never carries `_<locale>` variants —
 * silently dropped every translation.
 */
class TranslationRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function tree(string $intro, string $outro, array $introFr = [], array $outroFr = []): array
    {
        return [
            ['id' => 'hb1', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => $intro] + $introFr, 'supports' => [], 'innerBlocks' => []],
            ['id' => 'hb2', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => $outro] + $outroFr, 'supports' => [], 'innerBlocks' => []],
        ];
    }

    private function carry(array $old, array $new): array
    {
        $registry = app(BlockRegistryService::class);

        return LocalizedAttributes::carryTranslations($old, $new, fn (string $n): array => $registry->translatableAttributes($n), ['en', 'fr']);
    }

    public function test_a_rebuild_keeps_the_translation_of_unchanged_text(): void
    {
        $old = $this->tree('Hello', 'Bye', ['content_fr' => 'Bonjour'], ['content_fr' => 'Au revoir']);
        $new = $this->tree('Hello', 'Bye');

        $carried = $this->carry($old, $new);

        $this->assertSame('Bonjour', $carried[0]['attributes']['content_fr']);
        $this->assertSame('Au revoir', $carried[1]['attributes']['content_fr']);
    }

    /** A translation of text that was rewritten no longer translates it, so it is not carried. */
    public function test_a_rebuild_drops_the_translation_of_rewritten_text_only(): void
    {
        $old = $this->tree('Hello', 'Bye', ['content_fr' => 'Bonjour'], ['content_fr' => 'Au revoir']);
        $new = $this->tree('Hello there', 'Bye');

        $carried = $this->carry($old, $new);

        $this->assertArrayNotHasKey('content_fr', $carried[0]['attributes']);
        $this->assertSame('Au revoir', $carried[1]['attributes']['content_fr']);
    }

    /** Matched by text, not position: a block that moved keeps its own translation. */
    public function test_a_moved_block_keeps_its_own_translation(): void
    {
        $old = $this->tree('Hello', 'Bye', ['content_fr' => 'Bonjour'], ['content_fr' => 'Au revoir']);
        $new = $this->tree('Bye', 'Hello');

        $carried = $this->carry($old, $new);

        $this->assertSame('Au revoir', $carried[0]['attributes']['content_fr']);
        $this->assertSame('Bonjour', $carried[1]['attributes']['content_fr']);
    }

    public function test_a_variant_the_new_code_spells_out_wins(): void
    {
        $carried = $this->carry(
            $this->tree('Hello', 'Bye', ['content_fr' => 'Bonjour']),
            $this->tree('Hello', 'Bye', ['content_fr' => 'Salut']),
        );

        $this->assertSame('Salut', $carried[0]['attributes']['content_fr']);
    }

    /** update_post rebuilds from code; the French text of every unchanged block survives it. */
    public function test_update_post_keeps_translations_across_a_rebuild_from_code(): void
    {
        $post = Post::create(['title_en' => 'Guide', 'locale' => 'en']);
        foreach ($this->tree('Hello', 'Bye', ['content_fr' => 'Bonjour'], ['content_fr' => 'Au revoir']) as $i => $block) {
            $post->blocks()->create(['type' => 'paragraph', 'content' => $block, 'order' => $i]);
        }

        $result = app(McpToolRegistry::class)->call('update_post', [
            'id' => $post->id,
            'code' => "[p]Hello[/p]\n[p]See you soon[/p]",
        ], McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL);
        $this->assertFalse((bool) ($result['isError'] ?? false), (string) ($result['content'][0]['text'] ?? ''));

        $blocks = $post->fresh()->blocks()->orderBy('order')->get()->pluck('content')->all();
        $this->assertSame('Bonjour', $blocks[0]['attributes']['content_fr'] ?? null, 'unchanged text keeps its translation');
        $this->assertArrayNotHasKey('content_fr', $blocks[1]['attributes'], 'rewritten text is untranslated again');
    }

    public function test_translate_page_requires_a_real_target_and_something_to_translate(): void
    {
        $call = fn (array $args) => app(McpToolRegistry::class)->call('translate_page', $args, McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR);

        $this->assertTrue((bool) ($call(['target_locale' => 'de', 'title' => 'x'])['isError'] ?? false), 'an unknown locale is refused');
        $this->assertTrue((bool) ($call(['target_locale' => 'fr'])['isError'] ?? false), 'nothing to translate is refused');

        $ok = $call(['target_locale' => 'fr', 'code' => '[p]Bonjour[/p]', 'title' => 'Guide']);
        $this->assertFalse((bool) ($ok['isError'] ?? false), (string) ($ok['content'][0]['text'] ?? ''));
        $this->assertSame('fr', json_decode((string) $ok['content'][0]['text'], true)['target_locale']);
    }
}
