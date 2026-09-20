<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Rendering;
use Heisenberg\Rendering\BlockStyleCompiler;
use Heisenberg\Rendering\BlockTreeRenderer;
use Heisenberg\Rendering\CssValueSanitizer;
use Heisenberg\Rendering\EmbedUrlResolver;
use Heisenberg\Rendering\HtmlEscaper;
use Heisenberg\Rendering\RichTextSanitizer;
use Heisenberg\Rendering\SafeUrlResolver;
use Heisenberg\Rendering\TemplateInterpolator;

/**
 * Server-authoritative block renderer: every HTML byte that reaches a page or an
 * email goes through this pipeline. A thin FACADE over the collaborators in
 * {@see Rendering} — it owns none of the walking/sanitizing logic
 * itself, only wiring, the handful of public constants other services pin their
 * own limits/patterns to, and the public method signatures existing callers
 * (controllers, {@see EmailRenderer}, MCP tools, console commands, tests) depend on.
 *
 * SECURITY-CRITICAL. The actual security model — escaping, scheme allow-listing,
 * CSS token validation, rich-text tag/attribute scrubbing, the nesting-depth cap —
 * lives in, and is documented on, the collaborator that implements it:
 *
 *   - {@see BlockTreeRenderer}   the contract-driven tree walk (render.template /
 *                                email.template), tag/class/attribute resolution,
 *                                the iframe/video src allow-list gates.
 *   - {@see BlockStyleCompiler}  `style.variables` → inline CSS, and the
 *                                interaction-state stylesheet ({@see stateStylesCss()}).
 *   - {@see CssValueSanitizer}   every CSS value's token grammar.
 *   - {@see RichTextSanitizer}   the rich-text tag/attribute allow-list.
 *   - {@see EmbedUrlResolver}    the embed/self-hosted-video src gates.
 *   - {@see SafeUrlResolver}     the src/href scheme allow-list.
 *   - {@see TemplateInterpolator} `{{ ... }}` token + locale resolution.
 *   - {@see HtmlEscaper}         the one HTML-escaping primitive.
 *
 * The final heavyweight HTMLPurifier pass (HtmlSanitizationService::purify) is the
 * publish/render job's backstop over the WHOLE output — mirroring GTC; this
 * renderer does not run it. Blocks are JSON-only ({name, attributes, …}); there is
 * no legacy {type, content} path.
 */
class BlockRenderer
{
    /**
     * Hard cap on inner-block nesting depth — defends against pathologically deep
     * trees. Public: {@see BlocksPayloadService} reuses this exact limit when
     * validating a save payload's `innerBlocks` tree, so the save-time rejection
     * and the render-time silent drop never drift apart. Canonical value lives on
     * {@see BlockTreeRenderer}, which is the collaborator that actually enforces it.
     */
    public const MAX_NESTING_DEPTH = BlockTreeRenderer::MAX_NESTING_DEPTH;

    /**
     * Iframe src allowlist (embed block). Canonical value + docblock live on
     * {@see EmbedUrlResolver}, which is also the last line of defence applying it.
     * Public so tests can assert against the real constant instead of a copy that
     * could silently drift from it.
     */
    public const EMBED_SRC_PATTERN = EmbedUrlResolver::EMBED_SRC_PATTERN;

    /**
     * Self-hosted `<video src>` allowlist. Canonical value + docblock live on
     * {@see EmbedUrlResolver}.
     */
    public const EMBED_FILE_SRC_PATTERN = EmbedUrlResolver::EMBED_FILE_SRC_PATTERN;

    /**
     * Interaction states the model may style. Canonical value + docblock live on
     * {@see BlockStyleCompiler}, which is also what compiles them into CSS.
     */
    public const INTERACTION_STATES = BlockStyleCompiler::INTERACTION_STATES;

    private BlockStyleCompiler $styleCompiler;

    private BlockTreeRenderer $treeRenderer;

    public function __construct(private BlockRegistryService $registry)
    {
        // Constructed ONCE here (this service is bound as a container singleton) and
        // reused for every block on every render — a hot path with no per-block
        // container lookups or repeated disk reads.
        $this->styleCompiler = new BlockStyleCompiler($this->registry);
        $this->treeRenderer = new BlockTreeRenderer($this->registry, $this->styleCompiler);
    }

    /**
     * `$surface` selects WHICH top-level contract section supplies the template tree —
     * `'render'` (the default, web output) or `'email'` ({@see EmailRenderer}, the only
     * caller that ever passes it). See {@see BlockTreeRenderer::renderBlocks()} for the
     * full contract.
     */
    public function renderBlocks(array $blocks, string $locale, string $surface = 'render'): string
    {
        return $this->treeRenderer->renderBlocks($blocks, $locale, $surface);
    }

    public function renderBlock(array $block, string $locale, string $surface = 'render'): string
    {
        return $this->treeRenderer->renderBlock($block, $locale, $surface);
    }

    /**
     * Compile the per-instance interaction-state CSS for a list of blocks (recursing
     * inner blocks). See {@see BlockStyleCompiler::stateStylesCss()} for the full contract.
     */
    public function stateStylesCss(array $blocks, int $depth = 0): string
    {
        return $this->styleCompiler->stateStylesCss($blocks, $depth);
    }

    /**
     * Normalize a pasted video URL to a canonical, allow-listed player src for the
     * `embed` block. See {@see EmbedUrlResolver::embedSrcFor()} for the full contract.
     */
    public static function embedSrcFor(string $url): string
    {
        return EmbedUrlResolver::embedSrcFor($url);
    }

    /**
     * Validate a pasted link to a self-hosted video FILE for `<video src>`. See
     * {@see EmbedUrlResolver::embedFileSrcFor()} for the full contract.
     */
    public static function embedFileSrcFor(string $url): string
    {
        return EmbedUrlResolver::embedFileSrcFor($url);
    }
}
