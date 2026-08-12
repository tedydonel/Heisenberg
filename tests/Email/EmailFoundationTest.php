<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * docs/email-system.md §3 foundation: the `type` column, its default, its guarded (not
 * mass-assignable) posture, and the `scopePosts()`/`scopeEmails()` pair every listing
 * surface Heisenberg itself lists content through is meant to use.
 */
class EmailFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_post_defaults_to_type_post(): void
    {
        $post = Post::create(['title_en' => 'A post', 'locale' => 'en']);

        $this->assertSame('post', $post->fresh()->type);
    }

    public function test_type_is_not_mass_assignable(): void
    {
        $post = Post::create(['title_en' => 'A post', 'locale' => 'en', 'type' => 'email']);

        // The 'type' key in create()'s array is silently dropped by mass-assignment
        // protection -- same guarded posture PostController's docblock describes for
        // status/published_at/scheduled_at.
        $this->assertSame('post', $post->fresh()->type);
    }

    public function test_type_is_writable_via_direct_property_assignment(): void
    {
        $post = Post::create(['title_en' => 'An email', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $this->assertSame('email', $post->fresh()->type);
    }

    public function test_scope_posts_excludes_emails(): void
    {
        $post = Post::create(['title_en' => 'Blog post', 'locale' => 'en']);
        $email = Post::create(['title_en' => 'An email', 'locale' => 'en']);
        $email->type = 'email';
        $email->save();

        $ids = Post::query()->posts()->pluck('id')->all();

        $this->assertContains($post->id, $ids);
        $this->assertNotContains($email->id, $ids);
    }

    public function test_scope_emails_excludes_posts(): void
    {
        $post = Post::create(['title_en' => 'Blog post', 'locale' => 'en']);
        $email = Post::create(['title_en' => 'An email', 'locale' => 'en']);
        $email->type = 'email';
        $email->save();

        $ids = Post::query()->emails()->pluck('id')->all();

        $this->assertContains($email->id, $ids);
        $this->assertNotContains($post->id, $ids);
    }
}
