<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * A tool failed in a way the calling model should see and can act on — an
 * unknown block, unparseable shortcode, a stale content_version.
 *
 * Deliberately distinct from a transport error: MCP returns these as a normal
 * `tools/call` result with `isError: true`, so the agent reads the message and
 * retries. A JSON-RPC error would look like the server broke.
 */
class McpToolException extends \RuntimeException
{
}
