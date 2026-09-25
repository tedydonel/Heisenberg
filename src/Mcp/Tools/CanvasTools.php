<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

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

            // THE translation tool (docs/content-translation.md §0). The target locale is always
            // explicit, so a translation lands in that locale's slots whichever locale is on screen,
            // and never in the home text. Validated here, applied client-side like write_canvas.
            'translate_page' => [
                'description' => 'Translate the page open in the editor into target_locale. This is the ONLY way to '
                    . 'translate: use it for every translation request, whatever locale is on screen. Everything '
                    . 'you pass lands in target_locale only — the source text and every other locale are left '
                    . 'untouched. `code` is the whole document translated: the SAME block sequence and structure '
                    . 'as the page, only human-readable text translated (never ids, URLs, media or attribute '
                    . 'names); a structural mismatch is refused with nothing applied. Also pass `title` (the '
                    . 'translated page title) and, when the page has a table of contents, `toc` — a translation '
                    . "is unfinished without them. target_locale must not be the post's home locale: that is the "
                    . 'source text, edit it with write_canvas instead. Nothing is saved to the database — the user '
                    . 'reviews and saves.',
                'tier' => self::TIER_AUTHORS,
                'surface' => self::SURFACE_EDITOR,
                'inputSchema' => ToolSchema::schema([
                    'target_locale' => ['type' => 'string', 'description' => 'The language to translate INTO, e.g. "fr". Not the home locale.'],
                    'code' => ['type' => 'string', 'description' => 'The whole document translated, as Heisenberg shortcode: same blocks and structure, text translated.'],
                    'title' => ['type' => 'string', 'description' => 'The page title translated into target_locale, plain text.'],
                    'toc' => [
                        'type' => 'array',
                        'description' => 'The table of contents translated: one {anchor (unchanged), label (translated)} per entry.',
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
        return in_array($tool, ['write_canvas', 'set_page_title', 'translate_page'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'write_canvas' => $this->writeCanvas($arguments),
            'set_page_title' => $this->setPageTitle($arguments),
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
     * Validated here, applied by the panel into target_locale's slots. What cannot be checked
     * here — the page's structure and its home locale live in the browser — the editor checks
     * before applying anything (block-runtime's foldTranslation()).
     *
     * @param array<string, mixed> $args
     */
    private function translatePage(array $args): array
    {
        $target = trim((string) ($args['target_locale'] ?? ''));
        if (! LocaleConfig::isValid($target)) {
            throw new McpToolException('target_locale must be one of: ' . implode(', ', LocaleConfig::locales()) . " (got '{$target}').");
        }

        $code = trim((string) ($args['code'] ?? ''));
        $title = trim((string) ($args['title'] ?? ''));
        $toc = $args['toc'] ?? null;
        if ($code === '' && $title === '' && ! is_array($toc)) {
            throw new McpToolException('Pass at least one of code, title, toc — there is nothing to translate.');
        }

        $blocks = $code === '' ? [] : $this->blocks->contentBlocks(['code' => $code]);
        if ($code !== '' && $blocks === []) {
            throw new McpToolException('The code contained no blocks — supply the whole document, translated.');
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

        return ['applied' => true, 'target_locale' => $target, 'blocks' => count($blocks), 'title' => $title !== '', 'toc' => is_array($toc) ? count($toc) : 0];
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
