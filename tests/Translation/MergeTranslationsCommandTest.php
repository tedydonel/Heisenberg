<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Console\Commands\MergeTranslationsCommand;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `php artisan heisenberg:merge-translations` (docs/content-translation.md §0) — the split-row to
 * single-row migration command. Run directly the same way ConfigDiffCommandTest/
 * TemplatesVerifyCommandTest exercise their own commands, without `Artisan::call()`.
 */
class MergeTranslationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function runCommand(array $input = []): array
    {
        $command = new MergeTranslationsCommand();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }

    private function heading(string $text): array
    {
        return ['name' => 'heisenberg/heading', 'attributes' => ['content' => $text]];
    }

    /** @return array{0: Post, 1: Post} [en (survivor-to-be), fr] */
    private function group(): array
    {
        $en = Post::create([
            'title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world',
            'excerpt_en' => 'An intro',
        ]);
        $en->blocks()->create(['type' => 'heading', 'content' => $this->heading('Hello world'), 'order' => 0]);

        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
            'excerpt_fr' => 'Une introduction',
        ]);
        $fr->blocks()->create(['type' => 'heading', 'content' => $this->heading('Bonjour le monde'), 'order' => 0]);

        return [$en->fresh(), $fr->fresh()];
    }

    public function test_a_clean_merge_folds_title_excerpt_and_block_content_into_the_survivor(): void
    {
        [$en, $fr] = $this->group();

        [$exitCode, $text] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 group(s) merged, 0 group(s) skipped.', $text);

        $survivor = $en->fresh(['blocks']);
        $this->assertSame('Bonjour le monde', $survivor->title_fr);
        $this->assertSame('Une introduction', $survivor->excerpt_fr);
        $this->assertSame('Bonjour le monde', $survivor->blocks->first()->content['attributes']['content_fr']);
        // The survivor's OWN content is untouched.
        $this->assertSame('Hello world', $survivor->blocks->first()->content['attributes']['content']);

        $this->assertNull(Post::query()->find($fr->id), 'the merged-away row must be gone');
    }

    public function test_the_default_locale_row_is_always_the_survivor_regardless_of_creation_order(): void
    {
        // fr created first, en second — survivor selection must still prefer the configured
        // default locale (en), not "oldest row".
        $fr = Post::create(['title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft']);
        $en = Post::create([
            'title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft',
            'translation_group_id' => $fr->translation_group_id,
        ]);

        $this->runCommand();

        $this->assertNotNull(Post::query()->find($en->id), 'the en row (default locale) must survive');
        $this->assertNull(Post::query()->find($fr->id));
    }

    public function test_comments_on_the_merged_away_row_are_reassigned_not_dropped(): void
    {
        [$en, $fr] = $this->group();
        $comment = Comment::create(['post_id' => $fr->id, 'author_name' => 'Guest', 'body' => 'Nice post!']);

        $this->runCommand();

        $this->assertSame($en->id, $comment->fresh()->post_id);
    }

    public function test_seo_locale_fields_fold_into_the_survivors_seo_meta_row(): void
    {
        [$en, $fr] = $this->group();
        SeoMeta::create([
            'able_type' => $fr->getMorphClass(), 'able_id' => $fr->getKey(),
            'meta_title_fr' => 'Titre SEO', 'meta_description_fr' => 'Description SEO',
        ]);

        $this->runCommand();

        $survivorSeo = SeoMeta::query()->where('able_type', $en->getMorphClass())->where('able_id', $en->getKey())->first();
        $this->assertNotNull($survivorSeo);
        $this->assertSame('Titre SEO', $survivorSeo->meta_title_fr);
        $this->assertSame('Description SEO', $survivorSeo->meta_description_fr);
        // The sibling's own now-redundant row must not be left behind as an orphan.
        $this->assertSame(0, SeoMeta::query()->where('able_id', $fr->id)->where('able_type', $fr->getMorphClass())->count());
    }

    public function test_dry_run_reports_the_plan_but_writes_nothing(): void
    {
        [$en, $fr] = $this->group();

        [$exitCode, $text] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 group(s) would merge', $text);
        $this->assertStringContainsString('Dry run: nothing was written.', $text);

        $this->assertNull($en->fresh()->title_fr, 'the survivor must be untouched');
        $this->assertNotNull(Post::query()->find($fr->id), 'the sibling row must still exist');
    }

    public function test_a_group_with_differently_shaped_block_trees_is_skipped_entirely(): void
    {
        $en = Post::create(['title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world']);
        $en->blocks()->create(['type' => 'heading', 'content' => $this->heading('Hello world'), 'order' => 0]);

        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);
        // A second block the en row never got — a genuine structural divergence.
        $fr->blocks()->create(['type' => 'heading', 'content' => $this->heading('Bonjour le monde'), 'order' => 0]);
        $fr->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Extra']], 'order' => 1]);

        [$exitCode, $text] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0 group(s) merged, 1 group(s) skipped.', $text);
        $this->assertStringContainsString('block count differs', $text);

        // Nothing was touched — both rows survive untouched.
        $this->assertNotNull(Post::query()->find($en->id));
        $this->assertNotNull(Post::query()->find($fr->id));
        $this->assertNull($en->fresh()->title_fr);
    }

    public function test_a_block_name_mismatch_at_the_same_position_is_skipped(): void
    {
        $en = Post::create(['title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world']);
        $en->blocks()->create(['type' => 'heading', 'content' => $this->heading('Hello world'), 'order' => 0]);

        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);
        // Same count, different block type at position 0.
        $fr->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Bonjour']], 'order' => 0]);

        [, $text] = $this->runCommand();

        $this->assertStringContainsString('block name mismatch', $text);
        $this->assertNotNull(Post::query()->find($fr->id));
    }

    public function test_a_survivor_with_conflicting_title_is_skipped_entirely(): void
    {
        [$en, $fr] = $this->group();
        $en->title_fr = 'Un titre different'; // already has ITS OWN fr title, differing from the sibling's
        $en->save();

        [, $text] = $this->runCommand();

        $this->assertStringContainsString('title_fr already has different content', $text);
        $this->assertSame('Un titre different', $en->fresh()->title_fr, 'the survivor keeps its own value, untouched');
        $this->assertNotNull(Post::query()->find($fr->id), 'the sibling is not removed on a skip');
    }

    public function test_a_survivor_with_conflicting_block_attribute_text_is_skipped_entirely(): void
    {
        $en = Post::create(['title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world']);
        $en->blocks()->create([
            'type' => 'heading',
            'content' => ['name' => 'heisenberg/heading', 'attributes' => ['content' => 'Hello world', 'content_fr' => 'Already translated, differently']],
            'order' => 0,
        ]);
        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);
        $fr->blocks()->create(['type' => 'heading', 'content' => $this->heading('Bonjour le monde'), 'order' => 0]);

        [, $text] = $this->runCommand();

        $this->assertStringContainsString("attribute 'content' already has different fr content", $text);
        $this->assertNotNull(Post::query()->find($fr->id));
    }

    public function test_identical_existing_content_is_not_a_conflict(): void
    {
        // The survivor already carries the EXACT SAME fr text (e.g. a previous partial run, or
        // hand-authored) — this must merge cleanly, not be treated as a conflict.
        $en = Post::create(['title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world']);
        $en->blocks()->create([
            'type' => 'heading',
            'content' => ['name' => 'heisenberg/heading', 'attributes' => ['content' => 'Hello world', 'content_fr' => 'Bonjour le monde']],
            'order' => 0,
        ]);
        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);
        $fr->blocks()->create(['type' => 'heading', 'content' => $this->heading('Bonjour le monde'), 'order' => 0]);

        [$exitCode, $text] = $this->runCommand();

        $this->assertStringContainsString('1 group(s) merged, 0 group(s) skipped.', $text);
        $this->assertNull(Post::query()->find($fr->id));
    }

    public function test_reports_nothing_to_do_when_there_are_no_split_row_groups(): void
    {
        Post::create(['title_en' => 'Solo', 'locale' => 'en', 'status' => 'draft']);

        [$exitCode, $text] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('nothing to merge', $text);
    }
}
