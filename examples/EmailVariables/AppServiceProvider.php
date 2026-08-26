<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Heisenberg email variables — host AppServiceProvider boot
|--------------------------------------------------------------------------
|
| This is an EXAMPLE of a host's `AppServiceProvider::boot(...)` registering
| the host's own variables with Heisenberg's email-variable registry.
| Copy it into your real `app/Providers/AppServiceProvider.php` (or a
| dedicated `App\Providers\HeisenbergEmailVariablesServiceProvider` if your
| app prefers small providers), swap the placeholder Money class for your
| own value type, and you have working editor metadata, sample-only
| preview/size/single-document exports, the renderer/mailable seam, and
| the admin batch export.
|
| The example covers every category the plan §"Locked product decisions"
| calls out:
|   - Register custom formatter TYPES first (a host Money type).
|   - Register each DEFINITION with a NON-SECRET `sample` (mandatory —
|     the editor preview, public preview, size measurement, and the
|     single-document HTML/EML export all substitute samples only).
|   - Use dotted keys (`user.first_name`) AND single-segment keys
|     (`unsubscribe_url`) — both are allowed by
|     `EmailVariableRegistry::KEY_PATTERN`.
|   - Pass `group` / `description` / formatter `options` so the editor
|     picker (Task 5) and the bundled formatters can use them.
|
| WHAT THIS FILE DOES NOT DO:
|   - It does not register post authors, editors, admins, or any user
|     tier. Heisenberg's roles map (`config('heisenberg.roles')`) is
|     the host's job; this provider only adds EMAIL variables.
|   - It does not configure SMTP. Heisenberg does not own SMTP. EML
|     batch export reads `mail.from.address` only for the `From:` header
|     — that is a host `config/mail.php` concern, not this provider's.
|   - It does not persist anything. Definitions are registered in
|     memory on the registry singleton; the runtime value map lives on
|     `EmailVariableContext::runtime([...])` and is consumed
|     synchronously at render / mailable / batch time.
|
| The plan reference: `.hermes/plans/2026-08-25_190059-email-template-variables.md`,
| Tasks 1 (registry / definition / built-in types) + the §"Target public
| usage" block.
*/

namespace App\Providers; // <-- host namespace; replace with your app's

use App\Mail\VariableTypes\MoneyEmailVariableType; // see MoneyEmailVariableType.php
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * The registry is bound as a singleton by Heisenberg's own service
     * provider (`Heisenberg\HeisenbergServiceProvider::registerEngine()`),
     * so resolving it here returns the SAME instance every host provider
     * shares within a request. Registration order is preserved within one
     * boot pass — a second boot that registers under a different key still
     * hits the central duplicate-key guard.
     */
    public function boot(EmailVariableRegistry $variables): void
    {
        // 1) Register every custom formatter TYPE first.
        //    The registry validates `targets()` immediately — a non-empty,
        //    unique list drawn only from `text`, `url`, `email` — so a
        //    typo (`'txt'`) fails at boot, not at render time.
        $variables->registerType(new MoneyEmailVariableType());

        // 2) Register each VARIABLE DEFINITION. The same flat dotted-key
        //    format is used by the editor picker, by sample preview, by
        //    the renderer's fourth argument, by HeisenbergMailable's third
        //    constructor argument, and by the admin batch export's
        //    `recipients[].values` map. There is exactly ONE registry of
        //    keys; a key used in a runtime map MUST be registered here.

        // 2a) Built-in `text` formatter (auto-registered by the registry's
        //     own constructor; the host just defines a variable that
        //     references it).
        $variables->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy', // <-- non-secret, deterministic, used by every author-facing GET.
            group: 'User',
        ));

        // 2b) Built-in `text` formatter, single-segment key. The plan's
        //     own "Target public usage" block uses `unsubscribe_url`
        //     (no dot); the registry's `KEY_PATTERN` accepts both single-
        //     and multi-segment keys, see Tasks 1 compatibility note.
        $variables->register(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            // The bundled `url` formatter targets `['url']` — the
            // interpolated value is substituted raw into URL attributes,
            // and `BlockRenderer::safeUrl()` enforces the scheme policy
            // downstream. A `javascript:` value is rejected at the URL
            // gate, not at the formatter; see Task 2.
            sample: 'https://example.test/unsubscribe/sample',
            group: 'Campaign',
        ));

        // 2c) Host custom `money` formatter with a NON-SCALAR sample.
        //     The registry validates the type at register() time; the
        //     formatter receives the literal Money object both at sample
        //     time (editorMetadata) and at runtime (per-recipient
            //     render). A non-secret sample here is a literal money
            //     value the host is comfortable displaying publicly — the
            //     editor preview shows `NGN 250,000.00`, not a real
            //     recipient's balance.
        $variables->register(new EmailVariableDefinition(
            key: 'account.balance',
            label: 'Account balance',
            type: 'money',
            sample: new \Host\Money(250_000.00, 'NGN'),
            group: 'Account',
            // Formatter-specific options bag. The host's Money formatter
            // reads this; the registry does not interpret it. Heisenberg
            // does the same with its bundled `number` and `date` types
            // (decimals, format).
            options: ['currency' => 'NGN'],
        ));

        // 2d) Built-in `email` formatter. Targets `['email', 'url']` —
        //     suitable for email-specific targets and URL attributes that
        //     build a `mailto:` href. It is intentionally not a text target.
        $variables->register(new EmailVariableDefinition(
            key: 'user.email',
            label: 'Email address',
            type: 'email',
            sample: 'sample@example.test',
            group: 'User',
        ));
    }
}
