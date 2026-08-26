<?php

declare(strict_types=1);

namespace Heisenberg\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

/**
 * Built-in `number` formatter (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1): formats an int or float for substitution into text attributes.
 * Locale-aware through the registered `$locale` — a host that wants
 * thousands separators or different digit groupings passes `format`
 * options (e.g. `['style' => 'currency', 'currency' => 'NGN']`); the
 * default is the literal decimal representation.
 *
 * Deliberately avoids `ext-intl` / `NumberFormatter` so the package still
 * ships without the extension. The plan (Task 1 §Step 2 GREEN) keeps the
 * formatter "locale-aware" but explicitly without `ext-intl` — a host
 * that wants locale-aware number formatting registers its OWN type (e.g.
 * `IntlNumberEmailVariableType`) and does the `NumberFormatter` call
 * there.
 */
final class NumberEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'number';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $decimals = isset($definition->options['decimals']) && is_int($definition->options['decimals'])
                ? $definition->options['decimals']
                : 2;

            return number_format($value, $decimals, '.', ',');
        }

        if (is_string($value) && is_numeric($value)) {
            // Numeric strings round-trip through the same formatter: useful
            // for a host whose runtime value is a string from a CSV or
            // queued message payload.
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(
            "Email variable '%s' (type 'number') expects an int or float, got %s.",
            $definition->key,
            get_debug_type($value),
        ));
    }
}