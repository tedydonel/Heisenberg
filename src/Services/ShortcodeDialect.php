<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * The shortcode dialect's shared vocabulary — every table, regex and coercion
 * rule that {@see ShortcodeParser} and {@see ShortcodeSerializer} both need.
 *
 * **This is a port of the JavaScript in live/code-editor.blade.php, and the two
 * must not drift.** They are separate implementations because the editor parses
 * in the browser (no round trip while typing) and the MCP server parses on the
 * server (no browser at all) — but they describe one dialect, so
 * tests/Ai/ShortcodeParityTest.php runs a shared fixture corpus through both and
 * fails if either side disagrees. Change a table here, change it there, in the
 * same commit.
 *
 * The JS/PHP seams that actually bite are all in the coercion helpers below —
 * `String(true)` is `"true"` in JS and `"1"` in PHP, `JSON.stringify` does not
 * escape forward slashes and `json_encode` does by default, and `Number("")` is
 * 0 rather than NaN. Each is handled explicitly rather than left to the
 * language's own casting.
 */
class ShortcodeDialect
{
    /** Same source regex as the JS tokenizer; PCRE-escaped. */
    public const TAG_RE = '/\[(\/)?([a-z][a-z0-9-]*)((?:\s+[a-zA-Z0-9_.:-]+\s*=\s*(?:"(?:[^"\\\\]|\\\\.)*"|[^\s\]"]*[^\s\]"\/]))*)\s*(\/)?\]/';

    public const ATTR_RE = '/([a-zA-Z0-9_.:-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s\]"]*[^\s\]"\/]))/';

    /** Values matching this serialize unquoted; everything else gets quotes. */
    public const UNQUOTED_OK = '/^[A-Za-z0-9_.#%(),:+*-]+$/';

    public const STATES = ['hover', 'active', 'focus'];

    /** A tag whose inline form exceeds this pretty-prints one attribute per line. */
    public const MAX_TAG_WIDTH = 80;

    public const BODY_WIDTH = 90;

    /**
     * Canonical supports order in serialized code — mirrors the inspector's
     * panel order so the text reads like the panel, state overrides last.
     */
    public const GROUP_ORDER = [
        'align', 'position', 'layout', 'appearance', 'typography', 'size',
        'color', 'spacing', 'border', 'effects', 'animation', 'states',
    ];

    /** CSS/Tailwind-familiar short names over the full supports paths. */
    public const ALIASES = [
        'color' => 'color.text', 'bg' => 'color.background',
        'font' => 'typography.fontFamily', 'weight' => 'typography.fontWeight',
        'font-size' => 'typography.fontSize', 'line-height' => 'typography.lineHeight',
        'letter-spacing' => 'typography.letterSpacing',
        'text-align' => 'typography.textAlign', 'text-valign' => 'typography.textAlignVertical',
        'w' => 'size.width', 'h' => 'size.height',
        'min-w' => 'size.minWidth', 'min-h' => 'size.minHeight',
        'max-w' => 'size.maxWidth', 'max-h' => 'size.maxHeight',
        'clip' => 'size.clip',
        'padding-top' => 'spacing.padding.top', 'padding-right' => 'spacing.padding.right',
        'padding-bottom' => 'spacing.padding.bottom', 'padding-left' => 'spacing.padding.left',
        'margin-top' => 'spacing.margin.top', 'margin-right' => 'spacing.margin.right',
        'margin-bottom' => 'spacing.margin.bottom', 'margin-left' => 'spacing.margin.left',
        'radius-tl' => 'border.radius.topLeft', 'radius-tr' => 'border.radius.topRight',
        'radius-br' => 'border.radius.bottomRight', 'radius-bl' => 'border.radius.bottomLeft',
        'border-width' => 'border.width', 'border-color' => 'border.color', 'border-style' => 'border.style',
        'border-top' => 'border.width.top', 'border-right' => 'border.width.right',
        'border-bottom' => 'border.width.bottom', 'border-left' => 'border.width.left',
        'gap' => 'layout.gap', 'direction' => 'layout.direction', 'wrap' => 'layout.wrap',
        'justify' => 'layout.justify', 'align-items' => 'layout.align',
        'position' => 'position.mode', 'x' => 'position.x', 'y' => 'position.y', 'rotate' => 'position.rotation',
        'opacity' => 'appearance.opacity', 'shadow' => 'effects.shadow',
    ];

    /** CSS box shorthands: 1 value = all, 2 = pairs, 3 = t/x/b, 4 = each. */
    public const BOX_SHORTHANDS = [
        'padding' => ['path' => 'spacing.padding', 'keys' => ['top', 'right', 'bottom', 'left']],
        'margin' => ['path' => 'spacing.margin', 'keys' => ['top', 'right', 'bottom', 'left']],
        'radius' => ['path' => 'border.radius', 'keys' => ['topLeft', 'topRight', 'bottomRight', 'bottomLeft']],
    ];

    /** Heading levels ride the tag itself (h1…h6, HTML-familiar); paragraph is p. */
    public const TAG_SHORT = [
        'p' => ['slug' => 'paragraph', 'attrs' => []],
        'h1' => ['slug' => 'heading', 'attrs' => ['level' => 1]],
        'h2' => ['slug' => 'heading', 'attrs' => ['level' => 2]],
        'h3' => ['slug' => 'heading', 'attrs' => ['level' => 3]],
        'h4' => ['slug' => 'heading', 'attrs' => ['level' => 4]],
        'h5' => ['slug' => 'heading', 'attrs' => ['level' => 5]],
        'h6' => ['slug' => 'heading', 'attrs' => ['level' => 6]],
    ];

    /** @return array<string, string> full supports path → short alias */
    public static function reverseAliases(): array
    {
        $reverse = [];
        foreach (self::ALIASES as $short => $path) {
            $reverse[$path] = $short;
        }

        return $reverse;
    }

    public static function slugOf(string $name): string
    {
        return str_contains($name, '/') ? explode('/', $name)[1] : $name;
    }

    /** @param array<string, mixed> $contract */
    public static function richAttrOf(array $contract): ?string
    {
        foreach ((array) ($contract['attributeDefinitions'] ?? []) as $key => $def) {
            if (is_array($def) && ($def['type'] ?? null) === 'rich-text') {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * The tag a model serializes as, plus attributes the tag itself carries and
     * which therefore must not be repeated as attributes.
     *
     * @param  array<string, mixed> $model
     * @return array{tag: string, skip: list<string>}
     */
    public static function tagFor(string $slug, array $model): array
    {
        if ($slug === 'paragraph') {
            return ['tag' => 'p', 'skip' => []];
        }
        if ($slug === 'heading') {
            $level = (int) (($model['attributes'] ?? [])['level'] ?? 0);
            if ($level >= 1 && $level <= 6) {
                return ['tag' => 'h' . $level, 'skip' => ['level']];
            }
        }

        return ['tag' => $slug, 'skip' => []];
    }

    /**
     * JavaScript's `String(value)`. PHP casts booleans to "1"/"" and floats keep
     * a trailing ".0" that JS drops — both would silently change serialized
     * output and break the round trip.
     */
    public static function jsString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            // JS prints 3.0 as "3"; PHP prints "3".  Guard the integral case
            // explicitly rather than relying on that agreeing forever.
            return $value == (int) $value && is_finite($value)
                ? (string) (int) $value
                : rtrim(rtrim(sprintf('%.15g', $value), '0'), '.');
        }
        if (is_array($value)) {
            return self::jsJson($value);
        }

        return (string) $value;
    }

    /** JavaScript's `JSON.stringify` — notably, it does not escape forward slashes. */
    public static function jsJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * JavaScript's `Number(string)`, returning null where JS would return NaN.
     * Note `Number("")` is 0, not NaN — a difference worth preserving so an
     * empty numeric attribute behaves the same in both surfaces.
     */
    public static function jsNumber(string $raw): int|float|null
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 0;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $trimmed) === 1) {
            return (int) hexdec($trimmed);
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $trimmed) !== 1) {
            return null;
        }
        $number = $trimmed + 0;

        return is_float($number) && $number == (int) $number && ! str_contains($trimmed, '.')
            ? (int) $number
            : $number;
    }

    public static function escAttr(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public static function unescAttr(string $value): string
    {
        return preg_replace('/\\\\(["\\\\])/', '$1', $value) ?? $value;
    }

    public static function fmtValue(string $value): string
    {
        return preg_match(self::UNQUOTED_OK, $value) === 1 ? $value : '"' . self::escAttr($value) . '"';
    }

    /**
     * @param  list<string> $values
     * @param  list<string> $keys
     * @return array<string, string>
     */
    public static function expandBox(array $values, array $keys): array
    {
        $a = $values[0] ?? '';
        $b = $values[1] ?? '';
        $c = $values[2] ?? '';

        return match (count($values)) {
            1 => [$keys[0] => $a, $keys[1] => $a, $keys[2] => $a, $keys[3] => $a],
            2 => [$keys[0] => $a, $keys[1] => $b, $keys[2] => $a, $keys[3] => $b],
            3 => [$keys[0] => $a, $keys[1] => $b, $keys[2] => $c, $keys[3] => $b],
            default => [$keys[0] => $values[0], $keys[1] => $values[1], $keys[2] => $values[2], $keys[3] => $values[3]],
        };
    }

    /**
     * All four sides → the shortest CSS-equivalent form, or null when a side is
     * missing (the caller then emits per-side attributes instead).
     *
     * @param array<string, mixed> $sides
     * @param list<string>         $keys
     */
    public static function collapseBox(array $sides, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $sides)) {
                return null;
            }
        }

        [$a, $b, $c, $d] = array_map(static fn (string $k): string => self::jsString($sides[$k]), $keys);

        if ($a === $b && $b === $c && $c === $d) {
            return $a;
        }
        if ($a === $c && $b === $d) {
            return $a . ' ' . $b;
        }

        return implode(' ', [$a, $b, $c, $d]);
    }

    /** Write `value` at a dotted path, creating intermediate maps. */
    public static function setPath(array &$target, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $node = &$target;
        for ($i = 0, $n = count($parts) - 1; $i < $n; $i++) {
            if (! isset($node[$parts[$i]]) || ! is_array($node[$parts[$i]])) {
                $node[$parts[$i]] = [];
            }
            $node = &$node[$parts[$i]];
        }
        $node[$parts[count($parts) - 1]] = $value;
    }

    public static function dataGet(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** Strip the common leading indentation from a captured body. */
    public static function dedent(string $raw): string
    {
        $lines = explode("\n", $raw);
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        $min = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^ */', $line, $lead);
            $min = min($min, strlen($lead[0]));
        }
        if ($min === PHP_INT_MAX) {
            $min = 0;
        }

        return implode("\n", array_map(static fn (string $line): string => substr($line, $min), $lines));
    }
}
