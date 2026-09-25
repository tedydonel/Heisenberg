<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Http\Controllers\PostController;
use Heisenberg\Mcp\Support\ContentBlockPipeline;
use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Tools\BlockTools;
use Heisenberg\Mcp\Tools\CanvasTools;
use Heisenberg\Mcp\Tools\EmailTools;
use Heisenberg\Mcp\Tools\IconTools;
use Heisenberg\Mcp\Tools\McpToolProvider;
use Heisenberg\Mcp\Tools\MediaTools;
use Heisenberg\Mcp\Tools\PostLifecycleTools;
use Heisenberg\Mcp\Tools\PostSettingsTools;
use Heisenberg\Mcp\Tools\PostTools;
use Heisenberg\Mcp\Tools\RevisionTools;
use Heisenberg\Mcp\Tools\SeoTools;
use Heisenberg\Mcp\Tools\TaxonomyTools;
use Heisenberg\Mcp\Tools\ThemeTools;
use Heisenberg\Mcp\Tools\TranslationTools;
use Heisenberg\Mcp\Tools\WebTools;

/**
 * The tools Heisenberg exposes over MCP — to external AIs via the inbound
 * server (`routes/mcp.php`) AND to the in-editor assistant (via
 * {@see HeisenbergToolSource}). This class is the FAÇADE: it collects every
 * {@see McpToolProvider} (one per tool domain, under `Heisenberg\Mcp\Tools`), merges
 * their descriptors into `tools/list`'s catalogue, tier/surface-filters the result, and
 * dispatches `tools/call` to whichever provider owns the requested name. It holds no
 * tool business logic itself anymore — that lives in the providers and in the shared
 * `Heisenberg\Mcp\Support` collaborators they're built on
 * ({@see ContentBlockPipeline}, {@see PostAccess}).
 *
 * The governing rule: **every write goes through the same pipeline the editor
 * uses**, never around it. `create_post` and `update_post` (and everything
 * built on top of them — `restore_revision`) build the exact envelope
 * {@see BlocksPayloadService::validatePayload()} expects and refuse the write if it
 * fails, so an agent gets the same contract validation, the same sanitization and the
 * same unknown-block dropping as a human clicking Save — see
 * {@see ContentBlockPipeline::validatedContentBlocks()}, the ONE
 * place that call happens. Every content write that touches an EXISTING post also
 * snapshots the post's prior state into the revisions table first (mirroring
 * {@see PostController::captureRevision()}), so nothing
 * here is a one-way door — see {@see PostAccess::captureRevision()}.
 *
 * Tools are tiered (read/authors/admins) AND surfaced (editor/external). The
 * tier is the MCP-token vocabulary (who may call read-only vs. write tools at
 * all); the surface is a SEPARATE axis that decides whether a tool exists for
 * the in-editor assistant, the inbound MCP server, or both — see
 * {@see self::SURFACE_EDITOR}. Both are enforced twice: hidden from
 * `tools/list` for a caller that doesn't qualify, AND refused again if called
 * by name anyway in {@see self::call()}. Hiding alone would be security by
 * obscurity.
 */
class McpToolRegistry
{
    /** Read-only; available to any valid token. */
    public const TIER_READ = McpToolProvider::TIER_READ;

    /** Create and edit content. */
    public const TIER_AUTHORS = McpToolProvider::TIER_AUTHORS;

    /** Publish, and anything that changes site-wide state. */
    public const TIER_ADMINS = McpToolProvider::TIER_ADMINS;

    /** Ascending privilege — a token's tier satisfies every tier at or below it. */
    private const TIER_ORDER = [self::TIER_READ, self::TIER_AUTHORS, self::TIER_ADMINS];

    /**
     * The in-editor assistant ({@see HeisenbergToolSource}) — a human is
     * driving, so this surface may reach further than an unattended external
     * caller (e.g. it may change a post's lifecycle status).
     */
    public const SURFACE_EDITOR = McpToolProvider::SURFACE_EDITOR;

    /**
     * The inbound MCP server (`routes/mcp.php`) — any agent holding a valid
     * bearer token, with no human necessarily reviewing what it does. This is
     * the default surface: a tool with no `surface` entry is offered here too.
     */
    public const SURFACE_EXTERNAL = McpToolProvider::SURFACE_EXTERNAL;

    /**
     * The full `tools/list` catalogue, in the exact order every caller has always seen
     * it in. Providers group tools by DOMAIN (posts, taxonomy, SEO, …), which is not the
     * same sequence the catalogue is published in — this list is the single source of
     * truth for that public order, independent of which provider implements what.
     * {@see self::tools()} fails fast (a missing array key) if a provider stops
     * supplying one of these names, or a name here doesn't match any provider.
     *
     * @var list<string>
     */
    private const TOOL_ORDER = [
        'list_blocks', 'describe_block', 'list_email_variables', 'search_icons', 'search_web',
        'write_canvas', 'set_page_title', 'translation_source', 'translate_page',
        'list_posts', 'get_post', 'create_post', 'update_post', 'render_preview',
        'create_translation', 'set_post_status',
        'list_categories', 'list_tags', 'create_category', 'update_category',
        'create_tag', 'update_tag', 'attach_category', 'detach_category', 'attach_tag', 'detach_tag',
        'list_media', 'update_media',
        'get_seo', 'update_seo', 'analyze_seo',
        'set_page_layout', 'set_discussion', 'set_featured_image',
        'list_revisions', 'restore_revision',
        'trash_post', 'restore_post',
        'get_theme',
    ];

    /** @var list<McpToolProvider> */
    private array $providers;

    public function __construct(
        BlockTools $blockTools,
        IconTools $iconTools,
        WebTools $webTools,
        EmailTools $emailTools,
        ThemeTools $themeTools,
        CanvasTools $canvasTools,
        PostTools $postTools,
        TranslationTools $translationTools,
        PostLifecycleTools $postLifecycleTools,
        PostSettingsTools $postSettingsTools,
        TaxonomyTools $taxonomyTools,
        MediaTools $mediaTools,
        SeoTools $seoTools,
        RevisionTools $revisionTools,
    ) {
        // Order here is arbitrary (TOOL_ORDER governs the published catalogue order) —
        // listed roughly as the catalogue used to read, for a reviewer's convenience.
        $this->providers = [
            $blockTools, $emailTools, $iconTools, $webTools,
            $canvasTools,
            $postTools, $translationTools, $postLifecycleTools,
            $taxonomyTools,
            $mediaTools,
            $seoTools,
            $postSettingsTools,
            $revisionTools,
            $themeTools,
        ];
    }

    public static function tierSatisfies(string $tokenTier, string $required): bool
    {
        $have = array_search($tokenTier, self::TIER_ORDER, true);
        $need = array_search($required, self::TIER_ORDER, true);

        return $have !== false && $need !== false && $have >= $need;
    }

    /**
     * Tool descriptors visible to a token of `$tier` on `$surface`, in MCP's
     * `tools/list` shape.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listFor(string $tier, string $surface = self::SURFACE_EXTERNAL): array
    {
        $out = [];
        foreach ($this->tools() as $name => $tool) {
            if (! self::tierSatisfies($tier, $tool['tier']) || ! $this->surfaceAllows($tool, $surface)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return $out;
    }

    /**
     * Every tool with the tier it needs — what the settings modal's Expose tab
     * renders for the EXTERNAL surface (that tab configures the inbound MCP
     * server; an editor-only tool like `set_post_status` is never a candidate
     * to expose there, so it is left out by default). Generated from the same
     * table `tools/list` answers from, so the UI cannot advertise a tool the
     * server does not have.
     *
     * @return list<array{name: string, description: string, tier: string}>
     */
    public function describeAll(string $surface = self::SURFACE_EXTERNAL): array
    {
        $out = [];
        foreach ($this->tools() as $name => $tool) {
            if (! $this->surfaceAllows($tool, $surface)) {
                continue;
            }
            $out[] = ['name' => $name, 'description' => $tool['description'], 'tier' => $tool['tier']];
        }

        return $out;
    }

    /**
     * Execute a tool. Returns MCP's `tools/call` result shape — content blocks
     * plus an `isError` flag, because a tool that failed is a normal result to
     * the model, not a transport error.
     *
     * @param array<string, mixed> $arguments
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function call(string $name, array $arguments, string $tier, string $surface = self::SURFACE_EXTERNAL): array
    {
        $tools = $this->tools();
        if (! isset($tools[$name])) {
            return $this->error("Unknown tool '{$name}'");
        }
        if (! self::tierSatisfies($tier, $tools[$name]['tier'])) {
            return $this->error("Tool '{$name}' requires the '{$tools[$name]['tier']}' tier; this token has '{$tier}'.");
        }
        if (! $this->surfaceAllows($tools[$name], $surface)) {
            return $this->error("Tool '{$name}' is not available on this surface.");
        }

        $provider = $this->providerFor($name);
        if ($provider === null) {
            return $this->error("Unknown tool '{$name}'");
        }

        try {
            // $surface is passed as a third argument for the (currently none) handlers
            // whose behavior differs by surface once past the tier/surface gate above —
            // every provider simply ignores it today. Kept so a future handler can opt
            // into it without an interface change.
            return $this->ok($provider->call($name, $arguments, $surface));
        } catch (McpToolException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            // A tool handler that throws something other than a McpToolException is an
            // infrastructure fault (a DB error, a null-pointer bug, …), not a domain
            // refusal — but the model must never lose the result channel over it: without
            // this it would see nothing at all for the call it made. report() keeps the
            // fault visible to us; the model gets an actionable-enough message to move on.
            report($e);
            $class = $e::class;

            return $this->error(
                "The tool failed unexpectedly ({$class}): {$e->getMessage()}. Proceed without it or try a different approach."
            );
        }
    }

    private function providerFor(string $name): ?McpToolProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->handles($name)) {
                return $provider;
            }
        }

        return null;
    }

    /** @param array{surface?: string} $tool */
    private function surfaceAllows(array $tool, string $surface): bool
    {
        $required = $tool['surface'] ?? null;

        return $required === null || $required === $surface;
    }

    /**
     * Every provider's {@see McpToolProvider::definitions()}, merged and re-ordered to
     * {@see self::TOOL_ORDER} — the published catalogue.
     *
     * @return array<string, array{description: string, tier: string, inputSchema: array<string, mixed>, surface?: string}>
     */
    private function tools(): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            $all += $provider->definitions();
        }

        $ordered = [];
        foreach (self::TOOL_ORDER as $name) {
            $ordered[$name] = $all[$name];
        }

        return $ordered;
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private function ok(mixed $result): array
    {
        return [
            'content' => [['type' => 'text', 'text' => (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'isError' => false,
        ];
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
