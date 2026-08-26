<?php

declare(strict_types=1);

namespace Heisenberg\Support;

use Heisenberg\Services\EmailVariableRegistry;

/**
 * Immutable flat value map for one email render (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1, contract). One context carries a NAMED MODE — `runtime` for a
 * real render / mailable / batch export, `sample` for every author-facing
 * GET endpoint (preview, size, single export).
 *
 * DELIBERATELY NARROW:
 *  - Runtime values are stored exactly as supplied. The interpolator checks
 *    token keys against the registry; this value object does not normalize,
 *    drop, or resolve keys.
 *  - Values are NEVER persisted (this object is runtime-only) and NEVER
 *    serialized to the editor (the editor's metadata surface is the
 *    {@see \Heisenberg\Services\EmailVariableRegistry::editorMetadata()}
 *    call, which is definition-only).
 *  - The context does NOT contain users, models, the application, or the
 *    container. The host passes the final values; Heisenberg does no Eloquent
 *    introspection (Locked decision §3.2 of the plan).
 *  - The `mode` field exists so the interpolator (Task 2) can fail loud
 *    when a `runtime` context is missing a value, but a `sample` context
 *    missing the same key is acceptable IF the registry still has a sample
 *    to fall back to. The plan keeps strict / sample split visible at the
 *    boundary object so the interpolator doesn't need to look at the
 *    registry itself for every token.
 */
final class EmailVariableContext
{
    public const MODE_RUNTIME = 'runtime';
    public const MODE_SAMPLE = 'sample';

    /**
     * @param array<string, mixed>  $values  flat dotted-key → value map
     * @param string                $mode    self::MODE_RUNTIME | self::MODE_SAMPLE
     */
    private function __construct(
        private readonly array $values,
        private readonly string $mode,
    ) {
    }

    /**
     * Build a strict RUNTIME context from a host-supplied flat map. Every
     * key the host passes is reachable as-is; missing keys remain missing,
     * the interpolator (Task 2) handles the missing-value failure path.
     *
     * @param array<string, mixed>|self $values either a flat map or an
     *                                      existing context (idempotent pass-through)
     */
    public static function runtime(array|self $values): self
    {
        if ($values instanceof self) {
            return $values;
        }

        return new self($values, self::MODE_RUNTIME);
    }

    /**
     * Build a SAMPLE context from a registry: only registered definitions'
     * `sample` values appear in the map, keyed by the registered `key`.
     * Editor preview, public preview, size measurement, and single-document
     * HTML/EML export all funnel through this method so runtime values
     * never leak into an author-facing surface.
     */
    public static function samples(EmailVariableRegistry $registry): self
    {
        $values = [];
        foreach ($registry->definitions() as $definition) {
            $values[$definition->key] = $definition->sample;
        }

        return new self($values, self::MODE_SAMPLE);
    }

    /** `'runtime'` or `'sample'`. */
    public function mode(): string
    {
        return $this->mode;
    }

    public function isRuntime(): bool
    {
        return $this->mode === self::MODE_RUNTIME;
    }

    public function isSample(): bool
    {
        return $this->mode === self::MODE_SAMPLE;
    }

    /** True when the named key exists; explicit null is a value for the formatter to handle. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** Null when the key is missing — the interpolator decides the policy. */
    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Plain PHP array view — used by the interpolator (Task 2) and any host
     * helper that wants to iterate. Order matches insertion order.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

}