<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Ai\McpServer;

/**
 * The OUTBOUND half of Heisenberg's MCP support: talking to somebody else's MCP
 * server so the editor's assistant can use its tools. (The inbound half — other
 * AIs authoring pages here — is an HTTP controller, not this contract.)
 *
 * Heisenberg runs this loop itself rather than delegating to a provider's
 * server-side MCP connector, because that connector is Anthropic-only and this
 * package ships two provider families. Owning the loop also means MCP works
 * with servers on localhost or inside a VPC, which a provider-side connector
 * cannot reach.
 *
 * **Everything returned by an MCP server is untrusted input.** A tool result is
 * third-party text arriving mid-conversation; a tool *description* is
 * third-party text that the model reads as instructions. Neither may reach the
 * block tree without going through BlocksPayloadService + HtmlSanitizationService
 * first, and neither may widen what a tool is allowed to do — that is fixed by
 * {@see McpServer::allows()} before execution.
 */
interface McpClient
{
    /**
     * Discover the tools a server offers.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function listTools(McpServer $server): array;

    /**
     * Execute one tool.
     *
     * Implementations must re-check {@see McpServer::allows()} rather than
     * trusting the caller — this is the last gate before a third party's code
     * runs on our behalf.
     *
     * @param  array<string, mixed> $arguments
     * @return array{content: string, isError: bool}
     */
    public function callTool(McpServer $server, string $tool, array $arguments): array;
}
