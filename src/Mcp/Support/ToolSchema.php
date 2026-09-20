<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Support;

use Heisenberg\Mcp\Tools\McpToolProvider;
use Heisenberg\Services\McpToolException;

/**
 * Small, dependency-free argument/schema helpers shared across
 * {@see McpToolProvider} implementations — building an MCP JSON
 * input schema, capping/collecting optional string fields, bounding a `limit` argument.
 * None of these touch the database or a model; they are pure argument shaping, kept out
 * of every provider so the same "at least one field" / "cap at N chars" rules can't drift
 * between, say, `update_category` and `update_media`.
 */
final class ToolSchema
{
    /**
     * Layout guidance appended to every tool that accepts authored content
     * (write_canvas, create_post, update_post) — and restated on describe_block,
     * where agents read the `innerBlocks.orientation` field. This is a
     * hand-maintained copy of the shipped contracts (columns/column.json), so
     * keep it in lockstep with resources/blocks: without it, agents authoring
     * pages reliably reached for `columns` when they meant stacked content
     * (or wrapped everything in horizontal rows), producing side-by-side
     * layouts where vertical stacking was intended.
     */
    public const LAYOUT_GUIDANCE = 'Layout: vertical stacking (content flowing top to bottom) is the NORMAL case and needs no container — just emit sibling blocks in order. '
        . 'Use a columns block ONLY when sibling content must truly sit SIDE BY SIDE in one row (e.g. a text block next to an image block); its children are column blocks, one per side-by-side slot, and the row wraps on narrow screens. '
        . 'column (singular) and group are containers for a vertical stack of children and must not be used to lay content out side by side. '
        . 'A container can still be flipped with supports.layout.direction (row = side by side, column = stacked), but the block choice already carries the right default.';

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    public static function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => $required,
        ];
    }

    /** @param array<string, mixed> $args */
    public static function boundedLimit(array $args): int
    {
        return max(1, min(100, (int) ($args['limit'] ?? 20)));
    }

    /**
     * The provided-and-valid subset of `$args` for `update_category`/`update_tag`/
     * `update_media` — every field is optional, but at least one must be present (an
     * update with nothing to change is a caller mistake, not a no-op worth silently
     * accepting), and each is capped at its column's actual length (`string` columns are
     * 255 in the categories/tags migrations; `text` columns like a category's description
     * are not listed in `$caps` and so stay uncapped here — MySQL/SQLite's own TEXT limit
     * is generous enough not to need a second, arbitrary ceiling).
     *
     * @param array<string, mixed> $args
     * @param list<string> $fields
     * @param array<string, int> $caps
     * @return array<string, string>
     */
    public static function bilingualUpdateFields(array $args, array $fields, array $caps = []): array
    {
        $provided = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $args) && is_string($args[$field])) {
                $provided[$field] = $args[$field];
            }
        }

        if ($provided === []) {
            throw new McpToolException('Supply at least one of: ' . implode(', ', $fields) . '.');
        }

        foreach ($caps as $field => $cap) {
            if (array_key_exists($field, $provided) && mb_strlen($provided[$field]) > $cap) {
                throw new McpToolException("{$field} must be {$cap} characters or fewer (got " . mb_strlen($provided[$field]) . ').');
            }
        }

        return $provided;
    }
}
