<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * Strips a model's visible reasoning scaffolding out of user-facing text.
 *
 * Many open-weight models — DeepSeek-R1 derivatives, Qwen, MiniMax, GLM and the
 * long tail served through OpenAI-compatible gateways — emit their chain of
 * thought inline as `<think>…</think>` rather than in a separate field. The
 * Anthropic API keeps thinking in its own block type, so this never shows up
 * there; on every other endpoint it lands straight in the response card, which
 * is what the panel used to display verbatim.
 *
 * Prompting alone does not fix it — the tag is emitted by the serving template,
 * not chosen by the model — so it is filtered at the boundary instead.
 *
 * An UNCLOSED opening tag is treated as "everything after it is reasoning",
 * which is the right call mid-stream: the thought is still being written, and
 * showing half of it is worse than showing none.
 */
class ReasoningFilter
{
    /** Tag names models use for inline reasoning. */
    private const TAGS = ['think', 'thinking', 'reasoning', 'reflection'];

    public static function strip(string $text): string
    {
        $names = implode('|', self::TAGS);

        // Closed blocks anywhere in the text.
        $text = (string) preg_replace("#<({$names})\b[^>]*>.*?</\\1\s*>#is", '', $text);

        // An opening tag with no close yet: drop the remainder.
        $text = (string) preg_replace("#<({$names})\b[^>]*>.*$#is", '', $text);

        // A stray closing tag can arrive first when a gateway trims the opener.
        $text = (string) preg_replace("#^.*?</({$names})\s*>#is", '', $text);

        return trim($text);
    }
}
