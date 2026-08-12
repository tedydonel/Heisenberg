<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `Post::isTranslationOutdated()` (docs/content-translation.md §2): true only when
 * `translated_from_version` is set AND a sibling exists AND that sibling's
 * `content_version` has moved past the recorded value.
 */
class PostTranslationOutdatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `content_version` is deliberately not fillable (Post's own docblock) — a just-created
     * in-memory model never had it hydrated into `$attributes`, so reading it straight off the
     * `create()` result sees an unset value rather than the DB default of 0. `->fresh()` forces a
     * real reload so callers see the actual persisted `content_version`.
     */
    private function pair(): array
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);

        return [$en->fresh(), $fr->fresh()];
    }

    public function test_a_fresh_translation_is_not_outdated(): void
    {
        [$en, $fr] = $this->pair();

        $fr->translated_from_version = $en->content_version; // 0, matches source exactly
        $fr->save();

        $this->assertFalse($fr->fresh()->isTranslationOutdated());
    }

    public function test_bumping_the_source_content_version_makes_the_translation_outdated(): void
    {
        [$en, $fr] = $this->pair();

        $fr->translated_from_version = $en->content_version;
        $fr->save();

        $en->bumpContentVersion();

        $this->assertTrue($fr->fresh()->isTranslationOutdated());
    }

    public function test_null_translated_from_version_is_never_outdated_even_if_source_moved(): void
    {
        [$en, $fr] = $this->pair();

        $this->assertNull($fr->fresh()->translated_from_version);

        $en->bumpContentVersion();
        $en->bumpContentVersion();

        $this->assertFalse($fr->fresh()->isTranslationOutdated());
    }

    public function test_a_row_with_no_sibling_is_never_outdated_even_with_a_stamped_version(): void
    {
        $solo = Post::create(['title_en' => 'Solo', 'locale' => 'en', 'status' => 'draft']);
        $solo->translated_from_version = 5;
        $solo->save();

        $this->assertFalse($solo->fresh()->isTranslationOutdated());
    }
}
