<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Support\BlockViewData;

/**
 * Block models → shortcode text.
 *
 * The other half of the PHP port (see {@see ShortcodeDialect}); it is what lets
 * the inbound MCP server answer `get_post` with something an external AI can
 * read, edit and send straight back.
 *
 * Output is byte-identical to the JavaScript serializer's for the same input,
 * which is the property tests/Ai/ShortcodeParityTest.php exists to hold: only
 * non-default values appear, supports serialize in the inspector's own panel
 * order with state overrides last, box shorthands collapse to CSS form, and a
 * tag wider than 80 columns breaks one attribute per line.
 */
class ShortcodeSerializer
{
    /** @var array<string, array<string, mixed>> */
    private array $registry;

    /** @var array<string, string> */
    private array $reverseAliases;

    /** @param array<string, array<string, mixed>>|null $registry */
    public function __construct(BlockRegistryService $blocks, ?array $registry = null)
    {
        $this->registry = $registry ?? BlockViewData::clientBlocks($blocks);
        $this->reverseAliases = ShortcodeDialect::reverseAliases();
    }

    /**
     * The whole document. Blocks are separated by a blank line and the result
     * ends with a newline, matching the editor's own Code view exactly.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function serialize(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $model) {
            $text = $this->serializeModel((array) $model, 0);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $out = implode("\n\n", $parts);

        return $out === '' ? '' : $out . "\n";
    }

    /** @param array<string, mixed> $model */
    public function serializeModel(array $model, int $depth): string
    {
        $name = (string) ($model['name'] ?? '');
        $contract = $this->registry[$name] ?? null;
        if ($contract === null) {
            return '';
        }

        $tag = ShortcodeDialect::tagFor(ShortcodeDialect::slugOf($name), $model);
        $slug = $tag['tag'];
        $indent = str_repeat('  ', $depth);
        $defs = (array) ($contract['attributeDefinitions'] ?? []);
        $rich = ShortcodeDialect::richAttrOf($contract);
        $attributes = (array) ($model['attributes'] ?? []);

        $parts = [];
        foreach ($defs as $key => $def) {
            $key = (string) $key;
            if ($key === $rich || in_array($key, $tag['skip'], true)) {
                continue;
            }
            if (! array_key_exists($key, $attributes) || $attributes[$key] === null) {
                continue;
            }

            $def = (array) ($def ?? []);
            $default = $def['default'] ?? null;
            $default = $default === null ? '' : $default;

            // Only non-default values appear in code.
            if (ShortcodeDialect::jsString($attributes[$key]) === ShortcodeDialect::jsString($default)) {
                continue;
            }

            $text = is_array($attributes[$key])
                ? ShortcodeDialect::jsJson($attributes[$key])
                : ShortcodeDialect::jsString($attributes[$key]);

            $parts[] = $key . '=' . ShortcodeDialect::fmtValue($text);
        }

        foreach ($this->supportAttributes((array) ($model['supports'] ?? [])) as $part) {
            $parts[] = $part;
        }

        $inline = $indent . '[' . $slug . ($parts !== [] ? ' ' . implode(' ', $parts) : '');
        $wide = $parts !== [] && strlen($inline . ']') > ShortcodeDialect::MAX_TAG_WIDTH;

        // `$open` always ends exactly where the closing bracket (or `/]`) belongs.
        $open = $wide
            ? $indent . '[' . $slug . "\n"
                . implode("\n", array_map(static fn (string $p): string => $indent . '  ' . $p, $parts))
                . "\n" . $indent
            : $inline;

        $inner = $model['innerBlocks'] ?? [];
        $inner = is_array($inner) ? $inner : [];
        if ($inner !== []) {
            $kids = [];
            foreach ($inner as $child) {
                $text = $this->serializeModel((array) $child, $depth + 1);
                if ($text !== '') {
                    $kids[] = $text;
                }
            }

            return $open . "]\n" . implode("\n", $kids) . "\n" . $indent . '[/' . $slug . ']';
        }

        $body = $rich !== null ? ShortcodeDialect::jsString($attributes[$rich] ?? '') : '';
        if (trim($body) === '') {
            return $open . ($rich !== null ? '][/' . $slug . ']' : ($wide ? '/]' : ' /]'));
        }

        $bodyLines = implode("\n", array_map(
            static fn (string $line): string => $indent . '  ' . $line,
            $this->formatBody($body),
        ));

        return $open . "]\n" . $bodyLines . "\n" . $indent . '[/' . $slug . ']';
    }

    /**
     * Supports leaves as serialized attribute strings, in canonical group order,
     * with state prefixes and box shorthands applied.
     *
     * @param  array<string, mixed> $supports
     * @return list<string>
     */
    private function supportAttributes(array $supports): array
    {
        $leaves = [];
        $this->flattenSupports($supports, '', $leaves);

        // Stable since PHP 8.0, matching the JS engines' stable sort — leaves
        // inside one group must keep their relative order.
        usort($leaves, static fn (array $x, array $y): int => self::groupRank($x[0]) <=> self::groupRank($y[0]));

        $slots = [];
        $boxes = [];

        foreach ($leaves as [$path, $value]) {
            if ($value === null || $value === '') {
                continue;
            }

            $statePrefix = '';
            $rest = $path;
            if (preg_match('/^states\.([a-z]+)\./', $path, $m) === 1
                && in_array($m[1], ShortcodeDialect::STATES, true)) {
                $statePrefix = $m[1] . ':';
                $rest = substr($path, strlen($m[0]));
            }

            $captured = false;
            foreach (ShortcodeDialect::BOX_SHORTHANDS as $shortName => $box) {
                if (! str_starts_with($rest, $box['path'] . '.')) {
                    continue;
                }
                $side = substr($rest, strlen($box['path']) + 1);
                if (! in_array($side, $box['keys'], true)) {
                    continue;
                }

                $groupKey = $statePrefix . $shortName;
                if (! isset($boxes[$groupKey])) {
                    $boxes[$groupKey] = [
                        'sides' => [], 'at' => count($slots),
                        'shortName' => $shortName, 'statePrefix' => $statePrefix, 'box' => $box,
                    ];
                    $slots[] = null;
                }
                $boxes[$groupKey]['sides'][$side] = $value;
                $captured = true;
                break;
            }
            if ($captured) {
                continue;
            }

            $text = is_array($value) ? ShortcodeDialect::jsJson($value) : ShortcodeDialect::jsString($value);
            $slots[] = $statePrefix . ($this->reverseAliases[$rest] ?? $rest) . '=' . ShortcodeDialect::fmtValue($text);
        }

        foreach ($boxes as $acc) {
            $collapsed = ShortcodeDialect::collapseBox($acc['sides'], $acc['box']['keys']);
            if ($collapsed !== null) {
                $slots[$acc['at']] = $acc['statePrefix'] . $acc['shortName'] . '=' . ShortcodeDialect::fmtValue($collapsed);
                continue;
            }

            // A partial box can't collapse: emit only the sides that are set.
            $sideParts = [];
            foreach ($acc['box']['keys'] as $key) {
                if (! array_key_exists($key, $acc['sides'])) {
                    continue;
                }
                $full = $acc['box']['path'] . '.' . $key;
                $sideParts[] = $acc['statePrefix']
                    . ($this->reverseAliases[$full] ?? $full)
                    . '=' . ShortcodeDialect::fmtValue(ShortcodeDialect::jsString($acc['sides'][$key]));
            }
            $slots[$acc['at']] = ['multi' => $sideParts];
        }

        $out = [];
        foreach ($slots as $slot) {
            if ($slot === null) {
                continue;
            }
            if (is_string($slot)) {
                $out[] = $slot;
                continue;
            }
            foreach ($slot['multi'] as $part) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * Depth-first walk to `[dotted.path, value]` leaves, skipping `layers`
     * (inspector editing state, re-synthesized from the scalar on selection).
     *
     * An empty array is treated as an empty OBJECT and contributes nothing,
     * matching the JS side: PHP cannot tell `{}` from `[]` after a JSON decode,
     * and an empty-array supports leaf is meaningless either way.
     *
     * @param array<string, mixed>            $node
     * @param list<array{0: string, 1: mixed}> $out
     */
    private function flattenSupports(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'layers') {
                continue;
            }
            $path = $prefix !== '' ? $prefix . '.' . $key : (string) $key;

            if (is_array($value) && ($value === [] || ! array_is_list($value))) {
                $this->flattenSupports($value, $path, $out);
                continue;
            }

            $out[] = [$path, $value];
        }
    }

    private static function groupRank(string $path): int
    {
        $index = array_search(explode('.', $path)[0], ShortcodeDialect::GROUP_ORDER, true);

        return $index === false ? count(ShortcodeDialect::GROUP_ORDER) : (int) $index;
    }

    /**
     * Rich-text bodies pretty-print: block-level boundaries start a new line and
     * long prose word-wraps near 90 columns. HTML collapses the inserted
     * newlines back to whitespace, so wrapping never changes what renders — and
     * it is idempotent, so the round trip stays byte-stable.
     *
     * @return list<string>
     */
    private function formatBody(string $body): array
    {
        $broken = preg_replace(
            '/(<\/(?:div|p|section|blockquote|ul|ol|li|h[1-6])>|<br\s*\/?>)\s*(?=<)/i',
            "$1\n",
            $body,
        ) ?? $body;

        $lines = [];
        foreach (explode("\n", $broken) as $line) {
            foreach ($this->wrapLine($line, ShortcodeDialect::BODY_WIDTH) as $wrapped) {
                $lines[] = $wrapped;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function wrapLine(string $line, int $width): array
    {
        if (strlen($line) <= $width) {
            return [$line];
        }

        // Tags stay whole; words and whitespace runs are the wrap points.
        if (preg_match_all('/<[^>]*>|[^<\s]+|\s+/', $line, $m) === 0) {
            return [$line];
        }

        $out = [];
        $current = '';
        foreach ($m[0] as $token) {
            if (preg_match('/^\s+$/', $token) === 1) {
                if (strlen($current) >= $width) {
                    $out[] = $current;
                    $current = '';
                } else {
                    $current .= $token;
                }
                continue;
            }
            $current .= $token;
        }
        if (trim($current) !== '') {
            $out[] = $current;
        }

        return $out === [] ? [$line] : $out;
    }
}
