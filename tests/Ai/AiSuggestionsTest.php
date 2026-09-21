<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Http\Controllers\AiController;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;

/**
 * AiController::parseSuggestions() — the panel's quick-insert chips.
 *
 * The owner reported suggestions never appearing at all. The parser only ever
 * accepted a bare JSON array found between the first `[` and the last `]`;
 * anything else — a fenced ```json block, an object wrapping the array, or a
 * model that ignored the "JSON only" instruction and just wrote lines — fell
 * through to an empty list with nothing logged to say why. These tests pin
 * every shape the parser must now accept, that the existing limits (60 chars,
 * 3 suggestions) still hold, and that an unparsable reply is at least logged.
 */
class AiSuggestionsTest extends TestCase
{
    /** @return list<string> */
    private function parse(string $text): array
    {
        $method = new ReflectionMethod(AiController::class, 'parseSuggestions');
        $method->setAccessible(true);

        /** @var list<string> */
        return $method->invoke(app(AiController::class), $text);
    }

    public function test_a_bare_json_array_is_accepted(): void
    {
        $this->assertSame(
            ['Add a call to action', 'Shorten the intro', 'Suggest a headline'],
            $this->parse('["Add a call to action", "Shorten the intro", "Suggest a headline"]'),
        );
    }

    /** Some models wrap the array in an object even when told to reply with only the array. */
    public function test_an_object_wrapping_the_array_is_accepted(): void
    {
        $this->assertSame(
            ['Add a call to action', 'Shorten the intro'],
            $this->parse('{"suggestions": ["Add a call to action", "Shorten the intro"]}'),
        );
    }

    public function test_a_fenced_json_code_block_is_accepted(): void
    {
        $text = "Sure, here you go:\n```json\n[\"Add a call to action\", \"Shorten the intro\"]\n```";

        $this->assertSame(['Add a call to action', 'Shorten the intro'], $this->parse($text));
    }

    public function test_a_fenced_code_block_wrapping_an_object_is_also_accepted(): void
    {
        $text = "```json\n{\"suggestions\": [\"Add a call to action\"]}\n```";

        $this->assertSame(['Add a call to action'], $this->parse($text));
    }

    /** A model that ignored the JSON instruction entirely and wrote bulleted lines instead. */
    public function test_plain_bulleted_lines_are_accepted_as_a_last_resort(): void
    {
        $text = "- Add a call to action\n- Shorten the intro\n- Suggest a headline";

        $this->assertSame(
            ['Add a call to action', 'Shorten the intro', 'Suggest a headline'],
            $this->parse($text),
        );
    }

    public function test_plain_numbered_lines_are_also_accepted(): void
    {
        $text = "1. Add a call to action\n2) Shorten the intro";

        $this->assertSame(['Add a call to action', 'Shorten the intro'], $this->parse($text));
    }

    public function test_the_60_char_limit_still_applies(): void
    {
        $long = str_repeat('a', 90);

        $suggestions = $this->parse((string) json_encode([$long]));

        $this->assertSame(60, strlen($suggestions[0]));
    }

    public function test_at_most_three_are_ever_returned(): void
    {
        $suggestions = $this->parse((string) json_encode(['a', 'b', 'c', 'd', 'e']));

        $this->assertCount(3, $suggestions);
    }

    /**
     * Garbage input — long prose with no JSON and no short lines — must still
     * resolve to an empty list rather than something misleading shown as a chip.
     */
    public function test_unparsable_garbage_returns_an_empty_list(): void
    {
        $garbage = 'I cannot help with generating short follow-up suggestions for this particular '
            . 'conversation because the request as phrased does not give me enough to go on here.';

        $this->assertSame([], $this->parse($garbage));
    }

    public function test_an_empty_reply_returns_an_empty_list(): void
    {
        $this->assertSame([], $this->parse('   '));
    }

    /**
     * The one place this used to fail completely silently: a reply that parses
     * to nothing is now at least logged, at debug level so it can never spam
     * production for what is a best-effort convenience feature (see suggest()).
     */
    public function test_an_unparsable_reply_is_logged_at_debug_level(): void
    {
        Log::spy();

        $this->parse('This reply has no JSON and no short lines in it whatsoever, only long rambling prose that goes on and on.');

        Log::shouldHaveReceived('debug')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'ai suggestions'));
    }

    public function test_a_successfully_parsed_reply_logs_nothing(): void
    {
        Log::spy();

        $this->parse('["Add a call to action"]');

        Log::shouldNotHaveReceived('debug');
    }
}
