<?php

declare(strict_types=1);

namespace Heisenberg\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

/**
 * Built-in `text` formatter (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1): formats plain text for substitution into subject, body, alt,
 * caption, and other string attributes. Always returns raw text. The
 * interpolator HTML-escapes rich-text replacements before sanitization;
 * plain-text consumers such as MIME subjects keep the raw string.
 *
 * Coerces scalars to string; throws on non-stringables so a host mistake
 * surfaces as a strict error rather than a silent empty substitution.
 */
final class TextEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'text';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'text') cannot be null; supply a scalar value.",
                $definition->key,
            ));
        }

        throw new \InvalidArgumentException(sprintf(
            "Email variable '%s' (type 'text') expects a scalar value, got %s.",
            $definition->key,
            get_debug_type($value),
        ));
    }
}