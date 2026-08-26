<?php

declare(strict_types=1);

namespace Heisenberg\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

/**
 * Built-in `url` formatter (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1): formats a string URL for substitution into anchor `href`, image
 * `src`, button URL attributes. The interpolator substitutes the raw
 * formatted string BEFORE {@see \Heisenberg\Services\BlockRenderer::safeUrl()}
 * runs — that gate is what enforces the actual `javascript:` / `data:`
 * allow-list, not this formatter. A formatter that returns `javascript:…`
 * is a host bug, and {@see \Heisenberg\Services\BlockRenderer::safeUrl()} will
 * reject it the same way it rejects an authored literal.
 */
final class UrlEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'url';
    }

    public function targets(): array
    {
        return ['url'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'url') expects a non-empty string URL.",
                $definition->key,
            ));
        }

        return $value;
    }
}