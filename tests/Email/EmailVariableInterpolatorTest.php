<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\EmailVariableInterpolator;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailRenderResult;
use Heisenberg\Support\EmailVariableContext;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\EmailVariableResolutionException;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * Wave E5, Task 2 (.hermes/plans/2026-08-25_190059-email-template-variables.md):
 * the context-aware, strict interpolator that resolves registered `{{ dotted.key }}`
 * tokens into a copied email-subject + email-block-tree BEFORE
 * {@see \Heisenberg\Services\BlockRenderer} sanitizes rich text / URL gates.
 *
 * Vertical slices covered:
 *  - Token extraction: only valid `{{ dotted.key }}` tokens are matched; arbitrary
 *    `{{ ... }}` text in non-attribute strings or unsupported attributes (class,
 *    style, supports, etc.) is left alone.
 *  - Resolution: registered definitions and exact context values; explicit `null`
 *    reaches the formatter when the key exists.
 *  - Target compatibility: a formatter whose `targets()` does not include the
 *    target it's substituting into fails aggregated, not silently.
 *  - Sanitization ordering: rich-text replacements are HTML-escaped before the
 *    block renderer sanitizes the authored string; URL replacements are
 *    substituted before `safeUrl()` runs (so a `javascript:` payload is rejected
 *    by the existing gate); ordinary string attributes substitute raw text and
 *    let the existing escaping handle it.
 *  - Immutability: `innerBlocks` is recursively copied; an Eloquent model or a
 *    persisted block tree passed in is never mutated.
 *  - Subject: resolved through the `text` target.
 *  - Aggregated errors: every resolution failure — unknown token, missing value,
 *    formatter failure, target incompatibility — aggregates into ONE exception
 *    carrying keys + safe reasons only (no runtime values, no unsafe nested
 *    exception messages).
 *  - Bytes: a subject + block tree WITHOUT any `{{ ... }}` token round-trips
 *    byte-for-byte.
 *
 * This test exercises the interpolator's CONTRACT only. Wiring it into
 * {@see \Heisenberg\Services\EmailRenderer::render()} is Task 3.
 */
class EmailVariableInterpolatorTest extends TestCase
{
    private function interpolator(): EmailVariableInterpolator
    {
        return $this->app->make(EmailVariableInterpolator::class);
    }

    private function registry(): EmailVariableRegistry
    {
        return $this->app->make(EmailVariableRegistry::class);
    }

    private function blocksRegistry(): BlockRegistryService
    {
        return $this->app->make(BlockRegistryService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    // ---------------------------------------------------------------------
    // Slice 1: plain-text substitution into ordinary string attributes
    // ---------------------------------------------------------------------

    public function test_substitutes_plain_text_token_into_string_attribute(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello {{ user.first_name }}, welcome.'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // The interpolator never mutates the original; only the returned copy is changed.
        $this->assertSame('Hello {{ user.first_name }}, welcome.', $blocks[0]['attributes']['content']);
        $this->assertSame('Hello Ada, welcome.', $result[0]['attributes']['content']);
    }

    // ---------------------------------------------------------------------
    // Slice 2: repeated tokens in the same attribute
    // ---------------------------------------------------------------------

    public function test_repeated_tokens_in_the_same_attribute_all_resolve(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => '{{ user.first_name }}! Hi {{ user.first_name }}!'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Ada! Hi Ada!', $result[0]['attributes']['content']);
    }

    // ---------------------------------------------------------------------
    // Slice 3: rich-text attribute escaping BEFORE sanitization
    // ---------------------------------------------------------------------

    public function test_rich_text_replacement_is_html_escaped_before_existing_sanitization(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        // Rich text content is the `content` attribute on paragraph/heading/quote blocks;
        // their contract `type` is `rich-text`. The interpolator must escape the
        // replacement so a payload of `<script>` becomes `&lt;script&gt;` — the block
        // renderer's sanitizeRichText() sees a plain-text token, NOT raw markup.
        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello {{ user.first_name }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => '<script>alert(1)</script>']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame(
            'Hello &lt;script&gt;alert(1)&lt;/script&gt;',
            $result[0]['attributes']['content'],
        );
    }

    // ---------------------------------------------------------------------
    // Slice 4: URL attribute substitution before safeUrl()
    // ---------------------------------------------------------------------

    public function test_url_attribute_is_substituted_raw_before_safe_url_gate(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.profile_url',
            label: 'Profile URL',
            type: 'url',
            sample: 'https://example.test/u/sample',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/button',
                'attributes' => ['url' => '{{ user.profile_url }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.profile_url' => 'https://example.test/u/ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // The interpolator substitutes the raw formatted URL — BlockRenderer's safeUrl()
        // gate then runs on a real https URL and lets it through. We don't escape here:
        // safeUrl() is the URL gate.
        $this->assertSame('https://example.test/u/ada', $result[0]['attributes']['url']);
    }

    // ---------------------------------------------------------------------
    // Slice 5: javascript: URL is rejected by the existing safeUrl() gate
    // ---------------------------------------------------------------------

    public function test_javascript_url_is_rejected_by_existing_safe_url_gate(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.profile_url',
            label: 'Profile URL',
            type: 'url',
            sample: 'https://example.test/u/sample',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/button',
                'attributes' => ['url' => '{{ user.profile_url }}'],
            ],
        ];

        // A malicious payload survives substitution unchanged (the interpolator
        // substitutes raw text); BlockRenderer::safeUrl() strips it.
        $context = EmailVariableContext::runtime(['user.profile_url' => 'javascript:alert(1)']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // The substituted value is the raw, unescaped payload — the safeUrl() gate
        // downstream is what neutralizes it.
        $this->assertSame('javascript:alert(1)', $result[0]['attributes']['url']);

        // Prove the existing safeUrl() gate strips it when rendering: the rendered
        // HTML for this block must NOT contain `javascript:`.
        $rendered = $this->app->make(\Heisenberg\Services\BlockRenderer::class)
            ->renderBlock($result[0], 'en', 'email');

        $this->assertStringNotContainsString('javascript:', $rendered);
    }

    // ---------------------------------------------------------------------
    // Slice 6: custom Money formatter integration (non-scalar runtime value)
    // ---------------------------------------------------------------------

    public function test_custom_money_formatter_runs_with_non_scalar_runtime_value(): void
    {
        $registry = $this->registry();
        $registry->registerType(new InterpolatorTestMoneyEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'account.balance',
            label: 'Account balance',
            type: 'money',
            sample: InterpolatorTestMoneyStub::ngn(100000),
            options: ['currency' => 'NGN'],
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Balance: {{ account.balance }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['account.balance' => InterpolatorTestMoneyStub::ngn(25000000)]);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Balance: NGN 250,000.00', $result[0]['attributes']['content']);
    }

    // ---------------------------------------------------------------------
    // Slice 7: locale is threaded into the formatter
    // ---------------------------------------------------------------------

    public function test_locale_is_passed_through_to_the_formatter(): void
    {
        $registry = $this->registry();
        $registry->registerType(new InterpolatorTestLocaleAwareEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'user.locale_marker',
            label: 'Locale marker',
            type: 'locale_marker',
            sample: 'sample',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello {{ user.locale_marker }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.locale_marker' => 'world']);

        $resultEn = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
        $resultFr = $this->interpolator()->interpolateBlocks($blocks, $context, 'fr');

        $this->assertSame('Hello [en]world', $resultEn[0]['attributes']['content']);
        $this->assertSame('Hello [fr]world', $resultFr[0]['attributes']['content']);
    }

    public function test_locale_suffixed_attributes_are_interpolated_for_the_requested_locale(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Sample',
        ));

        $blocks = [[
            'name' => 'heisenberg/paragraph',
            'attributes' => [
                'content' => 'Hello {{ user.first_name }}',
                'content_fr' => 'Salut {{ user.first_name }}',
            ],
        ]];

        $result = $this->interpolator()->interpolateBlocks(
            $blocks,
            EmailVariableContext::runtime(['user.first_name' => 'Ada']),
            'fr',
        );

        $this->assertSame('Hello {{ user.first_name }}', $result[0]['attributes']['content']);
        $this->assertSame('Salut Ada', $result[0]['attributes']['content_fr']);
    }

    // ---------------------------------------------------------------------
    // Slice 8: explicit null reaches the formatter when the key exists
    // ---------------------------------------------------------------------

    public function test_explicit_null_reaches_the_formatter_when_the_key_exists(): void
    {
        $registry = $this->registry();
        $registry->registerType(new InterpolatorTestNullReceivingEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'user.middle_name',
            label: 'Middle name',
            type: 'null_receiver',
            sample: 'X',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ user.middle_name }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.middle_name' => null]);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Hi NULL', $result[0]['attributes']['content']);
    }

    // ---------------------------------------------------------------------
    // Slice 9: subject is resolved through the text target
    // ---------------------------------------------------------------------

    public function test_subject_is_resolved_through_the_text_target(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'campaign.name',
            label: 'Campaign name',
            type: 'text',
            sample: 'Sample',
        ));

        $context = EmailVariableContext::runtime(['campaign.name' => 'August Newsletter']);

        $resolved = $this->interpolator()->interpolateSubject('Welcome to {{ campaign.name }}', $context, 'en');

        $this->assertSame('Welcome to August Newsletter', $resolved);
    }

    public function test_subject_keeps_plain_text_replacement_raw(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'campaign.name',
            label: 'Campaign name',
            type: 'text',
            sample: 'Sample',
        ));

        $context = EmailVariableContext::runtime(['campaign.name' => '<unsafe> & "quoted"']);

        $resolved = $this->interpolator()->interpolateSubject('Hi {{ campaign.name }}', $context, 'en');

        // MIME subjects are plain text. HTML consumers such as wrapShell() escape
        // at their own boundary; the interpolator must not inject HTML entities.
        $this->assertSame('Hi <unsafe> & "quoted"', $resolved);
    }

    // ---------------------------------------------------------------------
    // Slice 10: formatter target incompatibility is aggregated
    // ---------------------------------------------------------------------

    public function test_target_incompatibility_is_aggregated_into_a_resolution_exception(): void
    {
        $registry = $this->registry();
        InterpolatorTestEmailOnlyEmailVariableType::$formatCalls = 0;
        $registry->registerType(new InterpolatorTestEmailOnlyEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'user.email_address',
            label: 'Email address',
            type: 'email_only',
            sample: 'sample@example.test',
        ));

        // The block's `content` attribute is a string (text target), not a URL — a
        // type whose only target is `email` cannot substitute here.
        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Email: {{ user.email_address }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.email_address' => 'ada@example.test']);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected EmailVariableResolutionException for an incompatible formatter target.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame('user.email_address', $e->getKey());
            $this->assertStringContainsString('incompatible', $e->getReason());
            $this->assertStringNotContainsString('ada@example.test', $e->getMessage());
            $this->assertSame(0, InterpolatorTestEmailOnlyEmailVariableType::$formatCalls);
        }
    }

    public function test_email_formatter_is_compatible_with_mailto_url_attributes(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.email',
            label: 'Email',
            type: 'email',
            sample: 'sample@example.test',
        ));

        $blocks = [[
            'name' => 'heisenberg/button',
            'attributes' => [
                'text' => 'Email us',
                'url' => 'mailto:{{ user.email }}',
            ],
        ]];

        $result = $this->interpolator()->interpolateBlocks(
            $blocks,
            EmailVariableContext::runtime(['user.email' => 'ada@example.test']),
            'en',
        );

        $this->assertSame('mailto:ada@example.test', $result[0]['attributes']['url']);
    }

    // ---------------------------------------------------------------------
    // Slice 11: aggregated errors — unknown token, missing value, formatter failure
    // ---------------------------------------------------------------------

    public function test_unknown_token_is_aggregated_without_exposing_runtime_values(): void
    {
        // No definition for `user.unknown` is registered — every token referencing
        // it should be aggregated under one exception carrying the key only.
        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ user.unknown }}'],
            ],
        ];

        $context = EmailVariableContext::runtime([]);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected EmailVariableResolutionException for an unknown token.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertStringContainsString('user.unknown', $e->getMessage());
            $this->assertStringNotContainsString('runtime', $e->getMessage());
        }
    }

    public function test_missing_runtime_value_is_aggregated(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Sample',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ user.first_name }}'],
            ],
        ];

        $context = EmailVariableContext::runtime([]); // key registered but not in the runtime map

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected EmailVariableResolutionException for a missing runtime value.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertStringContainsString('user.first_name', $e->getMessage());
        }
    }

    public function test_multiple_failures_aggregate_into_one_exception_with_keys_and_reasons(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Sample',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => [
                    'content' => 'Hi {{ user.first_name }} — see {{ user.unknown }} and {{ user.missing }}',
                ],
            ],
        ];

        $context = EmailVariableContext::runtime([]);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected an aggregated exception.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame(['user.first_name', 'user.unknown', 'user.missing'], $e->getKeys());
            $failures = $e->getFailures();
            $this->assertCount(3, $failures);
            // No sample / runtime values leak into the aggregated message.
            $this->assertStringNotContainsString('Sample', $e->getMessage());
            $this->assertStringNotContainsString('runtime', $e->getMessage());
        }
    }

    public function test_get_keys_returns_distinct_keys_for_repeated_failures(): void
    {
        $blocks = [[
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => '{{ user.unknown }} {{ user.unknown }}'],
        ]];

        try {
            $this->interpolator()->interpolateBlocks($blocks, EmailVariableContext::runtime([]), 'en');
            $this->fail('Expected repeated unknown tokens to fail.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame(['user.unknown'], $e->getKeys());
            $this->assertCount(2, $e->getFailures());
        }
    }

    public function test_formatter_failure_is_aggregated_without_unsafe_nested_message(): void
    {
        $registry = $this->registry();
        $registry->registerType(new InterpolatorTestAlwaysThrowsEmailVariableType());

        $registry->register(new EmailVariableDefinition(
            key: 'user.secret',
            label: 'Secret',
            type: 'always_throws',
            sample: 'x',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello {{ user.secret }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.secret' => 'recipient-secret-payload']);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected an aggregated formatter failure.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame('user.secret', $e->getKey());
            // The runtime value must NEVER reach the message; the wrapped exception's
            // message must be replaced with a safe reason.
            $this->assertStringNotContainsString('recipient-secret-payload', $e->getMessage());
            $this->assertStringContainsString('formatter failed', $e->getReason());
        }
    }

    // ---------------------------------------------------------------------
    // Slice 12: tokens outside subject/string attributes are ignored
    // ---------------------------------------------------------------------

    public function test_tokens_outside_string_attributes_are_ignored(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        // A block's `class` and `supports` slots must NOT be interpolated even
        // though the input block contains tokens there — only the string-typed
        // attributes (`content` etc.) participate.
        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => [
                    'content' => 'Hi {{ user.first_name }}',
                    'extraClasses' => ['class-token-{{ user.first_name }}'],
                ],
                'supports' => [
                    'align' => 'left-{{ user.first_name }}',
                ],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Hi Ada', $result[0]['attributes']['content']);
        // Class tokens are never interpolated; only `{{ ... }}` strings that happen to
        // appear inside a token-aware position are touched.
        $this->assertSame(
            ['class-token-{{ user.first_name }}'],
            $result[0]['attributes']['extraClasses'],
        );
    }

    public function test_arbitrary_braces_in_unsupported_string_attributes_are_left_alone(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'user.profile_path',
            label: 'Profile path',
            type: 'url',
            sample: 'https://example.test/u/sample',
        ));

        // The `anchor` attribute on `heisenberg/button` is a plain string used as a
        // CSS identifier (not user-facing content) — the interpolator must NOT walk
        // into it. The URL attribute substitutes raw; rich-text substitutes escaped.
        $blocks = [
            [
                'name' => 'heisenberg/button',
                'attributes' => [
                    'text' => 'Hi {{ user.first_name }}',
                    'url' => 'https://example.test/{{ user.profile_path }}', // URL — substituted raw
                    'anchor' => 'greet-{{ user.first_name }}', // NOT substituted
                ],
            ],
        ];

        $context = EmailVariableContext::runtime([
            'user.first_name' => 'Ada',
            'user.profile_path' => 'profile/Ada',
        ]);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Hi Ada', $result[0]['attributes']['text']);
        $this->assertSame('https://example.test/profile/Ada', $result[0]['attributes']['url']);
        $this->assertSame('greet-{{ user.first_name }}', $result[0]['attributes']['anchor']);
    }

    public function test_translatable_plain_string_attributes_are_interpolated_without_html_encoding(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'image.alt',
            label: 'Image alt',
            type: 'text',
            sample: 'Sample alt',
        ));

        $blocks = [[
            'name' => 'heisenberg/image',
            'attributes' => ['alt' => 'Portrait of {{ image.alt }}'],
        ]];

        $result = $this->interpolator()->interpolateBlocks(
            $blocks,
            EmailVariableContext::runtime(['image.alt' => '<Ada & Co>']),
            'en',
        );

        $this->assertSame('Portrait of <Ada & Co>', $result[0]['attributes']['alt']);
    }

    // ---------------------------------------------------------------------
    // Slice 13: byte-for-byte preservation for no-variable content
    // ---------------------------------------------------------------------

    public function test_no_variable_content_round_trips_byte_for_byte(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => [
                    'content' => 'Static content with no tokens.',
                    'extraClasses' => ['literal'],
                ],
                'supports' => ['align' => 'left'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Static content with no tokens.', $result[0]['attributes']['content']);
        $this->assertSame(['literal'], $result[0]['attributes']['extraClasses']);
        $this->assertSame(['align' => 'left'], $result[0]['supports']);
    }

    // ---------------------------------------------------------------------
    // Slice 14: innerBlocks recursively copied, never mutates Eloquent models
    // ---------------------------------------------------------------------

    public function test_inner_blocks_are_recursively_copied_without_mutating_inputs(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $inner = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello {{ user.first_name }}'],
            ],
        ];

        $blocks = [
            [
                'name' => 'heisenberg/group',
                'attributes' => [],
                'innerBlocks' => $inner,
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // Recursive copy carries the resolved value through.
        $this->assertSame(
            'Hello Ada',
            $result[0]['innerBlocks'][0]['attributes']['content'],
        );

        // Original inputs (a stand-in for the persisted model / payload) are NOT mutated.
        $this->assertSame(
            'Hello {{ user.first_name }}',
            $blocks[0]['innerBlocks'][0]['attributes']['content'],
        );
        $this->assertSame(
            'Hello {{ user.first_name }}',
            $inner[0]['attributes']['content'],
        );
    }

    public function test_interpolate_blocks_does_not_mutate_input(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ user.first_name }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // The original input carries its tokens — Heisenberg must never write
        // resolved values back into the block tree the caller holds.
        $this->assertSame('Hi {{ user.first_name }}', $blocks[0]['attributes']['content']);
    }

    // ---------------------------------------------------------------------
    // Slice 15: a `EmailVariableContext::samples(...)` context still works
    // ---------------------------------------------------------------------

    public function test_sample_context_resolves_tokens_using_registered_samples(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ user.first_name }}'],
            ],
        ];

        $context = EmailVariableContext::samples($registry);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Hi Tedy', $result[0]['attributes']['content']);
    }

    // ---------------------------------------------------------------------
    // Slice 16: only valid `{{ dotted.key }}` tokens are recognized
    // ---------------------------------------------------------------------

    public function test_only_valid_dotted_key_tokens_are_extracted(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => [
                    'content' => 'Hi {{ user.first_name }} and {{ user.first_name}} and {{user.first_name }} and {{user.first_name}}',
                ],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame(
            'Hi Ada and Ada and Ada and Ada',
            $result[0]['attributes']['content'],
        );
    }

    public function test_invalid_token_shapes_are_left_untouched(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        // Surrounding whitespace is optional and may be repeated. Invalid identifiers
        // and unclosed braces still pass through unchanged.
        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => [
                    'content' => "Hi {{ user.first_name }} and {{  user.first_name  }} and {{\tuser.first_name\t}} and {{ user.}} and {{ user.first_name }",
                ],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame(
            'Hi Ada and Ada and Ada and {{ user.}} and {{ user.first_name }',
            $result[0]['attributes']['content'],
        );
    }

    // ---------------------------------------------------------------------
    // Sanity: integration with EmailRenderer — Task 2 deliberately does NOT
    // wire the interpolator into EmailRenderer::render(); that's Task 3. We
    // just prove here that the interpolator's contract produces an output the
    // block renderer is happy to consume.
    // ---------------------------------------------------------------------

    public function test_interpolated_blocks_render_through_block_renderer_clean(): void
    {
        $registry = $this->registry();
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ user.first_name }}'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => '<bad>Ada</bad>']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $rendered = $this->app->make(\Heisenberg\Services\BlockRenderer::class)
            ->renderBlock($result[0], 'en', 'email');

        $this->assertStringContainsString('&lt;bad&gt;Ada&lt;/bad&gt;', $rendered);
        $this->assertStringNotContainsString('<bad>', $rendered);
    }

    // ---------------------------------------------------------------------
    // Sanity: EmailRenderResult is untouched by Task 2
    // ---------------------------------------------------------------------

    public function test_email_render_result_shape_is_unchanged(): void
    {
        $reflection = new \ReflectionClass(EmailRenderResult::class);
        $this->assertSame(
            ['html', 'text', 'subject', 'embeds', 'sizeBytes'],
            array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $reflection->getProperties()),
        );
    }
}

// ---------------------------------------------------------------------------
// Test-only helpers — kept alongside the test file because they mirror the
// helpers in EmailVariableRegistryTest but with a focused, interpolator-only
// contract. Each one exercises a specific vertical slice above.
// ---------------------------------------------------------------------------

/** Always returns `[text]`. */
final class InterpolatorTestMoneyEmailVariableType implements EmailVariableType
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
        if (! $value instanceof InterpolatorTestMoneyStub) {
            throw new \InvalidArgumentException('money formatter expects an InterpolatorTestMoneyStub value');
        }

        $currency = (string) ($definition->options['currency'] ?? $value->currency);

        return sprintf('%s %s', $currency, number_format($value->amount / 100, 2, '.', ','));
    }
}

final class InterpolatorTestMoneyStub
{
    public function __construct(public readonly int $amount, public readonly string $currency)
    {
    }

    public static function ngn(int $minorUnits): self
    {
        return new self($minorUnits, 'NGN');
    }
}

/** Decorates the formatted string with the locale for the locale-passing slice. */
final class InterpolatorTestLocaleAwareEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'locale_marker';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        return '[' . $locale . ']' . (string) $value;
    }
}

/** Accepts explicit null and renders the literal string "NULL". */
final class InterpolatorTestNullReceivingEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'null_receiver';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return (string) $value;
    }
}

/** Target list is `['email']` only — incompatible with the `text` substitution target. */
final class InterpolatorTestEmailOnlyEmailVariableType implements EmailVariableType
{
    public static int $formatCalls = 0;

    public function key(): string
    {
        return 'email_only';
    }

    public function targets(): array
    {
        return ['email'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        self::$formatCalls++;

        return (string) $value;
    }
}

/** Always throws — used to prove the interpolator never leaks the wrapped exception's message. */
final class InterpolatorTestAlwaysThrowsEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'always_throws';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        throw new \RuntimeException('formatter-internal-secret: ' . var_export($value, true));
    }
}
