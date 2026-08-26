<?php

declare(strict_types=1);

namespace Heisenberg\Support;

use Throwable;

/**
 * Aggregated failure for Task 2's context-aware interpolator
 * (.hermes/plans/2026-08-25_190059-email-template-variables.md, Task 2). Every
 * resolution failure — unknown token, missing runtime value, formatter failure,
 * formatter target incompatibility — is collected into ONE exception carrying:
 *
 *  - the keys involved (`user.first_name`, `unsubscribe_url`, …),
 *  - a safe `reason` per failure ("unknown token", "formatter failed", "target
 *    incompatible"),
 *  - NOTHING else: no runtime values, no sample values, no formatter-internal
 *    exception messages. A formatter that throws `\RuntimeException` with a
 *    message exposing the supplied value is replaced with a safe reason here.
 *
 * The aggregated shape is what the plan's "Interpolation algorithm" step 9
 * pins: "throw one exception containing keys/reasons only — never values."
 *
 * The exception's `getMessage()` is a deterministic, value-free summary; the
 * full list of failures lives on {@see self::getFailures()} so a caller can
 * surface it without leaking runtime data into a log line or HTTP response.
 */
final class EmailVariableResolutionException extends \RuntimeException
{
    /** @var list<array{key: string, reason: string}> */
    private array $failures;

    /**
     * @param list<array{key: string, reason: string}> $failures
     */
    public function __construct(array $failures, ?Throwable $previous = null)
    {
        $this->failures = array_values($failures);

        // Deterministic, value-free summary: "Email variable resolution failed
        // for N token(s): key1:reason1; key2:reason2; …". The reasons are
        // sanitised (no values), so a log line or HTTP response body built from
        // this string never leaks a runtime value or a formatter secret.
        $parts = [];
        foreach ($failures as $failure) {
            $parts[] = sprintf('%s: %s', $failure['key'], $failure['reason']);
        }

        parent::__construct(
            sprintf(
                'Email variable resolution failed for %d token(s): %s',
                count($failures),
                implode('; ', $parts),
            ),
            0,
            $previous,
        );
    }

    /**
     * Convenience constructor for the SINGLE-failure path. Aggregates to the
     * same shape as the multi-failure constructor; the resulting exception's
     * {@see self::getKey()} / {@see self::getReason()} helpers stay useful for
     * callers that want the first failure in isolation.
     *
     * @param self::REASON_* $reason
     */
    public static function single(string $key, string $reason, ?Throwable $previous = null): self
    {
        return new self([['key' => $key, 'reason' => $reason]], $previous);
    }

    public const REASON_UNKNOWN_TOKEN = 'unknown token';
    public const REASON_MISSING_VALUE = 'missing value';
    public const REASON_FORMATTER_FAILED = 'formatter failed';
    public const REASON_INCOMPATIBLE_TARGET = 'formatter target incompatible';

    /** @return list<array{key: string, reason: string}> */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /** @return list<string> distinct keys that failed (insertion order). */
    public function getKeys(): array
    {
        $out = [];
        foreach ($this->failures as $failure) {
            $out[] = $failure['key'];
        }

        return array_values(array_unique($out));
    }

    /** The FIRST failing key — for callers that want one key per exception. */
    public function getKey(): string
    {
        return $this->failures[0]['key'] ?? '';
    }

    /** The FIRST failing reason — paired with {@see self::getKey()}. */
    public function getReason(): string
    {
        return $this->failures[0]['reason'] ?? '';
    }
}
