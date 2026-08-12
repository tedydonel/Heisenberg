<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Ai\EditorPrompt;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\ShortcodeDialect;
use Heisenberg\Services\ThemeRepository;
use Heisenberg\Tests\TestCase;

/**
 * The system prompt is the platform's entire self-knowledge on message 1 (see
 * docs/ai-phase0-findings.md §4) — a model gets no other chance to learn what
 * Heisenberg is, what the dialect looks like, or what's on the page before it
 * has to reply. These tests pin the three load-bearing properties: every
 * registered block is taught with its real attributes (so describe_block is
 * never required for normal authoring), every theme token is taught with the
 * "prefer tokens" rule, and the whole thing still fits a sane budget so it
 * can't silently bloat back into the discovery-spree failure it replaces.
 */
class EditorPromptTest extends TestCase
{
    private function prompt(): EditorPrompt
    {
        return new EditorPrompt(app(BlockRegistryService::class), app(ThemeRepository::class));
    }

    public function test_system_prompt_names_the_platform_and_surface(): void
    {
        $system = $this->prompt()->system();

        $this->assertStringContainsString('Heisenberg', $system);
        $this->assertStringContainsString('AI panel', $system);
        $this->assertStringContainsString('shortcode', $system);
    }

    public function test_system_prompt_documents_every_registered_block_with_its_attributes(): void
    {
        $registry = app(BlockRegistryService::class);
        $system = $this->prompt()->system();

        $blocks = $registry->registry()['blocks'];
        $this->assertNotSame([], $blocks, 'fixture assumption: at least one block is registered');

        // The attribute names shared by every block (anchor, hide*, fill/hug*, ...) are
        // documented once, globally — see EditorPrompt::commonAttributeNames(). Per-block
        // assertions only check the attributes that are genuinely distinctive to that block.
        $common = [];
        foreach ($blocks as $contract) {
            $keys = array_keys($contract['attributes'] ?? []);
            $common = $common === [] ? $keys : array_intersect($common, $keys);
        }

        foreach ($blocks as $contract) {
            $slug = ShortcodeDialect::slugOf((string) $contract['name']);
            $this->assertStringContainsString(
                $slug,
                $system,
                "system prompt should list the '{$slug}' block"
            );

            foreach ($contract['attributes'] ?? [] as $attribute => $def) {
                if (in_array($attribute, $common, true)) {
                    continue; // documented once in the common-attributes line, not per block
                }
                $this->assertStringContainsString(
                    (string) $attribute,
                    $system,
                    "system prompt should mention '{$slug}'s attribute '{$attribute}'"
                );
            }
        }

        // The common attributes are still taught, just once.
        foreach ($common as $attribute) {
            $this->assertStringContainsString((string) $attribute, $system);
        }
    }

    public function test_system_prompt_documents_theme_tokens_and_the_preference_rule(): void
    {
        $theme = app(ThemeRepository::class)->load();
        $system = $this->prompt()->system();

        foreach (array_merge(
            $theme['colors'] ?? [],
            $theme['fontSizes'] ?? [],
            $theme['spaces'] ?? [],
            $theme['radii'] ?? [],
            $theme['fonts'] ?? [],
        ) as $token) {
            $variable = '--' . ThemeRepository::CSS_PREFIX . $token['name'];
            $this->assertStringContainsString(
                $variable,
                $system,
                "system prompt should list the token variable {$variable}"
            );
        }

        $this->assertStringContainsString('var(--hb-t-', $system);
        $this->assertStringContainsString('Prefer', $system);
    }

    public function test_system_prompt_teaches_discovery_discipline(): void
    {
        $system = $this->prompt()->system();

        $this->assertStringContainsString('describe_block', $system);
        $this->assertStringContainsString('Do not spend rounds on discovery', $system);
        $this->assertStringContainsString('document arrives', $system);
    }

    public function test_system_prompt_keeps_output_purity_rules(): void
    {
        $system = $this->prompt()->system();

        $this->assertStringContainsString('no code fences', $system);
        $this->assertStringContainsString('<think>', $system);
    }

    /**
     * The write path is the write_canvas tool — the prompt must route ALL page
     * content through it, and must no longer teach the reply-with-shortcode
     * flow that left content stranded in chat, nor mention the DB post/block
     * write tools the editor surface no longer offers.
     */
    public function test_system_prompt_teaches_the_canvas_tool_as_the_only_write_path(): void
    {
        $system = $this->prompt()->system();

        $this->assertStringContainsString('write_canvas', $system);
        $this->assertStringContainsString('mode="append"', $system);
        $this->assertStringContainsString('mode="replace"', $system);
        $this->assertStringContainsString('ONLY route content takes to the page', $system);

        $this->assertStringNotContainsString('REPLY WITH SHORTCODE', $system);
        $this->assertStringNotContainsString('insert_blocks', $system);
        $this->assertStringNotContainsString('create_post', $system);
    }

    /**
     * The failure this guards against (observed live, 2026-08-09): common
     * attributes were listed by NAME only, so a model guessed `animate=true`,
     * hit the parser, and spent describe_block/render_preview rounds for tens
     * of minutes re-learning what this prompt should have said. Common attrs
     * now carry full type/enum tokens, and every block line names the style
     * attributes it actually supports.
     */
    public function test_system_prompt_teaches_common_attribute_types_and_per_block_styles(): void
    {
        $system = $this->prompt()->system();

        // animate is an enum of animation presets, not a boolean — the enum
        // must be spelled out (fade is the first catalogue entry).
        $this->assertStringContainsString('animate:str(', $system);
        $this->assertStringContainsString('fade', $system);
        $this->assertStringContainsString('animateDelay:int', $system);

        // Every block line carries its styles vocabulary.
        $this->assertStringContainsString('styles:', $system);
        // paragraph supports textAlign but NOT lineHeight — the styles line is
        // per-block truth, not a copy of one global list.
        $this->assertMatchesRegularExpression('/- paragraph \(p\)[^\n]*text-align/', $system);
        $this->assertDoesNotMatchRegularExpression('/- paragraph \(p\)[^\n]*line-height/', $system);

        // And the build rhythm: first call early, section by section.
        $this->assertStringContainsString('BUILD INCREMENTALLY', $system);
        $this->assertStringContainsString('Never call render_preview for the current page', $system);
        $this->assertStringContainsString('set_page_title', $system);
    }

    /**
     * A size guard, not a style opinion: the whole point of this rewrite is that
     * exhaustive per-block prose is what caused the discovery spree in the first
     * place (docs/ai-phase0-findings.md §4). If a future block or token addition
     * pushes this over budget, that's a prompt that needs tightening, not a test
     * that needs loosening.
     *
     * Raised 12000 → 13000 on 2026-08-09 for the typed common-attribute tokens
     * and per-block styles vocabulary: that ~1k of DATA (not prose) replaced the
     * describe_block/render_preview round-trips a live model was spending tens
     * of minutes on. Prose additions still have to fit; only contract data may
     * move this number again.
     *
     * Raised 13000 → 14000 on 2026-08-11 (docs/seo-system.md §6, Wave A1) for the SEO/media
     * section (EditorPrompt::seo()): what get_seo/update_seo/analyze_seo do, meta-length
     * guidance, the analyze->fix->re-analyze workflow, and update_media/set_featured_image —
     * a fixed capability the model has no other way to learn about, same rationale as LOCALES.
     */
    public function test_system_prompt_stays_within_its_size_budget(): void
    {
        $system = $this->prompt()->system();

        $this->assertLessThan(
            14000,
            strlen($system),
            'system prompt has grown past its size budget — tighten it before adding more'
        );
    }

    public function test_user_prompt_still_sends_the_whole_document_unprompted(): void
    {
        $prompt = $this->prompt();

        $withDoc = $prompt->user('Add a heading', ['document' => '[p]Hello[/p]']);
        $this->assertStringContainsString('[p]Hello[/p]', $withDoc);
        // The note anchors write_canvas's two modes to THIS document: append
        // adds after it, replace swaps it for the full updated page.
        $this->assertStringContainsString('write_canvas', $withDoc);
        $this->assertStringContainsString('mode="replace"', $withDoc);

        $empty = $prompt->user('Write an intro', ['document' => '']);
        $this->assertStringContainsString('currently empty', $empty);
        $this->assertStringContainsString('write_canvas', $empty);
    }

    public function test_user_prompt_still_carries_selection_and_title(): void
    {
        $withSelection = $this->prompt()->user('Make this pop', [
            'document' => '[p]Hello[/p]',
            'selection' => 'Hello',
            'blockName' => 'heisenberg/paragraph',
            'title' => 'My Post',
        ]);

        $this->assertStringContainsString('heisenberg/paragraph', $withSelection);
        $this->assertStringContainsString('Hello', $withSelection);
        $this->assertStringContainsString('My Post', $withSelection);
    }
}
