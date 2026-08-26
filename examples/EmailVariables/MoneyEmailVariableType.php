<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Heisenberg email variable — host custom `money` formatter
|--------------------------------------------------------------------------
|
| This is an EXAMPLE of a host-registered variable type. It implements the
| exact contract Heisenberg ships — `Heisenberg\Contracts\EmailVariableType`
| — and nothing else: `key()`, `targets()`, and `format(mixed, definition,
| locale): string`. Registering this class via
| `EmailVariableRegistry::registerType(new MoneyEmailVariableType())` in
| your `AppServiceProvider::boot(...)` makes the type `money` available
| for every `EmailVariableDefinition($type: 'money', ...)`.
|
| The class below assumes a HOST-DOMAIN `Money` value object (just a tiny
| illustrative placeholder, NOT part of Heisenberg). The host that copies
| this example swaps the constructor/types for its own Money package
| (brick/math, moneyphp/money, a custom Domain\Money, …). The example is
| here to show the Heisenberg contract — what the host plugs INTO it is
| host-owned code.
|
| Placeholder class at the bottom of the file is clearly labeled and only
| exists so this example passes `php -l` standalone. Drop it before you
| copy this file into a real host app; your app's Money type replaces it.
|
| The plan reference: `.hermes/plans/2026-08-25_190059-email-template-variables.md`,
| Tasks 1 (contract) + 2 (interpolation), §"Locked API decisions" §3
| (formatter contract).
*/

namespace App\Mail\VariableTypes; // <-- host namespace; replace with your app's

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;
use Host\Money;

final class MoneyEmailVariableType implements EmailVariableType
{
    /**
     * The formatter type identifier. Stored on `EmailVariableDefinition::$type`
     * and unique across every registered formatter — the registry rejects
     * duplicate keys at registration time, so two host providers cannot
     * both claim `money`.
     */
    public function key(): string
    {
        return 'money';
    }

    /**
     * Which substitution TARGETS this formatter may feed. Heisenberg
     * recognises three:
     *   - `text`  — plain text / rich text body / subject / alt / caption /
     *               title (rich-text replacements are HTML-escaped before
     *               the block renderer; plain-text consumers escape at
     *               their boundary).
     *   - `url`   — anchor `href`, image `src`, button URL. The raw
     *               formatted URL is substituted before
     *               `BlockRenderer::safeUrl()` runs.
     *   - `email` — same URL gate; the resolver sees it as an email
     *               address when deciding scheme policy.
     *
     * Money amounts only make sense in textual contexts — return `['text']`.
     * The registry validates that the list is non-empty, unique, and only
     * contains values from that closed set; a typo here fails fast at boot.
     *
     * @return list<'text'|'url'|'email'>
     */
    public function targets(): array
    {
        return ['text'];
    }

    /**
     * Format one value into a string for substitution. The runtime caller
     * passes whatever the host supplied in the flat runtime map (a sample
     * context passes the registered `sample`). The definition carries
     * the static `options` the host registered; the locale is the one
     * Heisenberg is currently rendering in.
     *
     * Implementations MUST throw on unsupported values — Heisenberg
     * aggregates those into `EmailVariableResolutionException`, never
     * sends or zips a partial result. The interpolator also discards
     * any thrown message at the throw site (Task 2): a host formatter
     * that throws `\RuntimeException('host-secret: <value>')` results in
     * a `{key, REASON_FORMATTER_FAILED}` entry with NO runtime value in
     * the message. The host owns the failure surface; Heisenberg owns
     * the leak surface.
     */
    public function format(
        mixed $value,
        EmailVariableDefinition $definition,
        string $locale,
    ): string {
        // Sample-mode safety: when `editorMetadata()` formats the
        // definition's registered `sample`, the registry passes that
        // sample through here. A non-scalar host sample (a Money object)
        // is the WHOLE POINT of the registry's "samples may be non-scalar
        // for custom formatters" rule (Task 1 test name); build the
        // formatters around accepting a Money, not a string.
        if (! $value instanceof Money) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'money') expects a Money value; got %s.",
                $definition->key,
                get_debug_type($value),
            ));
        }

        // `definition->options['currency']` is the host's own static
        // metadata; the formatter reads it freely. A host that wants
        // locale-aware thousands separators or symbol position reads
        // the `$locale` argument here (we keep the example simple and
        // avoid `ext-intl` — Heisenberg deliberately ships without it,
        // see Task 1 §Step 2 GREEN and the bundled `number` formatter).
        $decimals = 2;

        return sprintf(
            '%s %s',
            $value->currency,
            number_format($value->amount, $decimals, '.', ','),
        );
    }
}

/*
|--------------------------------------------------------------------------
| Host-domain placeholder
|--------------------------------------------------------------------------
|
| `Money` is a HOST value object, NOT a Heisenberg class. The example
| above uses it only so this file is self-contained and passes
| `php -l` standalone. Replace it with your host's own Money type
| (or any value object your formatter accepts) when copying this
| example into a real app. The class is namespaced under `Host\` to
| make the boundary obvious — Heisenberg will never see it.
*/

namespace Host;

final class Money
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {
    }
}
