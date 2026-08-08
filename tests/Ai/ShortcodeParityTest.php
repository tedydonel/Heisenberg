<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\ShortcodeParser;
use Heisenberg\Services\ShortcodeSerializer;
use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The shortcode dialect exists twice — as JavaScript in the editor's Code view
 * and as PHP for the inbound MCP server — because one runs in a browser with no
 * round trip and the other on a server with no browser. Two implementations of
 * one grammar drift unless something holds them together.
 *
 * That something is `tests/Fixtures/shortcode/*.txt`: this test round-trips every
 * fixture through the PHP pair, and `tests/js/code-editor-matrix.mjs` runs the
 * same files through the JavaScript pair. A fixture that stops being byte-stable
 * on either side fails there.
 *
 * Byte stability is the assertion, not "looks equivalent": the serializer's
 * output is what an external AI reads back and edits, so a stray space is a diff
 * in somebody's document.
 */
class ShortcodeParityTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/shortcode';

    private function parser(): ShortcodeParser
    {
        return new ShortcodeParser(app(BlockRegistryService::class));
    }

    private function serializer(): ShortcodeSerializer
    {
        return new ShortcodeSerializer(app(BlockRegistryService::class));
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.txt') ?: [] as $path) {
            yield basename($path, '.txt') => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function test_a_fixture_parses_without_errors(string $path): void
    {
        $result = $this->parser()->parse(file_get_contents($path));

        $this->assertSame([], $result['errors'], 'fixture should be valid: ' . basename($path));
        $this->assertNotSame([], $result['blocks'], 'fixture should produce blocks: ' . basename($path));
    }

    /**
     * parse -> serialize must reproduce the fixture byte for byte. This is the
     * property the JS matrix asserts too, over the same files.
     */
    #[DataProvider('fixtures')]
    public function test_a_fixture_round_trips_byte_for_byte(string $path): void
    {
        $source = file_get_contents($path);
        $parsed = $this->parser()->parse($source);

        $this->assertSame($source, $this->serializer()->serialize($parsed['blocks']), basename($path));
    }

    /** Re-serializing already-canonical output must be a no-op. */
    #[DataProvider('fixtures')]
    public function test_serialization_is_idempotent(string $path): void
    {
        $parser = $this->parser();
        $serializer = $this->serializer();

        $once = $serializer->serialize($parser->parse(file_get_contents($path))['blocks']);
        $twice = $serializer->serialize($parser->parse($once)['blocks']);

        $this->assertSame($once, $twice, basename($path));
    }

    public function test_tag_aliases_resolve_to_real_contracts_with_the_level_on_the_tag(): void
    {
        $result = $this->parser()->parse("[h3]Title[/h3]\n\n[p]Body[/p]\n");

        $this->assertSame([], $result['errors']);
        $this->assertSame('heisenberg/heading', $result['blocks'][0]['name']);
        $this->assertSame(3, $result['blocks'][0]['attributes']['level']);
        $this->assertSame('heisenberg/paragraph', $result['blocks'][1]['name']);
    }

    public function test_a_box_shorthand_expands_css_style(): void
    {
        $result = $this->parser()->parse('[p padding="1px 2px 3px 4px"]x[/p]');
        $padding = $result['blocks'][0]['supports']['spacing']['padding'];

        $this->assertSame(
            ['top' => '1px', 'right' => '2px', 'bottom' => '3px', 'left' => '4px'],
            $padding,
        );
    }

    public function test_a_two_value_box_shorthand_pairs_vertically_and_horizontally(): void
    {
        $result = $this->parser()->parse('[p padding="4px 8px"]x[/p]');

        $this->assertSame(
            ['top' => '4px', 'right' => '8px', 'bottom' => '4px', 'left' => '8px'],
            $result['blocks'][0]['supports']['spacing']['padding'],
        );
    }

    public function test_a_state_prefix_writes_under_the_states_path(): void
    {
        $result = $this->parser()->parse('[p hover:color=#123456]x[/p]');

        $this->assertSame('#123456', $result['blocks'][0]['supports']['states']['hover']['color']['text']);
    }

    /**
     * `states.300.…` would round-trip forever and never emit any CSS, so it has
     * to be an error rather than a stored value.
     */
    public function test_a_long_form_state_path_naming_no_real_state_is_rejected(): void
    {
        $result = $this->parser()->parse('[p states.300.color.text=#fff]x[/p]');

        $this->assertNotSame([], $result['errors']);
    }

    public function test_an_unknown_block_is_reported_with_its_line(): void
    {
        $result = $this->parser()->parse("[p]ok[/p]\n\n[nope]bad[/nope]\n");

        $this->assertCount(1, $result['errors']);
        $this->assertSame(3, $result['errors'][0]['line']);
        $this->assertStringContainsString('nope', $result['errors'][0]['message']);
    }

    public function test_an_unclosed_tag_is_reported(): void
    {
        $result = $this->parser()->parse("[group]\n  [p]x[/p]\n");

        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('never closed', $result['errors'][0]['message']);
    }

    public function test_a_block_that_cannot_have_children_reports_it(): void
    {
        $result = $this->parser()->parse("[p]\n  [h2]nope[/h2]\n[/p]");

        $this->assertNotSame([], $result['errors']);
    }

    public function test_content_outside_any_block_is_reported(): void
    {
        $result = $this->parser()->parse("stray words\n\n[p]ok[/p]");

        $this->assertNotSame([], $result['errors']);
        $this->assertSame(1, $result['errors'][0]['line']);
    }

    /**
     * Errors point at the attribute's own line: a pretty-printed tag spans
     * several lines, so the tag's first line is usually the wrong one to blame.
     */
    public function test_an_attribute_error_points_at_the_attributes_own_line(): void
    {
        $result = $this->parser()->parse("[h2\n  font-size=20px\n  bogus-attr=1\n]\n  Title\n[/h2]");

        $this->assertNotSame([], $result['errors']);
        $this->assertSame(3, $result['errors'][0]['line']);
    }

    public function test_default_values_are_omitted_from_serialized_output(): void
    {
        $serialized = $this->serializer()->serialize([[
            'name' => 'heisenberg/heading',
            // level 2 is the tag itself; anchor is empty, matching its default.
            'attributes' => ['content' => 'Title', 'level' => 2, 'anchor' => ''],
            'supports' => [],
            'innerBlocks' => [],
        ]]);

        $this->assertSame("[h2]\n  Title\n[/h2]\n", $serialized);
    }

    /** `layers` is inspector editing state, re-synthesized on selection. */
    public function test_layers_keys_never_serialize(): void
    {
        $serialized = $this->serializer()->serialize([[
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x'],
            'supports' => ['color' => ['text' => '#fff', 'layers' => [['a' => 1]]]],
            'innerBlocks' => [],
        ]]);

        $this->assertStringNotContainsString('layers', $serialized);
        $this->assertStringContainsString('color=#fff', $serialized);
    }

    /**
     * PHP casts booleans to "1"/"" and floats keep a trailing ".0"; JavaScript
     * does neither. Both would silently change serialized output.
     */
    public function test_javascript_value_stringification_is_matched(): void
    {
        $this->assertSame('true', \Heisenberg\Services\ShortcodeDialect::jsString(true));
        $this->assertSame('false', \Heisenberg\Services\ShortcodeDialect::jsString(false));
        $this->assertSame('3', \Heisenberg\Services\ShortcodeDialect::jsString(3.0));
        $this->assertSame('3.5', \Heisenberg\Services\ShortcodeDialect::jsString(3.5));
        // JSON.stringify does not escape forward slashes; json_encode does by default.
        $this->assertSame('{"u":"a/b"}', \Heisenberg\Services\ShortcodeDialect::jsJson(['u' => 'a/b']));
        // Number("") is 0 in JavaScript, not NaN.
        $this->assertSame(0, \Heisenberg\Services\ShortcodeDialect::jsNumber(''));
        $this->assertNull(\Heisenberg\Services\ShortcodeDialect::jsNumber('abc'));
        $this->assertSame(12, \Heisenberg\Services\ShortcodeDialect::jsNumber(' 12 '));
    }
}
