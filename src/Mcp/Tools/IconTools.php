<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\IconLibraryService;

/**
 * The icon block's `icon` attribute is a `"<set>/<slug>"` reference into a library of
 * tens of thousands of imported icons. That set cannot go in a system prompt at any
 * budget, so a model asked for "a star icon" GUESSED a plausible-looking reference —
 * and a guess that isn't manifest-listed renders nothing, which is exactly how
 * hallucinated icons reached the canvas. This is the lookup that replaces guessing:
 * same manifest the picker dialog searches, so the model can only return references
 * that actually resolve. `describe_block` cannot cover this — the valid values are
 * data, not schema.
 */
final class IconTools implements McpToolProvider
{
    public function __construct(private IconLibraryService $icons)
    {
    }

    public function definitions(): array
    {
        return [
            'search_icons' => [
                'description' => 'Find real icons in this install\'s icon library. REQUIRED before writing a heisenberg/icon block: the `icon` attribute must be a "<set>/<slug>" reference this tool returned, never one you composed yourself — an unlisted reference renders nothing. Search by keyword ("arrow", "cart", "check"); optionally narrow to one set. Returns matches plus the total, so a thin result means try a different word rather than inventing one.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'query' => ['type' => 'string', 'description' => 'Keyword matched against icon slugs, e.g. "arrow" or "shopping-cart". Empty lists everything, paged.'],
                    'set' => ['type' => 'string', 'description' => 'Optional: restrict to one set name. Call with no arguments first to see the available sets.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum matches to return (default 30, capped at 200).'],
                ]),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return $tool === 'search_icons';
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        $limit = (int) ($arguments['limit'] ?? 30);
        $found = $this->icons->search(
            (string) ($arguments['query'] ?? ''),
            isset($arguments['set']) ? (string) $arguments['set'] : null,
            $limit > 0 ? $limit : 30,
        );

        return [
            // Pre-joined into the exact string the attribute takes, so the model never
            // has to assemble one from parts and cannot get the separator wrong.
            'icons' => array_map(
                static fn (array $i): string => $i['set'] . '/' . $i['slug'],
                $found['icons'],
            ),
            'total' => $found['total'],
            'sets' => $this->icons->sets(),
        ];
    }
}
