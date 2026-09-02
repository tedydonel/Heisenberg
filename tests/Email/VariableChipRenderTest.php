<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\EmailVariableInterpolator;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableContext;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\EmailVariableResolutionException;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * Round-trip test for the variable-token chip — the inline atom
 * `<span class="hb-var-token" contenteditable="false" data-hb-var-key="…">label</span>`
 * the Variables sidebar (panel-variables.blade.php) drags into the canvas. The interpolator
 * resolves it through the same registry + context + formatter pipeline `{{ key }}` text tokens
 * use, with the same fail-closed posture and the same sanitization ordering.
 *
 * Slice coverage:
 *  - Basic chip substitution: a chip is replaced with the formatted value, HTML-escaped for
 *    the rich-text attribute {@see \Heisenberg\Services\BlockRenderer::sanitizeRichText()}
 *    has not yet run on.
 *  - Mixed content: a chip alongside `{{ key }}` text tokens in the same attribute resolves
 *    both correctly; order is chip-first (a chip is a complete <span> element so it is consumed
 *    before the token regex runs over the residue).
 *  - Legacy backward compatibility: an email authored with raw `{{ key }}` text (no chip)
 *    still resolves correctly.
 *  - Unknown key on a chip: aggregated as REASON_UNKNOWN_TOKEN, identical posture to the
 *    text-token path. The interpolator never leaves a chip partially substituted.
 *  - Missing value on a chip: aggregated as REASON_MISSING_VALUE.
 *  - Target incompatibility on a chip: a formatter whose `targets()` does not include `text`
 *    fails with REASON_INCOMPATIBLE_TARGET when dropped into a rich-text attribute as a chip.
 *  - Bytes: an attribute that contains neither `{{ key }}` text nor `hb-var-token` chips
 *    short-circuits (returns the input untouched).
 *  - The chip's outer HTML is consumed wholesale by the substitution — the surrounding rich-text
 *    HTML around it (paragraph wrappers, other inline elements, plain text) is preserved.
 */
class VariableChipRenderTest extends TestCase
{
    private function interpolator(): EmailVariableInterpolator
    {
        return $this->app->make(EmailVariableInterpolator::class);
    }

    private function registry(): EmailVariableRegistry
    {
        return $this->app->make(EmailVariableRegistry::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    public function test_chip_in_rich_text_attribute_resolves_to_formatted_value(): void
    {
        $this->registry()->register(new EmailVariableDefinition(
            key: 'subscriber.first_name',
            label: 'Subscriber first name',
            type: 'text',
            sample: 'Tedy',
        ));

        $chip = '<span class="hb-var-token" contenteditable="false" '
            . 'data-hb-var-key="subscriber.first_name" data-hb-var-type="text" '
            . 'data-hb-var-sample="Tedy" '
            . 'title="subscriber.first_name">subscriber.first_name</span>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi ' . $chip . ', welcome.'],
            ],
        ];

        $context = EmailVariableContext::runtime(['subscriber.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // The chip is replaced wholesale with the formatted value. Surrounding text is intact.
        $this->assertSame('Hi Ada, welcome.', $result[0]['attributes']['content']);
    }

    public function test_chip_label_containing_html_metacharacters_does_not_break_substitution(): void
    {
        $this->registry()->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
        ));

        // A formatter that produces a payload containing `<` and `>` — the chip's formatted
        // replacement must be HTML-escaped so sanitizeRichText() sees plain text, not raw markup.
        $chip = '<span class="hb-var-token" data-hb-var-key="user.first_name" '
            . 'title="user.first_name">user.first_name</span>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello ' . $chip . '.'],
            ],
        ];

        $context = EmailVariableContext::runtime(['user.first_name' => '<script>alert(1)</script>']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result[0]['attributes']['content']);
        $this->assertStringNotContainsString('<script>', $result[0]['attributes']['content']);
        $this->assertStringNotContainsString('hb-var-token', $result[0]['attributes']['content']);
    }

    public function test_chip_and_text_token_in_same_attribute_both_resolve(): void
    {
        $this->registry()->register(new EmailVariableDefinition(
            key: 'subscriber.first_name',
            label: 'Subscriber first name',
            type: 'text',
            sample: 'Tedy',
        ));
        $this->registry()->register(new EmailVariableDefinition(
            key: 'campaign.name',
            label: 'Campaign name',
            type: 'text',
            sample: 'Spring launch',
        ));

        $chip = '<span class="hb-var-token" data-hb-var-key="subscriber.first_name" '
            . 'title="subscriber.first_name">subscriber.first_name</span>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => [
                    'content' => 'Hi ' . $chip . ', welcome to {{ campaign.name }}.',
                ],
            ],
        ];

        $context = EmailVariableContext::runtime([
            'subscriber.first_name' => 'Ada',
            'campaign.name' => 'Spring launch 2026',
        ]);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        // Both substitutions happened; the chip is gone and the token is gone.
        $this->assertSame('Hi Ada, welcome to Spring launch 2026.', $result[0]['attributes']['content']);
    }

    public function test_legacy_text_token_still_resolves_alongside_chip(): void
    {
        // An email authored before the chip UI existed uses raw `{{ key }}` text. Backward
        // compatibility: the chip substitution path runs first, the token path runs over the
        // residue, and the legacy token is still resolved correctly.
        $this->registry()->register(new EmailVariableDefinition(
            key: 'subscriber.first_name',
            label: 'Subscriber first name',
            type: 'text',
            sample: 'Tedy',
        ));

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hi {{ subscriber.first_name }}, welcome.'],
            ],
        ];

        $context = EmailVariableContext::runtime(['subscriber.first_name' => 'Ada']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame('Hi Ada, welcome.', $result[0]['attributes']['content']);
    }

    public function test_unknown_chip_key_throws_aggregated_resolution_exception(): void
    {
        $chip = '<span class="hb-var-token" data-hb-var-key="not.registered" '
            . 'title="not.registered">not.registered</span>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello ' . $chip],
            ],
        ];

        $context = EmailVariableContext::runtime([]);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected EmailVariableResolutionException for unknown chip key.');
        } catch (EmailVariableResolutionException $e) {
            $failures = $e->getFailures();
            $this->assertCount(1, $failures);
            $this->assertSame('not.registered', $failures[0]['key']);
            $this->assertSame(EmailVariableResolutionException::REASON_UNKNOWN_TOKEN, $failures[0]['reason']);
        }

        // The original block tree was never mutated.
        $this->assertStringContainsString('hb-var-token', $blocks[0]['attributes']['content']);
    }

    public function test_missing_runtime_value_for_chip_throws_aggregated_resolution_exception(): void
    {
        $this->registry()->register(new EmailVariableDefinition(
            key: 'subscriber.email',
            label: 'Subscriber email',
            type: 'email',
            sample: 'sample@example.test',
        ));

        $chip = '<span class="hb-var-token" data-hb-var-key="subscriber.email" '
            . 'title="subscriber.email">subscriber.email</span>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Hello ' . $chip],
            ],
        ];

        // Empty runtime context — no value for the registered key.
        $context = EmailVariableContext::runtime([]);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected EmailVariableResolutionException for missing runtime value.');
        } catch (EmailVariableResolutionException $e) {
            $failures = $e->getFailures();
            $this->assertCount(1, $failures);
            $this->assertSame('subscriber.email', $failures[0]['key']);
            $this->assertSame(EmailVariableResolutionException::REASON_MISSING_VALUE, $failures[0]['reason']);
        }
    }

    public function test_incompatible_target_on_chip_throws_aggregated_resolution_exception(): void
    {
        // A `url`-only formatter dropped into a rich-text attribute as a chip — the formatter's
        // `targets()` does NOT include `text`, so it must fail with INCOMPATIBLE_TARGET.
        $this->registry()->register(new EmailVariableDefinition(
            key: 'campaign.landing_url',
            label: 'Landing URL',
            type: 'url',
            sample: 'https://example.test/landing',
        ));

        $chip = '<span class="hb-var-token" data-hb-var-key="campaign.landing_url" '
            . 'title="campaign.landing_url">campaign.landing_url</span>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => 'Open: ' . $chip],
            ],
        ];

        $context = EmailVariableContext::runtime(['campaign.landing_url' => 'https://example.test/x']);

        try {
            $this->interpolator()->interpolateBlocks($blocks, $context, 'en');
            $this->fail('Expected EmailVariableResolutionException for incompatible target.');
        } catch (EmailVariableResolutionException $e) {
            $failures = $e->getFailures();
            $this->assertCount(1, $failures);
            $this->assertSame('campaign.landing_url', $failures[0]['key']);
            $this->assertSame(EmailVariableResolutionException::REASON_INCOMPATIBLE_TARGET, $failures[0]['reason']);
        }
    }

    public function test_attribute_without_chip_or_token_is_passed_through_unchanged(): void
    {
        $this->registry()->register(new EmailVariableDefinition(
            key: 'subscriber.email',
            label: 'Subscriber email',
            type: 'email',
            sample: 'sample@example.test',
        ));

        $original = '<p>Plain paragraph with no variables.</p>';
        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => $original],
            ],
        ];

        $context = EmailVariableContext::runtime([]);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $this->assertSame($original, $result[0]['attributes']['content']);
    }

    public function test_surrounding_html_around_chip_is_preserved(): void
    {
        $this->registry()->register(new EmailVariableDefinition(
            key: 'campaign.name',
            label: 'Campaign name',
            type: 'text',
            sample: 'Spring launch',
        ));

        // The chip sits in the middle of a richer HTML fragment; the surrounding markup
        // (other inline elements, plain text, attributes) survives intact.
        $chip = '<span class="hb-var-token" data-hb-var-key="campaign.name" '
            . 'title="campaign.name">campaign.name</span>';
        $original = '<p>Before <strong>bold</strong> ' . $chip . ' <em>after</em></p>';

        $blocks = [
            [
                'name' => 'heisenberg/paragraph',
                'attributes' => ['content' => $original],
            ],
        ];

        $context = EmailVariableContext::runtime(['campaign.name' => 'Spring launch 2026']);

        $result = $this->interpolator()->interpolateBlocks($blocks, $context, 'en');

        $resolved = $result[0]['attributes']['content'];
        $this->assertStringStartsWith('<p>Before <strong>bold</strong> ', $resolved);
        $this->assertStringContainsString('<em>after</em></p>', $resolved);
        $this->assertStringContainsString('Spring launch 2026', $resolved);
        $this->assertStringNotContainsString('hb-var-token', $resolved);
        $this->assertStringNotContainsString('<span', $resolved);
    }
}
