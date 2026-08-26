<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Mail\VariableTypes\BooleanEmailVariableType;
use Heisenberg\Mail\VariableTypes\DateEmailVariableType;
use Heisenberg\Mail\VariableTypes\EmailAddressEmailVariableType;
use Heisenberg\Mail\VariableTypes\NumberEmailVariableType;
use Heisenberg\Mail\VariableTypes\TextEmailVariableType;
use Heisenberg\Mail\VariableTypes\UrlEmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\LocaleConfig;

/**
 * Host-extensible variable registry for Heisenberg emails
 * (.hermes/plans/2026-08-25_190059-email-template-variables.md, Task 1).
 *
 * Two collections, both programmatically grown at boot time:
 *  - TYPES — keyed by {@see EmailVariableType::key()}, one formatter per
 *    type. Bundled formatters (`text`, `url`, `email`, `number`, `boolean`,
 *    `date`) are auto-registered here. Hosts add their own
 *    (Money-style, Enum-style, host-domain ID-style) via
 *    {@see self::registerType()}.
 *  - DEFINITIONS — keyed by dotted `key`, one per host-registered
 *    variable. Each carries `label`, `group`, `description`, `options`,
 *    a SAFE `sample`, and the formatter `type` to use at format time.
 *    Hosts add their own via {@see self::register()}.
 *
 * CENTRAL VALIDATION here (Locked decision §1, §2, §4):
 *  - `$key` matches the conservative dotted-identifier pattern
 *    `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$` — non-empty, no whitespace,
 *    no leading/trailing dots, no double dots, starts with a letter. A
 *    reserved BlockRenderer root (`id`, `name`, `attributes`, `supports`,
 *    `lang`) is rejected: those are real attribute paths the block
 *    renderer already reads, and a registered variable would collide
 *    with an internal lookup.
 *  - `$type` resolves to a registered formatter; an unknown type fails at
 *    `register()` time so a misconfiguration cannot ship half-built.
 *  - Duplicate keys AND duplicate type keys throw — silent override would
 *    let one host provider's `Money` type quietly win over another host
 *    provider's, and that is a debugging nightmare with no upside.
 *
 * EDITOR METADATA: {@see self::editorMetadata()} returns the union of every
 * registered definition's static fields, with each `sample` passed through
 * its formatter when metadata is first requested so the editor's picker can
 * show a literal preview string (e.g. `NGN 1,000.00`) without ever holding
 * the underlying value object. Formatter objects, closures, and host
 * classes NEVER appear in that array — the array is what Task 5's
 * email-only insertion UI consumes.
 *
 * SAMPLE FORMAT PATH: the editor needs a literal string for its picker
 * entry, not a callable. {@see self::editorMetadata()} runs
 * `format($definition->sample, $definition, $defaultLocale)` once per
 * registry state, so a host registering a non-scalar sample (Money,
 * a DateRange, …) does not need a separate "sample-as-string" override.
 * The formatter is the single source of truth for how a sample renders.
 *
 * SINGLETON (Task 1 §Step 2): Heisenberg binds this as a singleton in
 * {@see \Heisenberg\HeisenbergServiceProvider::registerEngine()} next to
 * `EmailRenderer`. A host provider that wants to register types /
 * definitions in `boot(...)` resolves this instance — registration order
 * is preserved within one container boot, but a host that registers
 * across multiple boot passes (rare; usually a misconfiguration) sees
 * the later registration win ONLY when the keys are genuinely distinct.
 */
class EmailVariableRegistry
{
    /** @var array<string, EmailVariableType> */
    private array $types = [];

    /** @var array<string, EmailVariableDefinition> */
    private array $definitions = [];

    /** @var array<string, string>|null memoized sample → formatted string */
    private ?array $formattedSamples = null;

    /**
     * The conservative identifier pattern a `$key` must match (Locked decision
     * §1, §2). Starts with a letter, only `[a-z0-9_]` inside segments, segments
     * joined by single dots — no leading/trailing/double dots. The plan's target
     * public usage shows both single-segment keys (`unsubscribe_url`) and
     * multi-segment ones (`user.first_name`), so the pattern is "may contain
     * dots" rather than "must contain a dot" — single-segment keys are still
     * disallowed when they collide with a reserved BlockRenderer root.
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * BlockRenderer roots Heisenberg owns outright — registering a variable
     * at one of these roots or beneath it would silently collide with an
     * internal lookup at render time. Rejected at registration.
     *
     * @var list<string>
     */
    private const RESERVED_KEYS = ['id', 'name', 'attributes', 'supports', 'lang'];

    /** @var list<'text'|'url'|'email'> */
    private const VALID_TARGETS = ['text', 'url', 'email'];

    public function __construct()
    {
        $this->registerBuiltInTypes();
    }

    /**
     * Add a host (or test) formatter implementation. Duplicate keys fail
     * deterministically — a host with two providers both trying to claim
     * `money` cannot ship.
     */
    public function registerType(EmailVariableType $type): void
    {
        $key = $type->key();

        if (isset($this->types[$key])) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable type '%s' is already registered; duplicate formatter types are not allowed.",
                $key
            ));
        }

        $this->validateTargets($type);

        $this->types[$key] = $type;
    }

    /**
     * Add a host (or test) variable definition. Validates the key shape,
     * rejects reserved BlockRenderer roots, and resolves the formatter
     * `type` — an unknown type fails here, not at render time. Samples are
     * formatted lazily when editor metadata is requested.
     */
    public function register(EmailVariableDefinition $definition): void
    {
        $this->validateKey($definition->key);

        if (trim($definition->label) === '') {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' must have a non-empty label.",
                $definition->key,
            ));
        }

        if (isset($this->definitions[$definition->key])) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' is already registered; duplicate keys are not allowed.",
                $definition->key
            ));
        }

        if (! isset($this->types[$definition->type])) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' references unknown formatter type '%s'; register the formatter first via registerType().",
                $definition->key,
                $definition->type
            ));
        }

        $this->definitions[$definition->key] = $definition;
        // Bust the sample-format memoization so the new entry shows up on
        // the next editorMetadata() call without a stale "first sample
        // wins" bug across registrations.
        $this->formattedSamples = null;
    }

    /** @return list<EmailVariableDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function definition(string $key): ?EmailVariableDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function type(string $key): EmailVariableType
    {
        if (! isset($this->types[$key])) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable type '%s' is not registered.",
                $key
            ));
        }

        return $this->types[$key];
    }

    /** @return list<EmailVariableType> */
    public function types(): array
    {
        return array_values($this->types);
    }

    /**
     * Format a value through a definition's registered formatter. Used by
     * Task 2's interpolator once it knows the target (`text` / `url` /
     * `email`); exposed publicly so a host can render its own preview
     * without going through the renderer.
     */
    public function format(EmailVariableDefinition $definition, mixed $value, string $locale): string
    {
        return $this->type($definition->type)->format($value, $definition, $locale);
    }

    /**
     * Editor-safe definition metadata — the JSON-serializable array the
     * email-only insertion UI (Task 5) consumes. NEVER includes formatter
     * objects, closures, host classes, runtime values, or unformatted
     * samples: every entry's `sample` is the formatted STRING produced by
     * the registered formatter when metadata is requested.
     *
     * @return list<array<string, mixed>>
     * @throws \InvalidArgumentException when a registered sample cannot be formatted
     */
    public function editorMetadata(): array
    {
        $this->ensureFormattedSamples();

        $rows = [];
        foreach ($this->definitions as $key => $definition) {
            $row = [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type,
                'targets' => $this->type($definition->type)->targets(),
                'group' => $definition->group,
                'description' => $definition->description,
                'options' => $definition->options,
                'sample' => $this->formattedSamples[$key] ?? '',
            ];

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Validate a dotted key. Empty / non-string / whitespace-bearing /
     * double-dotted / leading-or-trailing-dot / starting-with-digit keys
     * all fail; reserved BlockRenderer roots fail with a clearer message
     * so a host hitting it knows the collision is intentional, not random.
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException(
                'Email variable key must not be empty.'
            );
        }

        $root = explode('.', $key, 2)[0];
        if (in_array($root, self::RESERVED_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable key '%s' is reserved by BlockRenderer and cannot be registered.",
                $key
            ));
        }

        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable key '%s' is invalid; expected a conservative dotted identifier (e.g. 'user.first_name').",
                $key
            ));
        }
    }

    private function validateTargets(EmailVariableType $type): void
    {
        $targets = $type->targets();
        $valid = $targets !== []
            && array_is_list($targets)
            && count($targets) === count(array_unique($targets))
            && array_diff($targets, self::VALID_TARGETS) === [];

        if (! $valid) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable type '%s' must declare a non-empty unique list of text, url, or email targets.",
                $type->key(),
            ));
        }
    }

    /**
     * Compute (and memoize) one formatted sample per registered definition,
     * keyed by the same `key`. Memoized so `editorMetadata()` is cheap on
     * the editor hot path; busted by every {@see self::register()} call.
     *
     * @return array<string, string>
     */
    private function ensureFormattedSamples(): array
    {
        if ($this->formattedSamples !== null) {
            return $this->formattedSamples;
        }

        $out = [];
        foreach ($this->definitions as $key => $definition) {
            try {
                $out[$key] = $this->type($definition->type)->format(
                    $definition->sample,
                    $definition,
                    $this->sampleLocale(),
                );
            } catch (\Throwable $e) {
                // A formatter that throws on its own sample is a host bug
                // — surface it with enough context for the host to fix it,
                // but never ship a partial editor view that hides the
                // broken variable behind an empty string.
                throw new \InvalidArgumentException(sprintf(
                    "Email variable '%s' sample cannot be formatted by type '%s': %s",
                    $definition->key,
                    $definition->type,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        $this->formattedSamples = $out;

        return $out;
    }

    /**
     * The locale used when formatting samples for the editor. The editor's
     * own chrome locale drives this in Task 5 (the picker shows the
     * sample in the language the author is editing in); for now, fall
     * back to the configured default so a host without an explicit choice
     * still gets a deterministic string.
     */
    private function sampleLocale(): string
    {
        try {
            return (string) LocaleConfig::default();
        } catch (\Throwable) {
            return 'en';
        }
    }

    /** Auto-register the six built-in formatters. */
    private function registerBuiltInTypes(): void
    {
        $this->registerType(new TextEmailVariableType());
        $this->registerType(new UrlEmailVariableType());
        $this->registerType(new EmailAddressEmailVariableType());
        $this->registerType(new NumberEmailVariableType());
        $this->registerType(new BooleanEmailVariableType());
        $this->registerType(new DateEmailVariableType());
    }
}