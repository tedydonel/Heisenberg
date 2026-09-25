<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

use Heisenberg\Http\Controllers\AiController;

/**
 * What a translation of the open page is made from, for the in-editor assistant's current turn.
 *
 * The page lives in the browser (it may never have been saved), so the panel sends its
 * translatable text with each turn and {@see AiController} binds it
 * here for the request. The model reads it through the `translation_source` tool and answers with
 * `translate_page` using the same ids — so a translation is TEXT only: the model never re-types
 * the page's markup, styles or structure (docs/content-translation.md §0.4).
 */
final class TranslationSource
{
    /**
     * @param array<string, string> $segments `<blockId>.<key>` => home text
     * @param list<array{anchor: string, label: string}> $toc the saved table of contents, home labels
     */
    public function __construct(
        public readonly array $segments = [],
        public readonly string $title = '',
        public readonly array $toc = [],
    ) {
    }

    /**
     * Built from the panel's context — every value re-typed and bounded, since it is client input.
     *
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context): self
    {
        $source = is_array($context['translationSource'] ?? null) ? $context['translationSource'] : [];

        $segments = [];
        foreach ((array) ($source['segments'] ?? []) as $id => $text) {
            if (is_string($id) && preg_match('/^hb\d+\.[A-Za-z][A-Za-z0-9_]*$/', $id) === 1 && is_string($text) && trim($text) !== '') {
                $segments[$id] = mb_substr($text, 0, 20000);
            }
            if (count($segments) >= 2000) {
                break;
            }
        }

        $home = trim((string) ($context['homeLocale'] ?? ''));
        $toc = [];
        foreach (is_array($context['toc'] ?? null) ? array_slice($context['toc'], 0, 200) : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $anchor = trim((string) ($entry['anchor'] ?? ''));
            $label = trim((string) (($entry['labels'][$home] ?? null) ?: ($entry['label'] ?? '')));
            if ($anchor !== '' && $label !== '') {
                $toc[] = ['anchor' => $anchor, 'label' => mb_substr($label, 0, 160)];
            }
        }

        return new self($segments, mb_substr(trim((string) ($source['title'] ?? '')), 0, 200), $toc);
    }
}
