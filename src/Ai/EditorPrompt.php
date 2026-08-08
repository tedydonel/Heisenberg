<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

use Heisenberg\Services\BlockRegistryService;

/**
 * Builds the system prompt that teaches a model to author Heisenberg documents.
 *
 * The output format is the **shortcode dialect** (docs/code-view.md), not prose
 * and not HTML — that dialect is the editor's machine-authoring surface, and
 * routing AI output through it means an AI-written block is validated against
 * exactly the same registry the canvas uses, reports line-numbered errors, and
 * lands through the same undo stack as a hand-drawn one. Nothing else has to be
 * built for AI insertion to be safe.
 *
 * The tag list is generated from the live registry rather than hardcoded, so a
 * contract added tomorrow is offered to the model tomorrow, and a contract
 * removed stops being suggested.
 */
class EditorPrompt
{
    public function __construct(private BlockRegistryService $registry)
    {
    }

    /** Everything the model needs to emit valid blocks. */
    public function system(): string
    {
        $tags = $this->tags();

        return <<<PROMPT
        You are the writing assistant built into Heisenberg, a block-based page editor.

        When the user asks for content, reply with Heisenberg shortcode — never HTML, never Markdown.
        When the user asks a question about their document, answer in plain prose instead.

        The shortcode grammar:
          [tag attr=value long-attr="value with spaces"]body[/tag]
          [tag /]                                       self-closing

        Available tags: {$tags}
        `p` is paragraph and `h1`-`h6` are heading (the level rides the tag).

        Rules:
        - Body text may contain inline HTML (<strong>, <em>, <a href="...">). Block-level HTML may not.
        - Style attributes are CSS-familiar: color, bg, font-size, padding, margin, radius, w, h,
          gap, text-align. Box shorthands take CSS value order: padding="4px 8px".
        - State overrides use a prefix: hover:color=#123456.
        - Only set attributes the user actually asked for. Omit everything else.
        - Emit the blocks and nothing else — no explanation, no code fences, no preamble.
        - Never write <think> or any other reasoning tag into your reply.

        The user's current document is given to you below on every turn. Never ask them to paste
        it — if you need to know what is on the page, read it there. You also have tools for
        looking up block contracts and reading saved posts; use them instead of asking.
        PROMPT;
    }

    /**
     * The user turn: their instruction, plus whatever the editor selection gives
     * us as context.
     *
     * @param array<string, mixed> $context
     */
    public function user(string $prompt, array $context = []): string
    {
        $parts = [$prompt];

        // The whole document, as the shortcode the model is asked to produce.
        // Sending it unprompted is the difference between an assistant that can
        // edit the page and one that replies "paste your current shortcode".
        $document = trim((string) ($context['document'] ?? ''));
        if ($document !== '') {
            $parts[] = "The document currently on the page:\n\n{$document}";
        } else {
            $parts[] = 'The page is currently empty.';
        }

        $selection = trim((string) ($context['selection'] ?? ''));
        if ($selection !== '') {
            $blockName = trim((string) ($context['blockName'] ?? ''));
            $label = $blockName !== '' ? "the selected {$blockName} block" : 'the selected block';
            $parts[] = "The user has {$label} selected:\n\n{$selection}";
        }

        $title = trim((string) ($context['title'] ?? ''));
        if ($title !== '') {
            $parts[] = "The post title is: {$title}";
        }

        return implode("\n\n", $parts);
    }

    /** Comma-separated contract slugs, e.g. "heading, paragraph, list, quote". */
    private function tags(): string
    {
        $slugs = [];
        foreach ($this->registry->discover()['blocks'] as $contract) {
            $name = (string) ($contract['name'] ?? '');
            if ($name === '') {
                continue;
            }
            // Contract names are namespaced ("heisenberg/heading"); the dialect
            // accepts the bare slug.
            $slugs[] = str_contains($name, '/')
                ? substr($name, strrpos($name, '/') + 1)
                : $name;
        }

        $slugs = array_values(array_unique($slugs));
        sort($slugs);

        return $slugs === [] ? 'paragraph, heading' : implode(', ', $slugs);
    }
}
