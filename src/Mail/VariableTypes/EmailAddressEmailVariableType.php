<?php

declare(strict_types=1);

namespace Heisenberg\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

/**
 * Built-in `email` formatter (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1): formats an email-address string for substitution into the same
 * URL attribute gate that {@see UrlEmailVariableType} feeds. The block
 * renderer's URL gate is what enforces the actual `mailto:` scheme and the
 * "value looks like an email" check; this formatter is a labelled target
 * so the editor's picker can offer `email` as its own filterable category
 * (the interpolator's target compatibility check stays simple).
 */
final class EmailAddressEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'email';
    }

    public function targets(): array
    {
        return ['email', 'url'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'email') expects a non-empty string email address.",
                $definition->key,
            ));
        }

        return $value;
    }
}