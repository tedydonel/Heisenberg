<?php

declare(strict_types=1);

namespace Heisenberg\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

/**
 * Built-in `date` formatter (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1): formats a DateTimeInterface / timestamp / ISO-8601 string into
 * a localized date string. Defaults to the ISO `Y-m-d` representation
 * (the most portable default — an email is read in many clients, and
 * many of them lack locale-aware date parsing entirely); a host that
 * wants a locale-aware format registers its own formatter (e.g. on top
 * of `ext-intl`'s `IntlDateFormatter`) and passes `options['format']`
 * to opt into a richer representation.
 *
 * Timestamp / string inputs are normalized to `DateTimeImmutable` so a
 * formatter that ignores `format()`'s `get_debug_type()` checks still
 * has a single normalization seam.
 */
final class DateEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'date';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        $date = $this->normalize($value, $definition);

        $format = (string) ($definition->options['format'] ?? 'Y-m-d');

        return $date->format($format);
    }

    private function normalize(mixed $value, EmailVariableDefinition $definition): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            return (new \DateTimeImmutable())->setTimestamp($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(sprintf(
                    "Email variable '%s' (type 'date') could not parse the supplied value as a date.",
                    $definition->key,
                ), 0, $e);
            }
        }

        throw new \InvalidArgumentException(sprintf(
            "Email variable '%s' (type 'date') expects a DateTimeInterface, timestamp, or ISO-8601 string; got %s.",
            $definition->key,
            get_debug_type($value),
        ));
    }
}