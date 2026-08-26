<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * Static metadata for one host-registered email variable — the registry's
 * input shape (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1). A definition is a VALUE OBJECT (no behavior): a host constructs
 * one per variable in its own `AppServiceProvider::boot(...)` and hands it
 * to {@see \Heisenberg\Services\EmailVariableRegistry::register()}.
 *
 * The shape is intentionally narrow:
 *  - `$key`     dotted identifier, validated centrally by the registry
 *               (not by this class) so the error surface stays uniform
 *               across every definition produced in the same host.
 *  - `$label`   human-readable label the editor's variable picker renders
 *               (already resolved server-side by the host's own locale).
 *  - `$type`    formatter key registered via
 *               {@see \Heisenberg\Services\EmailVariableRegistry::registerType()}.
 *               Resolved centrally — an unknown type fails at `register()`
 *               time, not silently at render time.
 *  - `$sample`  non-secret SAFE placeholder value used for editor preview,
 *               public preview, size measurement, and single-document
 *               exports — every author-facing GET endpoint substitutes
 *               samples, NEVER runtime values. May be non-scalar when the
 *               host's custom formatter accepts it (Task 1 test
 *               "sample_context_supports_non_scalar_samples_for_custom_formatters").
 *  - `$group`   optional human-readable group label (`User`, `Campaign`,
 *               `Account`) for the picker's grouping.
 *  - `$description` optional longer description surfaced as a tooltip /
 *               secondary line in the picker.
 *  - `$options` formatter-specific bag — for the bundled `number`/`date`
 *               formatters it carries e.g. `currency` / `format`; for a
 *               host's custom type it carries whatever the formatter
 *               reads in {@see \Heisenberg\Contracts\EmailVariableType::format()}.
 *
 * RUNTIME VALUES NEVER APPEAR HERE — a definition carries only static
 * metadata, not the per-recipient value map. The flat runtime map lives on
 * {@see \Heisenberg\Support\EmailVariableContext}, a separate object passed
 * to {@see \Heisenberg\Services\EmailRenderer::render()} or
 * {@see \Heisenberg\Mail\HeisenbergMailable}'s third constructor argument.
 */
final class EmailVariableDefinition
{
    /**
     * @param string                $key         dotted identifier (e.g. `user.first_name`)
     * @param string                $label       picker label
     * @param string                $type        formatter key (`text`, `url`, `email`,
     *                                          `number`, `boolean`, `date`, or a
     *                                          host-registered custom key)
     * @param mixed                 $sample      safe non-secret sample used by every
     *                                          author-facing GET (preview, size, single
     *                                          export) — may be scalar or a host object
     * @param string|null           $group       optional picker group label
     * @param string|null           $description optional picker description
     * @param array<string, mixed>  $options     formatter-specific options bag
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $sample,
        public readonly ?string $group = null,
        public readonly ?string $description = null,
        public readonly array $options = [],
    ) {
    }
}