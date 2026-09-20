<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\EmailVariableCatalog;

/** `list_email_variables` — metadata only for the host-defined email personalization variables. */
final class EmailTools implements McpToolProvider
{
    public function __construct(private EmailVariableCatalog $emailVariables)
    {
    }

    public function definitions(): array
    {
        return [
            'list_email_variables' => [
                'description' => 'List the host-defined email personalization variables available to the current email editor. Returns metadata only (key, label, description, group); never recipient values. Use only these exact keys when authoring email content.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([]),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return $tool === 'list_email_variables';
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return ['variables' => $this->emailVariables->definitions()];
    }
}
