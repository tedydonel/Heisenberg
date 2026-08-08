<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Support\BlockViewData;

/**
 * Shortcode text → block models, with line-numbered errors.
 *
 * A server-side port of the parser in live/code-editor.blade.php (see
 * {@see ShortcodeDialect} for why both exist and how they are kept honest).
 * This is what lets the inbound MCP server accept `[h2]Title[/h2]` from an
 * external AI and turn it into blocks that then go through exactly the same
 * BlocksPayloadService validation as anything the editor saves.
 *
 * The contracts come from {@see BlockViewData::clientBlocks()} — the same
 * registry envelope the browser gets — so "valid here" and "valid in the
 * editor" cannot mean different things.
 *
 * Errors are collected, never thrown: a document with three bad attributes
 * should report all three, and a caller usually wants the partial tree too.
 */
class ShortcodeParser
{
    /** @var array<string, array<string, mixed>> name → contract */
    private array $registry;

    /** @var array<string, string> slug → contract name */
    private array $slugToName = [];

    /** @var list<array{line: int, message: string}> */
    private array $errors = [];

    /** @var list<array<string, mixed>> */
    private array $rootBlocks = [];

    /** @var list<array<string, mixed>> */
    private array $stack = [];

    private string $text = '';

    /** @param array<string, array<string, mixed>>|null $registry */
    public function __construct(BlockRegistryService $blocks, ?array $registry = null)
    {
        $this->registry = $registry ?? BlockViewData::clientBlocks($blocks);

        foreach (array_keys($this->registry) as $name) {
            $this->slugToName[ShortcodeDialect::slugOf((string) $name)] = (string) $name;
        }
    }

    /**
     * @return array{blocks: list<array<string, mixed>>, errors: list<array{line: int, message: string}>}
     */
    public function parse(string $text): array
    {
        $this->text = $text;
        $this->errors = [];
        $this->rootBlocks = [];
        $this->stack = [];

        preg_match_all(
            ShortcodeDialect::TAG_RE,
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        $last = 0;
        foreach ($matches as $match) {
            $offset = (int) $match[0][1];
            $this->pushText(substr($text, $last, $offset - $last), $last);
            $last = $offset + strlen((string) $match[0][0]);

            $line = $this->lineOf($offset);
            $closing = $match[1][0] !== null;
            $slug = (string) $match[2][0];
            $attrStr = (string) ($match[3][0] ?? '');
            $selfClose = ($match[4][0] ?? null) !== null;

            if ($closing) {
                $top = $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
                if ($top === null || $top['slug'] !== $slug) {
                    $this->err($line, 'err_stray_close', ['slug' => $slug]);
                    continue;
                }
                $this->attach(array_pop($this->stack));
                continue;
            }

            // Real slugs win; then the tag aliases (p, h1…h6 — level rides the tag).
            $name = $this->slugToName[$slug] ?? null;
            $preset = [];
            if ($name === null && isset(ShortcodeDialect::TAG_SHORT[$slug])) {
                $short = ShortcodeDialect::TAG_SHORT[$slug];
                $name = $this->slugToName[$short['slug']] ?? null;
                $preset = $short['attrs'];
            }

            if ($name === null) {
                $this->err($line, 'err_unknown_block', ['slug' => $slug]);
                if (! $selfClose) {
                    $this->stack[] = ['slug' => $slug, 'dummy' => true, 'body' => [], 'line' => $line];
                }
                continue;
            }

            $contract = $this->registry[$name];
            $parent = $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
            if ($parent !== null && empty($parent['dummy'])
                && empty($parent['contract']['innerBlocks']['enabled'])) {
                $this->err($line, 'err_no_children', ['slug' => $parent['slug']]);
            }

            $frame = [
                'slug' => $slug,
                'contract' => $contract,
                'body' => [],
                'line' => $line,
                'dummy' => false,
                'model' => [
                    'name' => $name,
                    'attributes' => $preset,
                    'supports' => [],
                    'innerBlocks' => [],
                ],
            ];

            // Errors point at the attribute's OWN line — a pretty-printed tag
            // spans several lines, so the tag's first line is usually wrong.
            $attrsAt = $offset + 1 + strlen($slug);
            preg_match_all(
                ShortcodeDialect::ATTR_RE,
                $attrStr,
                $attrs,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
            );

            foreach ($attrs as $attr) {
                // A quoted value is group 2 even when empty; unquoted is group 3.
                $value = $attr[2][0] !== null
                    ? ShortcodeDialect::unescAttr((string) $attr[2][0])
                    : (string) ($attr[3][0] ?? '');

                $this->applyAttr(
                    $contract,
                    $frame['model'],
                    (string) $attr[1][0],
                    $value,
                    $this->lineOf($attrsAt + (int) $attr[0][1]),
                    $slug,
                );
            }

            if ($selfClose) {
                $this->stack[] = $frame;
                $this->attach(array_pop($this->stack));
            } else {
                $this->stack[] = $frame;
            }
        }

        $this->pushText(substr($text, $last), $last);

        foreach ($this->stack as $frame) {
            if (empty($frame['dummy'])) {
                $this->err($frame['line'], 'err_unclosed', ['slug' => $frame['slug'], 'line' => $frame['line']]);
            }
        }

        return ['blocks' => $this->rootBlocks, 'errors' => $this->errors];
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $model
     */
    private function applyAttr(array $contract, array &$model, string $rawName, string $raw, int $line, string $slug): void
    {
        $defs = (array) ($contract['attributeDefinitions'] ?? []);

        $state = '';
        $name = $rawName;
        $colon = strpos($name, ':');
        if ($colon !== false && $colon > 0 && in_array(substr($name, 0, $colon), ShortcodeDialect::STATES, true)) {
            $state = substr($name, 0, $colon);
            $name = substr($name, $colon + 1);
        }

        // Contract attributes win over aliases; a state prefix always means supports.
        if ($state === '' && array_key_exists($name, $defs)) {
            $def = (array) ($defs[$name] ?? []);
            $type = $def['type'] ?? null;
            $value = $raw;

            if ($type === 'boolean') {
                $value = $raw === 'true' || $raw === '1';
            } elseif ($type === 'integer' || $type === 'number') {
                $value = ShortcodeDialect::jsNumber($raw);
                if ($value === null) {
                    $this->err($line, 'err_invalid_value', ['name' => $rawName, 'slug' => $slug]);

                    return;
                }
            } elseif ($type === 'object' || $type === 'media' || $type === 'array') {
                $decoded = json_decode($raw, true);
                if ($decoded === null && trim($raw) !== 'null') {
                    $this->err($line, 'err_invalid_value', ['name' => $rawName, 'slug' => $slug]);

                    return;
                }
                $value = $decoded;
            }

            $enum = $def['enum'] ?? null;
            if (is_array($enum) && $enum !== [] && ! in_array($value, $enum, true)) {
                $this->err($line, 'err_invalid_value', ['name' => $rawName, 'slug' => $slug]);

                return;
            }

            $model['attributes'][$name] = $value;

            return;
        }

        $box = ShortcodeDialect::BOX_SHORTHANDS[$name] ?? null;
        $path = $box !== null ? $box['path'] : (ShortcodeDialect::ALIASES[$name] ?? $name);
        $group = explode('.', $path)[0];

        if (! is_array($contract['supports'] ?? null) || ! array_key_exists($group, $contract['supports'])) {
            $this->err($line, 'err_unknown_attr', ['name' => $rawName, 'slug' => $slug]);

            return;
        }

        // A long-form state path must name a REAL state — `states.300.…` would
        // round-trip forever and never emit any CSS.
        if ($group === 'states') {
            $segments = explode('.', $path);
            if (count($segments) < 3 || ! in_array($segments[1] ?? '', ShortcodeDialect::STATES, true)) {
                $this->err($line, 'err_unknown_attr', ['name' => $rawName, 'slug' => $slug]);

                return;
            }
        }

        $prefix = $state !== '' ? "states.{$state}." : '';

        if ($box !== null) {
            $declared = ShortcodeDialect::dataGet($contract['supports'], $box['path']);
            $values = array_slice(preg_split('/\s+/', trim($raw)) ?: [''], 0, 4);
            // A side-map declaration (or a multi-value) expands CSS-style; a
            // scalar declaration with one value writes the scalar path directly.
            if (is_array($declared) || count($values) > 1) {
                $sides = ShortcodeDialect::expandBox(array_values($values), $box['keys']);
                foreach ($box['keys'] as $key) {
                    ShortcodeDialect::setPath($model['supports'], $prefix . $box['path'] . '.' . $key, $sides[$key]);
                }

                return;
            }
        }

        ShortcodeDialect::setPath($model['supports'], $prefix . $path, $raw);
    }

    /** @param array<string, mixed> $frame */
    private function attach(array $frame): void
    {
        if (! empty($frame['dummy'])) {
            return;
        }

        $rich = ShortcodeDialect::richAttrOf($frame['contract']);
        $body = ShortcodeDialect::dedent(implode('', $frame['body']));

        if ($rich !== null) {
            if ($body !== '') {
                $frame['model']['attributes'][$rich] = $body;
            }
        } elseif (trim($body) !== '' && $frame['model']['innerBlocks'] === []) {
            $this->err($frame['line'], 'err_no_body', ['slug' => $frame['slug']]);
        }

        $depth = count($this->stack);
        if ($depth > 0 && empty($this->stack[$depth - 1]['dummy'])) {
            $this->stack[$depth - 1]['model']['innerBlocks'][] = $frame['model'];
        } elseif ($depth === 0) {
            $this->rootBlocks[] = $frame['model'];
        }
    }

    private function pushText(string $chunk, int $at): void
    {
        if ($chunk === '') {
            return;
        }

        $depth = count($this->stack);
        if ($depth > 0) {
            $this->stack[$depth - 1]['body'][] = $chunk;

            return;
        }

        if (preg_match('/\S/', $chunk, $m, PREG_OFFSET_CAPTURE) === 1) {
            $this->err($this->lineOf($at + (int) $m[0][1]), 'err_outside', []);
        }
    }

    /** @param array<string, mixed> $replace */
    private function err(int $line, string $key, array $replace): void
    {
        $this->errors[] = [
            'line' => $line,
            'message' => (string) __("heisenberg::editor.code.{$key}", $replace),
        ];
    }

    private function lineOf(int $index): int
    {
        return substr_count(substr($this->text, 0, $index), "\n") + 1;
    }
}
