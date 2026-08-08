<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Ai\McpServer;
use Heisenberg\Contracts\McpClient;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Services\AiSettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The MCP Servers tab's backend: probe a server and report what it offers, so
 * an operator can fill the per-tool allow-list from real tool names instead of
 * guessing them.
 *
 * Admins-tier, like the rest of the AI configuration — adding an MCP server
 * decides which third party the assistant may call, which is an operator
 * decision, not an authoring one.
 *
 * Servers themselves are stored through the ordinary settings PUT
 * (`mcp_servers`), where {@see AiSettingsRepository} enforces the http(s)-only
 * URL rule and refuses anything credential-shaped. This controller only reads.
 */
class AiMcpController
{
    public function __construct(
        private AiSettingsRepository $settings,
        private McpClient $mcp,
    ) {
    }

    /**
     * Probe one server. Accepts either a saved server's `id` or an inline
     * `url`/`auth_env` pair, so a server can be tested BEFORE it is saved —
     * otherwise an operator has to persist a broken entry to find out it's
     * broken.
     */
    public function test(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAdmin($request)) !== null) {
            return $denied;
        }

        $server = $this->resolve($request);
        if ($server === null) {
            return response()->json(['ok' => false, 'error' => 'Unknown server.'], 422);
        }

        if (! $server->isConfigured()) {
            return response()->json([
                'ok' => false,
                'error' => $server->authEnv !== null
                    ? "The environment variable {$server->authEnv} is not set."
                    : 'This server has no URL.',
            ], 422);
        }

        try {
            $tools = $this->mcp->listTools($server);
        } catch (\Throwable $e) {
            // The message is ours, not the upstream body — see HttpMcpClient.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'tools' => array_map(static fn (array $t): array => [
                'name' => $t['name'],
                'description' => $t['description'],
                // Whether this tool is currently callable, so the tab can show
                // the allow-list's real effect rather than just the raw list.
                'allowed' => $server->allows($t['name']),
            ], $tools),
        ]);
    }

    private function resolve(Request $request): ?McpServer
    {
        $url = trim((string) $request->input('url', ''));
        if ($url !== '') {
            return McpServer::fromArray([
                'id' => (string) $request->input('id', 'probe'),
                'label' => (string) $request->input('label', ''),
                'url' => $url,
                'auth_env' => $request->input('auth_env'),
                // A probe lists tools; it never calls one, so the allow-list is
                // irrelevant here and `enabled` only gates `allows()` reporting.
                'enabled' => true,
                'allowed_tools' => (array) $request->input('allowed_tools', []),
            ]);
        }

        $id = (string) $request->input('id', '');
        foreach ($this->settings->mcpServers() as $server) {
            if ($server->id === $id) {
                return $server;
            }
        }

        return null;
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        return $roleGate->is($actor, 'admins')
            ? null
            : response()->json(['ok' => false, 'error' => __('heisenberg::editor.ai.forbidden')], 403);
    }
}
