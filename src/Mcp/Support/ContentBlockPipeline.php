<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Support;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\ShortcodeParser;
use Heisenberg\Support\BlockViewData;

/**
 * THE single path from caller-supplied content (shortcode or block JSON) to a validated,
 * hydrated block tree. Every MCP tool that touches content — `write_canvas`,
 * `render_preview`, `create_post`/`update_post` (via
 * {@see PostAccess::writePost()}), `restore_revision`, and
 * `create_translation` — funnels through {@see self::contentBlocks()} and/or
 * {@see self::validatedContentBlocks()}, so "valid content" cannot mean something
 * different between authoring a post and translating one, and so every write is checked
 * by the exact same {@see BlocksPayloadService::validatePayload()} call the editor's own
 * save path runs. Do not add a second place that calls `validatePayload()` — route
 * through here instead.
 */
class ContentBlockPipeline
{
    public function __construct(
        private BlockRegistryService $registry,
        private BlocksPayloadService $payload,
        private ShortcodeParser $parser,
    ) {
    }

    /**
     * Content from either input shape. Shortcode is the ergonomic surface and
     * block JSON the canonical one; both end up as models validated the same way.
     *
     * @param array<string, mixed> $args
     * @return list<array<string, mixed>>|null null means "not supplied"
     */
    public function contentBlocks(array $args, bool $allowEmpty = false): ?array
    {
        $hasCode = array_key_exists('code', $args) && is_string($args['code']);
        $hasBlocks = array_key_exists('blocks', $args) && is_array($args['blocks']);

        if ($hasCode && $hasBlocks) {
            throw new McpToolException('Supply either code or blocks, not both.');
        }

        if ($hasCode) {
            // Strict: this is AI/API-authored content, so markdown in text fields is an error the
            // caller is told how to fix, not literal characters on the page.
            $parsed = $this->parser->parse((string) $args['code'], strict: true);
            if ($parsed['errors'] !== []) {
                $lines = array_map(
                    static fn (array $e): string => "line {$e['line']}: {$e['message']}",
                    $parsed['errors'],
                );

                throw new McpToolException('Shortcode did not parse — ' . implode('; ', $lines));
            }

            return $parsed['blocks'];
        }

        if ($hasBlocks) {
            return array_values($args['blocks']);
        }

        if ($allowEmpty) {
            return null;
        }

        throw new McpToolException('Supply content as `code` (shortcode) or `blocks` (JSON).');
    }

    /**
     * Shortcode/JSON -> validated, hydrated block models, ready to persist. The exact
     * pipeline `write_canvas` validates against (parse via {@see self::contentBlocks()},
     * against the SAME live contracts — {@see ShortcodeParser} reports the identical
     * line-numbered errors either way) PLUS the hydrate + {@see BlocksPayloadService}
     * step that stamps ids/schemaVersion/attribute defaults and re-checks the result —
     * the extra step every path that actually WRITES to the database needs (write_canvas
     * never persists, so it stops after the parse).
     *
     * @param array<string, mixed> $args
     * @return list<array<string, mixed>>|null null means "no content supplied"
     */
    public function validatedContentBlocks(array $args, bool $allowEmpty = false): ?array
    {
        $blocks = $this->contentBlocks($args, allowEmpty: $allowEmpty);
        if ($blocks === null) {
            return null;
        }

        // The parser produces bare models, exactly as the JS parser does — in the
        // browser it is replaceDoc() that stamps each one with an id, the contract's
        // schemaVersion and the attribute defaults. There is no replaceDoc here, so
        // this is that step. Without it every write fails validation on `missing key 'id'`.
        $blocks = $this->hydrateBlocks($blocks);

        $checked = $this->payload->validatePayload([
            'schemaVersion' => 1,
            // Computed live rather than quoted by the caller: an MCP client holds no
            // page snapshot that could be stale.
            'registryHash' => $this->registry->computeHash(),
            'blocks' => $blocks,
        ]);

        if (! ($checked['valid'] ?? false)) {
            throw new McpToolException('Content rejected: ' . implode('; ', (array) ($checked['errors'] ?? ['invalid payload'])));
        }

        return $checked['blocks'] ?? $blocks;
    }

    /**
     * The server-side equivalent of the runtime's `newBlockModel()`: give every
     * model a unique id, the contract's own `schemaVersion`, and the contract's
     * attribute defaults beneath whatever the caller supplied.
     *
     * An unregistered block name is an ERROR here, not a silent drop. The editor
     * drops (a contract can vanish between page load and save, and losing one
     * block beats losing the document), but an agent that gets "saved" back for
     * content that was discarded has no way to notice — so this surface tells it
     * instead, and names the tool that would have prevented the mistake.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function hydrateBlocks(array $blocks, int $depth = 0): array
    {
        if ($depth > 20) {
            throw new McpToolException('Block nesting is too deep (max 20).');
        }

        $contracts = BlockViewData::clientBlocks($this->registry);
        $out = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                throw new McpToolException('Every entry in `blocks` must be an object.');
            }
            $name = (string) ($block['name'] ?? '');
            $contract = $contracts[$name] ?? null;
            if ($contract === null) {
                throw new McpToolException(
                    "'{$name}' is not a registered block contract. Call list_blocks to see what this install accepts."
                );
            }

            $inner = $block['innerBlocks'] ?? [];

            $out[] = [
                'name' => $name,
                'id' => (string) ($block['id'] ?? 'mcp-' . bin2hex(random_bytes(8))),
                'schemaVersion' => $block['schemaVersion'] ?? $contract['version'],
                'attributes' => array_merge(
                    (array) ($contract['attributes'] ?? []),
                    (array) ($block['attributes'] ?? []),
                ),
                'supports' => (array) ($block['supports'] ?? []),
                'innerBlocks' => $this->hydrateBlocks(is_array($inner) ? $inner : [], $depth + 1),
            ];
        }

        return $out;
    }
}
