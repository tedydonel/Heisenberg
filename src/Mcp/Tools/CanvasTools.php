<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ContentBlockPipeline;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\McpToolException;

/**
 * The in-editor assistant's live-canvas tools — `write_canvas`/`set_page_title` — the
 * ONE write path to the page open in front of the user. Editor-surface only: execution
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
            // docs/content-translation.md §0/Wave 2: the editor turn's `editing_locale`/
            // `home_locale` (see EditorPrompt::user()) tell the model when it is translating,
            // not this tool — write_canvas has no view of the editor's current document (it
            // lives in the browser, possibly never saved), so it cannot itself compare the
            // supplied code's structure against what is already on the canvas. That
            // position-matched comparison, and the actual non-replacing fold, happen
            // CLIENT-side (block-runtime.blade.php's foldTranslation, applied by panel-ai's
            // applyCanvasTool) the moment this call's arguments land on the stream — mirroring
            // McpToolRegistry::foldTranslatedBlocks(), the same rule create_translation
            // enforces server-side for a SAVED post. This description restates the rule so a
            // model that skims tool descriptions instead of the system prompt still gets it.
            'write_canvas' => [
                'description' => 'Write Heisenberg shortcode directly into the editor the user is looking at. '
                    . 'The blocks land on the canvas immediately — this is THE way to build or edit the current '
                    . 'page. mode "append" (default) adds the blocks after what is already on the page; mode '
                    . '"replace" swaps the whole document for the supplied code (pass the full updated document '
                    . 'to rework or restructure existing content). If the editor is showing a locale other than '
                    . "the post's home locale (see the user turn's editing/home locale), you are TRANSLATING: "
                    . 'reproduce the SAME block sequence with only human-readable text changed — never add, '
                    . 'remove, or reorder blocks, and never change ids/urls/media refs; the editor applies this '
                    . 'as a position-matched fold and rejects (with no partial change) a mismatched structure. '
                    . 'mode="append" is refused while translating — tell the user to switch to the home locale '
                    . 'to add new blocks. Nothing is saved to the database — the user reviews and saves. The '
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
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['write_canvas', 'set_page_title'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'write_canvas' => $this->writeCanvas($arguments),
            'set_page_title' => $this->setPageTitle($arguments),
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
