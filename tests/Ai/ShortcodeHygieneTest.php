<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Ai\EditorPrompt;
use Heisenberg\Mcp\Tools\CanvasTools;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\ShortcodeParser;
use Heisenberg\Services\ShortcodeSerializer;
use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a model writes into a text field is not always what it means. Two shapes reached real
 * canvases and both read as "the AI put code on my page":
 *
 *  - `\n` inside a quoted value. Every JSON-shaped language spells a line break that way; the
 *    dialect never defined it, so a whole list arrived as ONE item with visible backslash-n's.
 *  - markdown ("- item", "# heading", "**bold**") inside a paragraph. It never renders.
 *
 * The first is READ as the whitespace it was meant to be (deterministic, and the same on the
 * browser side — tests/js/canvas-pipeline-harness.mjs). The second is REJECTED, in strict mode
 * only, with a message that names the fix, because the parse errors travel back to the model
 * through the tool channel and nothing is applied to the canvas until it resends real blocks.
 */
class ShortcodeHygieneTest extends TestCase
{
    private const BS = '\\';

    private function parser(): ShortcodeParser
    {
        return new ShortcodeParser(app(BlockRegistryService::class));
    }

    /** @return list<string> */
    private function messages(array $parsed): array
    {
        return array_map(static fn (array $e): string => "line {$e['line']}: {$e['message']}", $parsed['errors']);
    }

    // -- normalization ------------------------------------------------------------------------

    public function test_a_backslash_n_inside_a_quoted_value_is_a_line_break(): void
    {
        $code = '[list content="First point' . self::BS . 'nSecond point' . self::BS . 'nThird point" /]';

        $parsed = $this->parser()->parse($code);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame("First point\nSecond point\nThird point", $parsed['blocks'][0]['attributes']['content']);
    }

    public function test_a_backslash_n_inside_a_body_is_a_line_break(): void
    {
        $parsed = $this->parser()->parse('[p]Line one' . self::BS . 'nLine two[/p]');

        $this->assertSame("Line one\nLine two", $parsed['blocks'][0]['attributes']['content']);
    }

    public function test_an_escaped_backslash_still_means_a_backslash(): void
    {
        // `\\n` is how a real backslash + n is written, so it must survive.
        $parsed = $this->parser()->parse('[list content="C:' . self::BS . self::BS . 'new" /]');

        $this->assertSame('C:' . self::BS . 'new', $parsed['blocks'][0]['attributes']['content']);
    }

    public function test_a_real_backslash_n_round_trips_through_the_serializer(): void
    {
        $model = ['name' => 'heisenberg/list', 'attributes' => ['content' => 'path ' . self::BS . 'n here'], 'supports' => [], 'innerBlocks' => []];

        $code = app(ShortcodeSerializer::class)->serialize([$model]);
        $back = $this->parser()->parse($code);

        $this->assertSame([], $back['errors']);
        $this->assertSame('path ' . self::BS . 'n here', $back['blocks'][0]['attributes']['content']);
    }

    // -- the gate (strict) --------------------------------------------------------------------

    /** The damaged shape from a real canvas: one paragraph holding a heading, bullets and escapes. */
    public function test_the_reported_email_shape_is_rejected_with_the_fix(): void
    {
        $bs = self::BS;
        $code = "[p]What happens next{$bs}n- Carefully review your approval letter.{$bs}n- Check your passport.[/p]";

        $errors = $this->messages($this->parser()->parse($code, strict: true));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('markdown list', $errors[0]);
        $this->assertStringContainsString('[list]', $errors[0]);
        $this->assertStringContainsString('line 1', $errors[0]);
    }

    public function test_dashes_in_list_block_items_are_rejected_too(): void
    {
        $errors = $this->messages($this->parser()->parse("[list content=\"- one\n- two\" /]", strict: true));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('no leading', $errors[0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function markdownShapes(): iterable
    {
        yield 'numbered list' => ["[p]Steps\n1. Open it\n2. Close it[/p]", 'markdown list'];
        yield 'asterisk list' => ["[p]* one\n* two[/p]", 'markdown list'];
        yield 'bullet glyph' => ['[p]• one[/p]', 'markdown list'];
        yield 'markdown heading' => ['[p]## What next[/p]', 'starting with "#"'];
        yield 'bold markers' => ['[p]This is **important** news[/p]', '**bold**'];
        yield 'code fence' => ["[p]```\ncode\n```[/p]", 'code fence'];
        yield 'markdown in a heading block' => ['[h2]# Title[/h2]', 'starting with "#"'];
    }

    /** @dataProvider markdownShapes */
    #[DataProvider('markdownShapes')]
    public function test_markdown_is_rejected_in_strict_mode(string $code, string $needle): void
    {
        $errors = $this->messages($this->parser()->parse($code, strict: true));

        $this->assertNotSame([], $errors, 'markdown was accepted');
        $this->assertStringContainsString($needle, implode(' ', $errors));
    }

    public function test_the_same_markdown_is_left_alone_outside_strict_mode(): void
    {
        // Hand-typed Code view: a person typing "- " in a paragraph is entitled to it.
        $parsed = $this->parser()->parse('[p]- deliberate dash[/p]');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('- deliberate dash', $parsed['blocks'][0]['attributes']['content']);
    }

    public function test_clean_prose_and_real_blocks_pass_strict_mode(): void
    {
        $code = <<<'SC'
        [h2]What happens next[/h2]
        [list content="Carefully review your approval letter.
        Check your passport carries the visa.
        Keep copies of every document." /]
        [p]Well-known fact: e-mail beats post — a 5 - 3 sum, a hyphen - here, and <strong>bold</strong> text.[/p]
        SC;

        $parsed = $this->parser()->parse($code, strict: true);

        $this->assertSame([], $parsed['errors'], implode('; ', $this->messages($parsed)));
        $this->assertCount(3, $parsed['blocks']);
    }

    // -- where it is enforced -----------------------------------------------------------------

    public function test_write_canvas_rejects_markdown_so_nothing_reaches_the_canvas(): void
    {
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('markdown list');

        app(CanvasTools::class)->call('write_canvas', ['code' => "[p]Intro\n- one\n- two[/p]"], 'editor');
    }

    public function test_write_canvas_accepts_the_same_content_as_real_blocks(): void
    {
        $result = app(CanvasTools::class)->call('write_canvas', [
            'code' => "[p]Intro[/p]\n[list content=\"one\ntwo\" /]",
        ], 'editor');

        $this->assertTrue($result['applied']);
        $this->assertSame(2, $result['blocks']);
    }

    public function test_the_prompt_tells_the_model_the_rule_up_front(): void
    {
        $prompt = app(EditorPrompt::class)->system();

        $this->assertStringContainsString('NO MARKDOWN', $prompt);
        $this->assertStringContainsString('[list] block', $prompt);
        $this->assertStringContainsString('never the characters backslash-n', $prompt);
    }
}
