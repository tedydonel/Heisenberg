<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Support;

use Heisenberg\Support\LocalizedAttributes;
use PHPUnit\Framework\TestCase;

/**
 * Pure array-logic tests for {@see LocalizedAttributes} (docs/content-translation.md §0) — no app
 * boot needed, same posture {@see \Heisenberg\Tests\M0\ConfigMergeTest} takes for its own
 * dependency-free Support class.
 */
class LocalizedAttributesTest extends TestCase
{
    // ── read() ────────────────────────────────────────────────────────────

    public function test_read_prefers_the_locale_suffixed_variant(): void
    {
        $attributes = ['content' => 'Hello', 'content_fr' => 'Bonjour'];

        $this->assertSame('Bonjour', LocalizedAttributes::read($attributes, 'content', 'fr'));
    }

    public function test_read_falls_back_to_the_bare_key_when_no_suffixed_variant_exists(): void
    {
        $attributes = ['content' => 'Hello'];

        $this->assertSame('Hello', LocalizedAttributes::read($attributes, 'content', 'fr'));
    }

    public function test_read_returns_null_when_neither_variant_exists(): void
    {
        $this->assertNull(LocalizedAttributes::read([], 'content', 'fr'));
    }

    public function test_read_distinguishes_an_explicit_empty_string_from_absence(): void
    {
        // content_fr explicitly set to '' must win over the bare key, not fall through to it.
        $attributes = ['content' => 'Hello', 'content_fr' => ''];

        $this->assertSame('', LocalizedAttributes::read($attributes, 'content', 'fr'));
    }

    // ── write() ───────────────────────────────────────────────────────────

    public function test_write_always_writes_the_suffixed_variant(): void
    {
        $result = LocalizedAttributes::write(['content' => 'Hello'], 'content', 'fr', 'Bonjour');

        $this->assertSame('Hello', $result['content']);
        $this->assertSame('Bonjour', $result['content_fr']);
    }

    public function test_write_does_not_mutate_the_input_array(): void
    {
        $original = ['content' => 'Hello'];

        LocalizedAttributes::write($original, 'content', 'fr', 'Bonjour');

        $this->assertSame(['content' => 'Hello'], $original);
    }

    public function test_write_then_read_round_trips(): void
    {
        $attributes = LocalizedAttributes::write([], 'text', 'fr', 'Cliquez ici');

        $this->assertSame('Cliquez ici', LocalizedAttributes::read($attributes, 'text', 'fr'));
        $this->assertNull(LocalizedAttributes::read($attributes, 'text', 'en'));
    }

    // ── hasContent() ──────────────────────────────────────────────────────

    public function test_has_content_rejects_null_empty_string_and_empty_array(): void
    {
        $this->assertFalse(LocalizedAttributes::hasContent(null));
        $this->assertFalse(LocalizedAttributes::hasContent(''));
        $this->assertFalse(LocalizedAttributes::hasContent('   '));
        $this->assertFalse(LocalizedAttributes::hasContent([]));
    }

    public function test_has_content_accepts_non_empty_values(): void
    {
        $this->assertTrue(LocalizedAttributes::hasContent('hello'));
        $this->assertTrue(LocalizedAttributes::hasContent(['a']));
        $this->assertTrue(LocalizedAttributes::hasContent(0));
        $this->assertTrue(LocalizedAttributes::hasContent(false));
    }

    // ── locales() ─────────────────────────────────────────────────────────

    public function test_locales_returns_every_candidate_when_there_are_no_translatable_keys(): void
    {
        $block = ['name' => 'heisenberg/separator', 'attributes' => []];

        $this->assertSame(['en', 'fr'], LocalizedAttributes::locales($block, [], ['en', 'fr']));
    }

    public function test_locales_returns_every_candidate_when_every_translatable_key_is_unused(): void
    {
        $block = ['name' => 'heisenberg/separator', 'attributes' => ['titleAttr' => '']];

        $this->assertSame(['en', 'fr'], LocalizedAttributes::locales($block, ['titleAttr'], ['en', 'fr']));
    }

    public function test_locales_reports_only_locales_with_an_explicit_suffixed_variant_when_no_home_locale_is_given(): void
    {
        $block = [
            'name' => 'heisenberg/heading',
            'attributes' => ['content' => 'Hello', 'content_fr' => 'Bonjour'],
        ];

        // Strict mode: the bare value counts for NO locale without a $homeLocale — only fr has
        // its own explicit suffix.
        $this->assertSame(['fr'], LocalizedAttributes::locales($block, ['content'], ['en', 'fr', 'de']));
    }

    public function test_locales_lets_the_home_locale_satisfy_an_attribute_via_its_bare_value(): void
    {
        $block = [
            'name' => 'heisenberg/heading',
            'attributes' => ['content' => 'Hello', 'content_fr' => 'Bonjour'],
        ];

        $this->assertSame(['en', 'fr'], LocalizedAttributes::locales($block, ['content'], ['en', 'fr', 'de'], 'en'));
    }

    public function test_locales_excludes_a_locale_missing_content_for_an_active_attribute(): void
    {
        $block = ['name' => 'heisenberg/heading', 'attributes' => ['content' => 'Hello']];

        $this->assertSame(['en'], LocalizedAttributes::locales($block, ['content'], ['en', 'fr'], 'en'));
    }

    public function test_locales_requires_every_active_attribute_not_just_one(): void
    {
        $block = [
            'name' => 'heisenberg/quote',
            'attributes' => [
                'content' => 'Quote', 'content_fr' => 'Citation',
                'citation' => 'Author', // no citation_fr
            ],
        ];

        $this->assertSame(['en'], LocalizedAttributes::locales($block, ['content', 'citation'], ['en', 'fr'], 'en'));
    }

    public function test_locales_home_locale_never_exempts_an_attribute_that_is_explicitly_empty_for_it(): void
    {
        // content_en explicitly set to '' — the home locale doesn't get a free pass once a
        // suffixed variant actually exists for it, empty or not.
        $block = ['name' => 'heisenberg/heading', 'attributes' => ['content' => 'Hello', 'content_en' => '', 'content_fr' => 'Bonjour']];

        $this->assertSame(['fr'], LocalizedAttributes::locales($block, ['content'], ['en', 'fr'], 'en'));
    }

    public function test_locales_accepts_a_bare_attributes_map_as_well_as_a_full_block(): void
    {
        $attributes = ['content' => 'Hello', 'content_fr' => 'Bonjour'];

        $this->assertSame(['en', 'fr'], LocalizedAttributes::locales($attributes, ['content'], ['en', 'fr'], 'en'));
    }
}
