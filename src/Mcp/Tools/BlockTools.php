<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ContentBlockPipeline;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\ShortcodeDialect;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Support\LocaleConfig;

/**
 * The block contract catalogue: `list_blocks`/`describe_block` — the contract set IS the
 * authoring schema, so these two ARE the reference an agent reads before writing any
 * shortcode — plus `render_preview`, a read-only render of caller-supplied content
 * against the same contracts, so an agent can sanity-check output before ever calling a
 * write tool.
 */
final class BlockTools implements McpToolProvider
{
    public function __construct(
        private BlockRegistryService $registry,
        private BlockRenderer $renderer,
        private ContentBlockPipeline $blocks,
    ) {
    }

    public function definitions(): array
    {
        return [
            // The contract set IS the authoring schema: an agent reads these two
            // and knows exactly what it may emit.
            'list_blocks' => [
                'description' => 'List every block contract available in this Heisenberg install. Call this before authoring content — the returned slugs are the only valid shortcode tags.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([]),
            ],

            'describe_block' => [
                'description' => 'Full contract for one or more blocks: attributes (with types, defaults and enums) and the style supports each accepts. Pass `names` (a list) to batch several contracts in one call instead of one describe_block round trip per block — cheaper than calling this once per block when authoring something with a handful of different types. The returned `innerBlocks.orientation` is how the block stacks its children: "vertical" = one column, top to bottom (the default for most content); "horizontal" = one row, side by side.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'name' => ['type' => 'string', 'description' => 'Contract name or bare slug, e.g. "heisenberg/heading" or "heading".'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Batch form of `name` — describe several contracts in one call. When non-empty, `name` is ignored and the result is `{results: [...]}`, one entry per requested name (unknown names come back as `{name, error}` instead of failing the whole call).'],
                ]),
            ],

            'render_preview' => [
                'description' => 'Render shortcode or block JSON to HTML without saving anything. Use it to check output before writing a post.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'code' => ['type' => 'string'],
                    'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'locale' => ['type' => 'string', 'description' => 'Defaults to en.'],
                ]),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['list_blocks', 'describe_block', 'render_preview'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'list_blocks' => $this->listBlocks(),
            'describe_block' => $this->describeBlock($arguments),
            'render_preview' => $this->renderPreview($arguments),
            default => throw new \LogicException("BlockTools does not handle '{$tool}'."),
        };
    }

    private function listBlocks(): array
    {
        return array_map(
            static fn (array $c): array => [
                'name' => $c['name'],
                'slug' => ShortcodeDialect::slugOf((string) $c['name']),
                'title' => $c['title'],
                'category' => $c['category'],
                'acceptsChildren' => (bool) ($c['innerBlocks']['enabled'] ?? false),
            ],
            array_values(BlockViewData::clientBlocks($this->registry)),
        );
    }

    /** @param array<string, mixed> $args */
    private function describeBlock(array $args): array
    {
        $blocks = BlockViewData::clientBlocks($this->registry);
        $describe = static function (string $wanted) use ($blocks): ?array {
            foreach ($blocks as $name => $contract) {
                if ($name === $wanted || ShortcodeDialect::slugOf((string) $name) === $wanted) {
                    return [
                        'name' => $contract['name'],
                        'slug' => ShortcodeDialect::slugOf((string) $name),
                        'attributes' => $contract['attributeDefinitions'],
                        'supports' => $contract['supports'],
                        'innerBlocks' => $contract['innerBlocks'],
                    ];
                }
            }

            return null;
        };

        $names = $args['names'] ?? null;
        if (is_array($names) && $names !== []) {
            return ['results' => array_map(
                static fn (mixed $wanted): array => $describe((string) $wanted)
                    ?? ['name' => (string) $wanted, 'error' => "No block contract named '{$wanted}'. Call list_blocks first."],
                $names,
            )];
        }

        $wanted = (string) ($args['name'] ?? '');
        $found = $describe($wanted);
        if ($found === null) {
            throw new McpToolException("No block contract named '{$wanted}'. Call list_blocks first.");
        }

        return $found;
    }

    /** @param array<string, mixed> $args */
    private function renderPreview(array $args): array
    {
        $blocks = $this->blocks->contentBlocks($args);
        $locale = (string) ($args['locale'] ?? LocaleConfig::default());
        $locale = LocaleConfig::isValid($locale) ? $locale : LocaleConfig::default();

        return ['html' => $this->renderer->renderBlocks($blocks, $locale)];
    }
}
