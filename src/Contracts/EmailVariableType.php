<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Support\EmailVariableDefinition;

/**
 * A formatter that turns one PHP value into a string for substitution into a
 * Heisenberg email document (.hermes/plans/2026-08-25_190059-email-template-variables.md,
 * Task 1 — "host-extensible variable registry and formatter contract").
 *
 * A host registers one implementation per formatter TYPE it needs
 * (a Money type, an enum type, a host-domain ID type, …) via
 * {@see \Heisenberg\Services\EmailVariableRegistry::registerType()}; variables
 * using that type are then registered via
 * {@see \Heisenberg\Services\EmailVariableRegistry::register()} with their
 * static `key`, `label`, `group`, `description`, `sample`, and `options`.
 *
 * `key()` is the type identifier stored on
 * {@see \Heisenberg\Support\EmailVariableDefinition::$type} and is unique
 * across all registered formatters — the registry rejects duplicate keys.
 *
 * `targets()` lists which ATTRIBUTE TARGETS this formatter may substitute
 * into. Heisenberg recognises three:
 *   - `text`  — plain text / rich text body / subject / alt / caption /
 *               title. Rich-text replacements are HTML-escaped before the
 *               block renderer; plain-text consumers escape at their boundary.
 *   - `url`   — anchor `href`, image `src`, button URL — values substitute
 *               before {@see \Heisenberg\Services\BlockRenderer::safeUrl()}.
 *   - `email` — the same URL attribute gate, plus the resolver sees it as
 *               an email address when deciding scheme policy.
 *
 * `format()` produces the final string for substitution. The host owns the
 * failure surface — every contract violation in a host-supplied formatter
 * surfaces to the caller as a {@see \Heisenberg\Support\EmailVariableResolutionException}
 * (Task 2). Implementations MUST be locale-aware via the `$locale` argument
 * (e.g. localized date format), and MUST NOT depend on `ext-intl`.
 */
interface EmailVariableType
{
    public function key(): string;

    /**
     * The attribute targets this formatter may substitute into.
     *
     * @return list<'text'|'url'|'email'>
     */
    public function targets(): array;

    /**
     * Format one value into a string for substitution.
     *
     * The runtime caller passes the value the host supplied in the
     * flat runtime map (sample context passes the static sample). The
     * definition carries the static options the host registered; the
     * locale is the one Heisenberg is currently rendering in.
     *
     * Implementations MUST throw (`\InvalidArgumentException` /
     * `\RuntimeException`) for unsupported values — Heisenberg aggregates
     * those into the task-2 resolution exception, never sends or zips a
     * partial result.
     */
    public function format(
        mixed $value,
        EmailVariableDefinition $definition,
        string $locale,
    ): string;
}