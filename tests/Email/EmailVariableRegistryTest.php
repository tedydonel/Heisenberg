<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Mail\VariableTypes\BooleanEmailVariableType;
use Heisenberg\Mail\VariableTypes\DateEmailVariableType;
use Heisenberg\Mail\VariableTypes\EmailAddressEmailVariableType;
use Heisenberg\Mail\VariableTypes\NumberEmailVariableType;
use Heisenberg\Mail\VariableTypes\TextEmailVariableType;
use Heisenberg\Mail\VariableTypes\UrlEmailVariableType;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableContext;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;

/**
 * Wave E5, Task 1 (.hermes/plans/2026-08-25_190059-email-template-variables.md):
 * the host-extensible variable registry and formatter contract.
 *
 * Vertical slices covered:
 *  - Built-in formatter type keys exist once and are auto-registered when the
 *    singleton is resolved (text, url, email, number, boolean, date).
 *  - A host may register a custom formatter type plus a variable definition
 *    that uses it (Money-style object type with options).
 *  - Definitions serialize to editor-safe metadata WITHOUT formatter objects
 *    or runtime values — only the static key/label/group/description/type/
 *    targets/sample-format-path that the email-only insertion UI (Task 5) will
 *    later consume.
 *  - Duplicate keys and duplicate type keys fail deterministically rather
 *    than silently overriding.
 *  - Invalid keys (empty / non-dotted / reserved BlockRenderer roots) and
 *    unknown formatter types fail at registration time.
 *  - `EmailVariableContext::samples(...)` exposes ONLY registered samples,
 *    keyed by registered keys; values are kept opaque (objects allowed for
 *    custom formatters), no model lookup happens here.
 *  - A custom formatter may accept a non-scalar sample (Money-style) and
 *    produce a string at format() time.
 *
 * This test exercises ONLY the registry surface — interpolation,
 * `EmailRenderer` integration, the editor picker, batch export, SMTP, and
 * HeisenbergMailable's optional values argument are Tasks 2+ and are
 * intentionally untouched.
 */
class EmailVariableRegistryTest extends TestCase
{
    private function registry(): EmailVariableRegistry
    {
        return app(EmailVariableRegistry::class);
    }

    public function test_built_in_formatter_types_are_registered_once(): void
    {
        $registry = $this->registry();

        // Every built-in type surfaces its key + at least one target, and each
        // type key resolves to exactly one implementation.
        $this->assertSame('text', $registry->type('text')->key());
        $this->assertSame('url', $registry->type('url')->key());
        $this->assertSame('email', $registry->type('email')->key());
        $this->assertSame('number', $registry->type('number')->key());
        $this->assertSame('boolean', $registry->type('boolean')->key());
        $this->assertSame('date', $registry->type('date')->key());

        // The exact instances shipped by the registry are the built-in classes.
        $this->assertInstanceOf(TextEmailVariableType::class, $registry->type('text'));
        $this->assertInstanceOf(UrlEmailVariableType::class, $registry->type('url'));
        $this->assertInstanceOf(EmailAddressEmailVariableType::class, $registry->type('email'));
        $this->assertInstanceOf(NumberEmailVariableType::class, $registry->type('number'));
        $this->assertInstanceOf(BooleanEmailVariableType::class, $registry->type('boolean'));
        $this->assertInstanceOf(DateEmailVariableType::class, $registry->type('date'));
    }

    public function test_built_in_formatter_targets_are_what_the_plan_declares(): void
    {
        $registry = $this->registry();

        $this->assertSame(['text'], $registry->type('text')->targets());
        $this->assertSame(['url'], $registry->type('url')->targets());
        $this->assertSame(['email', 'url'], $registry->type('email')->targets());
        $this->assertSame(['text'], $registry->type('number')->targets());
        $this->assertSame(['text'], $registry->type('boolean')->targets());
        $this->assertSame(['text'], $registry->type('date')->targets());
    }

    public function test_formatter_targets_are_validated_centrally(): void
    {
        foreach ([[], ['txt'], ['text' => 'text'], ['text', 'text']] as $index => $targets) {
            $registry = new EmailVariableRegistry();
            $type = new class ($index, $targets) implements EmailVariableType
            {
                /** @param array<int|string, string> $targets */
                public function __construct(
                    private readonly int $index,
                    private readonly array $targets,
                ) {
                }

                public function key(): string
                {
                    return 'invalid_targets_' . $this->index;
                }

                public function targets(): array
                {
                    return $this->targets;
                }

                public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
                {
                    return (string) $value;
                }
            };

            try {
                $registry->registerType($type);
                $this->fail('Expected invalid formatter targets to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($type->key(), $e->getMessage());
            }
        }
    }

    public function test_a_host_can_register_a_custom_formatter_type_and_a_variable_using_it(): void
    {
        $registry = $this->registry();

        $registry->registerType(new MoneyEmailVariableType());

        $definition = new EmailVariableDefinition(
            key: 'account.balance',
            label: 'Account balance',
            type: 'money',
            sample: MoneyStub::ngn(25000000),
            group: 'Account',
            options: ['currency' => 'NGN'],
        );

        $registry->register($definition);

        $this->assertSame($definition, $registry->definition('account.balance'));

        $type = $registry->type('money');
        $this->assertInstanceOf(MoneyEmailVariableType::class, $type);
        $this->assertSame(['text'], $type->targets());

        $formatted = $registry->format($definition, MoneyStub::ngn(25000000), 'en');
        $this->assertSame('NGN 250,000.00', $formatted);
    }

    public function test_definitions_serialize_to_editor_safe_metadata_without_formatter_objects_or_runtime_values(): void
    {
        $registry = $this->registry();
        $registry->registerType(new MoneyEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
            group: 'User',
            description: 'Recipient first name',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'account.balance',
            label: 'Account balance',
            type: 'money',
            sample: MoneyStub::ngn(100000),
            group: 'Account',
            options: ['currency' => 'NGN'],
        ));

        $meta = $registry->editorMetadata();

        $byKey = [];
        foreach ($meta as $row) {
            $byKey[$row['key']] = $row;
        }

        // The metadata is the union of static fields only — no formatter
        // objects, no closures, no sample values leaking into the editor view.
        $this->assertArrayHasKey('user.first_name', $byKey);
        $this->assertArrayHasKey('account.balance', $byKey);

        $row = $byKey['user.first_name'];
        $this->assertSame('user.first_name', $row['key']);
        $this->assertSame('First name', $row['label']);
        $this->assertSame('text', $row['type']);
        $this->assertSame(['text'], $row['targets']);
        $this->assertSame('User', $row['group']);
        $this->assertSame('Recipient first name', $row['description']);
        $this->assertArrayNotHasKey('definition', $row);
        $this->assertArrayNotHasKey('formatter', $row);

        $moneyRow = $byKey['account.balance'];
        $this->assertSame(['currency' => 'NGN'], $moneyRow['options']);
        $this->assertSame('NGN 1,000.00', $moneyRow['sample']);
        $this->assertArrayNotHasKey('formatter', $moneyRow);
    }

    public function test_duplicate_definition_keys_fail_deterministically(): void
    {
        $registry = $this->registry();

        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user.first_name');

        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'Given name',
            type: 'text',
            sample: 'Ada',
        ));
    }

    public function test_duplicate_formatter_type_keys_fail_deterministically(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('text');

        // Built-in `text` is already registered — re-registering is a host bug.
        $registry->registerType(new TextEmailVariableType());
    }

    public function test_invalid_keys_fail_at_registration_time(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);

        // Empty key — must be rejected before touching the registry.
        $registry->register(new EmailVariableDefinition(
            key: '',
            label: 'Bad',
            type: 'text',
            sample: 'x',
        ));
    }

    public function test_empty_definition_labels_fail_at_registration_time(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('label');

        $this->registry()->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: '   ',
            type: 'text',
            sample: 'Tedy',
        ));
    }

    public function test_reserved_blockrenderer_keys_cannot_be_registered(): void
    {
        $registry = $this->registry();

        $reserved = ['id', 'name', 'attributes', 'attributes.url', 'supports', 'supports.align', 'lang', 'lang.email.subject'];
        foreach ($reserved as $key) {
            try {
                $registry->register(new EmailVariableDefinition(
                    key: $key,
                    label: 'Reserved',
                    type: 'text',
                    sample: 'x',
                ));
                $this->fail("Expected registration of reserved key '$key' to fail.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function test_keys_with_whitespace_or_invalid_characters_fail(): void
    {
        $registry = $this->registry();

        foreach (['user first_name', 'user.first ', ' user.first_name', 'user..first_name', '1user.first'] as $bad) {
            try {
                $registry->register(new EmailVariableDefinition(
                    key: $bad,
                    label: 'Bad',
                    type: 'text',
                    sample: 'x',
                ));
                $this->fail("Expected registration of bad key '$bad' to fail.");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_unknown_formatter_types_fare_when_a_definition_uses_them(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not-a-real-type');

        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'not-a-real-type',
            sample: 'x',
        ));
    }

    public function test_sample_context_contains_only_registered_samples(): void
    {
        $registry = $this->registry();

        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            sample: 'https://example.test/unsubscribe/sample',
        ));

        $context = EmailVariableContext::samples($registry);

        $this->assertSame('Tedy', $context->get('user.first_name'));
        $this->assertSame('https://example.test/unsubscribe/sample', $context->get('unsubscribe_url'));
        $this->assertNull($context->get('does.not.exist'));
        $this->assertSame('sample', $context->mode());
        $this->assertFalse($context->isRuntime());
    }

    public function test_sample_context_supports_non_scalar_samples_for_custom_formatters(): void
    {
        $registry = $this->registry();
        $registry->registerType(new MoneyEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'account.balance',
            label: 'Account balance',
            type: 'money',
            sample: MoneyStub::ngn(12345600),
            options: ['currency' => 'NGN'],
        ));

        $context = EmailVariableContext::samples($registry);

        $sample = $context->get('account.balance');
        $this->assertInstanceOf(MoneyStub::class, $sample);

        // The context holds the value object — formatting is a separate step,
        // owned by the formatter — but `has()` must still resolve true.
        $this->assertTrue($context->has('account.balance'));

        $rendered = $registry->format(
            $registry->definition('account.balance'),
            $sample,
            'en'
        );
        $this->assertSame('NGN 123,456.00', $rendered);
    }

    public function test_runtime_context_preserves_the_exact_flat_map_and_distinguishes_null_from_missing(): void
    {
        $values = [
            0 => 'positional value retained verbatim',
            'user.first_name' => 'Tedy',
            'user.optional_name' => null,
        ];

        $context = EmailVariableContext::runtime($values);

        $this->assertSame($values, $context->all());
        $this->assertTrue($context->has('user.optional_name'));
        $this->assertNull($context->get('user.optional_name'));
        $this->assertFalse($context->has('user.missing'));
    }

    public function test_built_in_formatter_errors_do_not_include_runtime_values(): void
    {
        $definition = new EmailVariableDefinition(
            key: 'user.joined_at',
            label: 'Joined at',
            type: 'date',
            sample: '2026-08-26',
        );

        try {
            $this->registry()->format($definition, 'recipient-secret-date', 'en');
            $this->fail('Expected an invalid date to fail formatting.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('user.joined_at', $e->getMessage());
            $this->assertStringNotContainsString('recipient-secret-date', $e->getMessage());
        }
    }
}

/**
 * Test-only custom formatter: formats a {@see MoneyStub} value object with
 * an `options['currency']` label. Lives alongside the test because the
 * registry's "host can register a custom formatter" promise is a host seam,
 * not a built-in package feature.
 */
final class MoneyEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'money';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (! $value instanceof MoneyStub) {
            throw new \InvalidArgumentException('money formatter expects a MoneyStub value');
        }

        $currency = (string) ($definition->options['currency'] ?? $value->currency);

        return sprintf('%s %s', $currency, number_format($value->amount / 100, 2, '.', ','));
    }
}

/**
 * Test-only value object — mirrors the Money-style "non-scalar sample"
 * the plan calls out as a host extension point.
 */
final class MoneyStub
{
    public function __construct(public readonly int $amount, public readonly string $currency)
    {
    }

    public static function ngn(int $minorUnits): self
    {
        return new self($minorUnits, 'NGN');
    }
}