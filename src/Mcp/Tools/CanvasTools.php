<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Ai\TranslationSource;
use Heisenberg\Mcp\Support\ContentBlockPipeline;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\McpToolException;
use Heisenberg\Support\LocaleConfig;

/**
 * The in-editor assistant's live-canvas tools — `write_canvas`, `set_page_title` and
 * `translate_page` — the ONE write path to the page open in front of the user. Editor-surface only: execution
 * is split, this provider validates the shortcode against the live contracts (so the
 * model gets line-numbered errors back through the tool channel), and the PANEL applies
 * the validated code to the editor when the call's arguments arrive on the stream
 * (AiToolRunner ships them, panel-ai applies them). Nothing is persisted here — the
 * document stays unsaved client state until the user saves, same as hand-drawn blocks.
 */
final class CanvasTools implements McpToolProvider
{
    public function __construct(private ContentBlockPipeline $blocks)
    {
    }

    public function definitions(): array
    {
        return [
            // Translation is NOT this tool's job (see translate_page below): here, content is written
            // into the locale on screen — the home locale's blocks, or, while another locale is on
            // screen, that locale's text for the same blocks (position-matched, applied client-side).
            'write_canvas' => [
                'description' => 'Write Heisenberg shortcode directly into the editor the user is looking at. '
                    . 'The blocks land on the canvas immediately — this is THE way to build or edit the current '
                    . 'page. mode "append" (default) adds the blocks after what is already on the page; mode '
                    . '"replace" swaps the whole document for the supplied code (pass the full updated document '
                    . 'to rework or restructure existing content). It writes into the locale on screen. NEVER '
                    . 'use it to translate — translate_page is the only translation tool. While a locale other '
                    . "than the post's home locale is on screen, the code is THAT locale's text for the same blocks "
                    . '(same block sequence, only text changed, mode="replace" only). Nothing is saved to the '
                    . 'database — the user reviews and saves. The '
                    . 'code is validated against the live block contracts; on a parse error nothing is applied '
                    . 'and the error names the line to fix. '
                    . ToolSchema::LAYOUT_GUIDANCE,
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => ToolSchema::schema([
                    'code' => ['type' => 'string', 'description' => 'The content, as Heisenberg shortcode.'],
                    'mode' => ['type' => 'string', 'description' => '"append" (default) adds after the current page content; "replace" swaps the whole document. "append" is refused while translating a non-home locale.'],
                ], ['code']),
            ],

            // Same client-applied split as write_canvas: validated here, landed
            // in the editor's title field by the panel when the frame arrives.
            'set_page_title' => [
                'description' => 'Set the title of the page open in the editor. It fills the editor\'s title '
                    . 'field immediately (the user still saves), so use it whenever you write a page that '
                    . 'deserves a headline — do not leave a built page untitled or ask the user to type the '
                    . 'title themselves.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => ToolSchema::schema([
                    'title' => ['type' => 'string', 'description' => 'The page title, plain text.'],
                ], ['title']),
            ],

            // Translation is TEXT (docs/content-translation.md §0.4): translation_source hands out the
            // page's translatable text keyed by where it lives, translate_page brings it back
            // translated under the same ids. The model never re-types the page's markup, which is
            // what made a translation slow, and the target locale is always named.
            'translation_source' => [
                'description' => 'The text to translate for the page open in the editor: `segments` maps an id to each '
                    . 'piece of source text, plus the source `title` and the saved table of contents (`toc`, empty '
                    . 'when the page has none). Call it first whenever you are asked to translate, then call '
                    . 'translate_page ONCE with everything translated.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => ToolSchema::schema([]),
            ],

            'translate_page' => [
                'description' => 'Translate the page open in the editor into target_locale — the ONLY way to translate, '
                    . 'whatever locale is on screen. Pass, in ONE call: `segments` (every id from translation_source, '
                    . 'its text translated; keep inline HTML tags and line breaks), `title` (translated) and, only when '
                    . 'translation_source returned any, `toc` (each anchor unchanged, its label translated). Everything '
                    . 'lands in target_locale only — the source and every other locale are untouched. If something was '
                    . 'left out, call again with ONLY what is missing; never resend what was already applied. '
                    . "target_locale must not be the post's home locale: that is the source text. Nothing is saved to "
                    . 'the database — the user reviews and saves.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => ToolSchema::schema([
                    'target_locale' => ['type' => 'string', 'description' => 'The language to translate INTO, e.g. "fr". Not the home locale.'],
                    'segments' => [
                        'type' => 'object',
                        'description' => 'id (from translation_source) => the translated text.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'title' => ['type' => 'string', 'description' => 'The page title translated into target_locale, plain text.'],
                    'toc' => [
                        'type' => 'array',
                        'description' => 'The table of contents translated: one {anchor (unchanged), label (translated)} per entry translation_source returned.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'anchor' => ['type' => 'string', 'description' => 'The entry\'s anchor, copied unchanged.'],
                                'label' => ['type' => 'string', 'description' => 'The translated label, plain text.'],
                            ],
                            'required' => ['anchor', 'label'],
                        ],
                    ],
                ], ['target_locale']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['write_canvas', 'set_page_title', 'translation_source', 'translate_page'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'write_canvas' => $this->writeCanvas($arguments),
            'set_page_title' => $this->setPageTitle($arguments),
            'translation_source' => $this->translationSource(),
            'translate_page' => $this->translatePage($arguments),
            default => throw new \LogicException("CanvasTools does not handle '{$tool}'."),
        };
    }

    /** @param array<string, mixed> $args */
    private function writeCanvas(array $args): array
    {
        $mode = (string) ($args['mode'] ?? 'append');
        if (! in_array($mode, ['append', 'replace'], true)) {
            throw new McpToolException('mode must be "append" or "replace".');
        }

        $blocks = $this->blocks->contentBlocks(['code' => (string) ($args['code'] ?? '')]);
        if ($blocks === []) {
            throw new McpToolException('The code contained no blocks — supply shortcode with at least one block tag.');
        }

        return ['applied' => true, 'mode' => $mode, 'blocks' => count($blocks)];
    }

    /**
     * The open page's translatable text for this turn ({@see TranslationSource}, bound per request
     * by the AI controller from the panel's context). Read-only; empty outside an editor turn.
     *
     * @return array{segments: array<string, string>|\stdClass, title: string, toc: list<array{anchor: string, label: string}>}
     */
    private function translationSource(): array
    {
        $source = app()->bound(TranslationSource::class) ? app(TranslationSource::class) : new TranslationSource();

        return [
            'segments' => $source->segments === [] ? new \stdClass() : $source->segments,
            'title' => $source->title,
            'toc' => $source->toc,
        ];
    }

    /**
     * Validated here, applied by the panel into target_locale's slots. What cannot be checked
     * here — the page and its home locale live in the browser — the editor checks before writing
     * anything (block-runtime's translateSegments()).
     *
     * @param array<string, mixed> $args
     */
    private function translatePage(array $args): array
    {
        $target = trim((string) ($args['target_locale'] ?? ''));
        if (! LocaleConfig::isValid($target)) {
            throw new McpToolException('target_locale must be one of: ' . implode(', ', LocaleConfig::locales()) . " (got '{$target}').");
        }

        $segments = $args['segments'] ?? [];
        if (! is_array($segments)) {
            throw new McpToolException('segments must be an object of id => translated text.');
        }
        foreach ($segments as $id => $text) {
            if (! is_string($id) || preg_match('/^hb\d+\.[A-Za-z][A-Za-z0-9_]*$/', $id) !== 1) {
                throw new McpToolException("segments: '{$id}' is not an id from translation_source.");
            }
            if (! is_string($text)) {
                throw new McpToolException("segments.{$id} must be the translated text.");
            }
        }

        $title = trim((string) ($args['title'] ?? ''));
        $toc = $args['toc'] ?? null;
        if ($segments === [] && $title === '' && ! is_array($toc)) {
            throw new McpToolException('Pass at least one of segments, title, toc — there is nothing to translate.');
        }
        if (mb_strlen($title) > 200) {
            throw new McpToolException('title must be 200 characters or fewer.');
        }
        if (is_array($toc)) {
            foreach (array_values($toc) as $i => $entry) {
                if (! is_array($entry) || trim((string) ($entry['anchor'] ?? '')) === '' || trim((string) ($entry['label'] ?? '')) === '') {
                    throw new McpToolException("toc[{$i}] needs a non-empty anchor and label.");
                }
            }
        }

        return ['applied' => true, 'target_locale' => $target, 'segments' => count($segments), 'title' => $title !== '', 'toc' => is_array($toc) ? count($toc) : 0];
    }

    /** @param array<string, mixed> $args */
    private function setPageTitle(array $args): array
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw new McpToolException('title must be a non-empty string.');
        }
        if (mb_strlen($title) > 200) {
            throw new McpToolException('title must be 200 characters or fewer.');
        }

        return ['applied' => true, 'title' => $title];
    }
}
