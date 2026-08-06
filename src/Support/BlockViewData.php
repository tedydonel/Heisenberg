<?php

declare(strict_types=1);

namespace Heisenberg\Support;

use Heisenberg\Services\BlockRegistryService;
use Illuminate\Support\Facades\Log;

/**
 * View data the editor needs from the live block registry: the client-side contract
 * payload and each enabled block's own CSS, concatenated. Builder-only concerns
 */
final class BlockViewData
{
    /**
     * The contract data the client runtime needs to mirror PHP rendering and
     * build the live inspector. Attribute defaults stay flattened for model
     * creation while definitions retain enum/type metadata for safe dynamic tags.
     *
     * `$enabled` is a plain caller-supplied filter — `null` (the default) ships
     * every contract the registry discovers, which is what the editor wants now
     * that only heading + paragraph exist. Pass an explicit list to narrow it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function clientBlocks(BlockRegistryService $registry, ?array $enabled = null): array
    {
        $out = [];

        foreach ($registry->registry()['blocks'] as $block) {
            $name = (string) ($block['name'] ?? '');
            if (is_array($enabled) && $enabled !== [] && ! in_array($name, $enabled, true)) {
                continue;
            }

            $defaults = [];
            foreach (($block['attributes'] ?? []) as $attribute => $definition) {
                $defaults[$attribute] = $definition['default'] ?? null;
            }

            $out[$name] = [
                'name' => $name,
                'title' => (string) ($block['title'] ?? ''),
                'description' => (string) ($block['description'] ?? ''),
                // The block instance's schemaVersion must equal this exactly (BlocksPayloadService)
                // — the client runtime stamps every model with it (see newBlockModel() in
                // live/block-runtime.blade.php) so a save payload is never missing it.
                'version' => $block['version'] ?? null,
                'keywords' => array_values(array_filter((array) ($block['keywords'] ?? []), 'is_string')),
                'icon' => (string) ($block['icon'] ?? ''),
                'category' => (string) ($block['category'] ?? ''),
                'attributes' => $defaults,
                'attributeDefinitions' => $block['attributes'] ?? [],
                'controls' => $block['controls'] ?? [],
                'panels' => $block['panels'] ?? [],
                'supports' => $block['supports'] ?? [],
                'template' => $block['render']['template'] ?? null,
                'innerBlocks' => $block['innerBlocks'] ?? ['enabled' => false],
                'style' => [
                    'className' => (string) ($block['style']['className'] ?? ''),
                    'classNames' => $block['style']['classNames'] ?? [],
                    'variables' => $block['style']['variables'] ?? [],
                ],
                'richText' => (string) ($block['security']['richText'] ?? 'inline-basic'),
            ];
        }

        return $out;
    }

    /**
     * Concatenated CSS for the enabled blocks, read from each block's own
     * `style.css` file (`resources/blocks/<slug>/<slug>.css`). The editor embeds
     * this so a component stays self-contained — its styles live with its contract,
     * not in a shared stylesheet. `$enabled` behaves exactly like clientBlocks()'s.
     *
     * The shared capability stylesheet ({@see SupportsStyle}) is prepended, because
     * nothing else loads it into the editor: the route serving it
     * (`/heisenberg-assets/editor-supports.css`) exists but no view ever linked it, so
     * every capability it implements — opacity, letter-spacing, text-align, transforms,
     * shadow, overflow, flex, per-side border — was unreachable in the canvas no matter
     * what a contract declared. It is prepended rather than appended so a block's own
     * CSS still wins on equal specificity, and it is inert until a contract opts in by
     * carrying `hb-supports` on its root.
     */
    public static function blocksCss(BlockRegistryService $registry, ?array $enabled = null): string
    {
        $chunks = ["/* supports capabilities */\n" . SupportsStyle::css()];

        foreach ($registry->discover()['blocks'] as $block) {
            $name = (string) ($block['name'] ?? '');
            if (is_array($enabled) && $enabled !== [] && ! in_array($name, $enabled, true)) {
                continue;
            }

            $relative = $block['style']['css'] ?? null;
            $jsonPath = $block['_absolutePath'] ?? null;
            if (! is_string($relative) || $relative === '' || ! is_string($jsonPath)) {
                continue;
            }

            $cssPath = dirname($jsonPath) . DIRECTORY_SEPARATOR . ltrim($relative, './');
            if (is_file($cssPath)) {
                $chunks[] = "/* {$name} */\n" . file_get_contents($cssPath);
            } else {
                // §4.11 — this used to be a silent skip: the block would render
                // unstyled with no signal anywhere. Surface it as a dev-time log
                // instead (never in production, so a genuinely CSS-less block
                // doesn't become log noise on every request). The skip behavior
                // itself is unchanged — a missing file still just means no chunk.
                self::logMissingCss($name, $cssPath);
            }
        }

        return implode("\n\n", $chunks);
    }

    /**
     * Dev-time signal for a contract that declares a `style.css` file which
     * doesn't exist on disk (§4.11). Guarded so it is inert outside a booted
     * Laravel app (unit tests instantiating this class directly) and silent
     * in production.
     */
    private static function logMissingCss(string $blockName, string $path): void
    {
        if (! function_exists('app')) {
            return;
        }

        $app = app();
        if (! method_exists($app, 'environment') || $app->environment('production')) {
            return;
        }

        Log::warning("heisenberg: block '{$blockName}' declares style.css '{$path}' but the file does not exist on disk — it will render unstyled.");
    }
}
