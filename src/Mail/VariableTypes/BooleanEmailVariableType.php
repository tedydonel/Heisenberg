<?php

declare(strict_types=1);

namespace Heisenberg\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

/**
 * Built-in `boolean` formatter (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1): formats a bool as a text-substitutable string. Defaults to
 * `Yes` / `No` (the smallest textual representation a non-ASCII recipient
 * won't get wrong); a host that wants `true` / `false` passes
 * `options['format' => 'code']`, a host that wants `on` / `off` passes
 * `options['format' => 'toggle']`, etc.
 */
final class BooleanEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'boolean';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (! is_bool($value)) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'boolean') expects a bool, got %s.",
                $definition->key,
                get_debug_type($value),
            ));
        }

        $style = (string) ($definition->options['format'] ?? 'word');

        return match ($style) {
            'code' => $value ? 'true' : 'false',
            'toggle' => $value ? 'on' : 'off',
            'word' => $value ? 'Yes' : 'No',
            default => throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'boolean') has unknown 'format' option '%s'.",
                $definition->key,
                $style,
            )),
        };
    }
}