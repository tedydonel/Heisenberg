<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Mail\HeisenbergMailable;
use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableContext;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\EmailVariableResolutionException;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * {@see HeisenbergMailable} coverage (docs/email-system.md §6): constructed from a post id, it
 * carries subject/html/text and attaches every {@see \Heisenberg\Services\EmailRenderer} embed
 * with the EXACT Content-ID the HTML already references.
 */
class HeisenbergMailableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    private function tinyImageBytes(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
    }

    private function makeEmail(): Post
    {
        $post = Post::create(['title_en' => 'Weekly Digest', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        Block::create([
            'post_id' => $post->id,
            'type' => 'heading',
            'content' => [
                'id' => 'b1', 'name' => 'heisenberg/heading', 'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Digest Time', 'level' => 2], 'supports' => [], 'innerBlocks' => [],
            ],
            'order' => 1,
        ]);
        Block::create([
            'post_id' => $post->id,
            'type' => 'paragraph',
            'content' => [
                'id' => 'b2', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Here is what happened this week.'], 'supports' => [], 'innerBlocks' => [],
            ],
            'order' => 2,
        ]);

        Storage::disk('uploads')->put('media/2026/07/pic.jpg', $this->tinyImageBytes());
        PublicFile::create([
            'type' => 'jpg', 'disk' => 'uploads', 'stored_path' => 'media/2026/07/pic.jpg',
            'original_name' => 'pic.jpg', 'stored_name' => 'pic.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 1024, 'width' => 400, 'height' => 300,
        ]);
        Block::create([
            'post_id' => $post->id,
            'type' => 'image',
            'content' => [
                'id' => 'b3', 'name' => 'heisenberg/image', 'schemaVersion' => '1.0.0',
                'attributes' => ['url' => '/uploads/media/2026/07/pic.jpg', 'alt' => 'A pic'], 'supports' => [], 'innerBlocks' => [],
            ],
            'order' => 3,
        ]);

        return $post;
    }

    public function test_it_carries_subject_html_and_text_from_the_renderer(): void
    {
        $post = $this->makeEmail();

        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertSame('Weekly Digest', $mailable->result->subject);
        $mailable->assertHasSubject('Weekly Digest');
        $mailable->assertSeeInHtml('Digest Time');
        $mailable->assertSeeInText('Here is what happened this week.');
    }

    public function test_it_defaults_the_locale_when_none_is_given(): void
    {
        $post = $this->makeEmail();

        $mailable = new HeisenbergMailable($post->id);

        $this->assertSame('Weekly Digest', $mailable->result->subject);
    }

    public function test_the_symfony_message_carries_an_inline_part_with_the_exact_cid_the_html_references(): void
    {
        $post = $this->makeEmail();

        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertCount(1, $mailable->result->embeds);
        $expectedCid = $mailable->result->embeds[0]['cid'];
        $this->assertStringContainsString('cid:' . $expectedCid, $mailable->result->html);

        // withSymfonyMessage() callbacks run at send()-build time (Mailable::runCallbacks());
        // invoking them directly against a fresh Email exercises the exact same attach logic
        // without needing a real transport.
        $message = new SymfonyEmail();
        foreach ($mailable->callbacks as $callback) {
            $callback($message);
        }

        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);

        $part = $attachments[0];
        $this->assertSame($expectedCid, $part->getContentId());
        $this->assertSame('image/jpeg', $part->getContentType());
        $this->assertSame('inline', $part->getPreparedHeaders()->get('Content-Disposition')->getBody());
    }

    public function test_an_email_with_no_images_attaches_nothing(): void
    {
        $post = Post::create(['title_en' => 'Text Only', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();
        Block::create([
            'post_id' => $post->id,
            'type' => 'paragraph',
            'content' => [
                'id' => 'b1', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Just words.'], 'supports' => [], 'innerBlocks' => [],
            ],
            'order' => 1,
        ]);

        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertSame([], $mailable->result->embeds);
        $this->assertSame([], $mailable->callbacks);
    }

    // ====================================================================
    // Wave E5 / Task 3 — HeisenbergMailable threading recipient variables.
    // The constructor gains a third parameter: `array|EmailVariableContext
    // $variables = []`. A plain array is normalized into a strict runtime
    // context; an explicitly supplied context is preserved verbatim.
    // ====================================================================

    private function makeEmailWithTokens(): Post
    {
        $post = Post::create(['title_en' => 'Welcome {{ user.first_name }}', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        Block::create([
            'post_id' => $post->id,
            'type' => 'heading',
            'content' => [
                'id' => 'b1', 'name' => 'heisenberg/heading', 'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Hi {{ user.first_name }}', 'level' => 2], 'supports' => [], 'innerBlocks' => [],
            ],
            'order' => 1,
        ]);
        Block::create([
            'post_id' => $post->id,
            'type' => 'button',
            'content' => [
                'id' => 'b2', 'name' => 'heisenberg/button', 'schemaVersion' => '1.0.0',
                'attributes' => ['text' => 'Open {{ user.first_name }}', 'url' => '{{ unsubscribe_url }}'],
                'supports' => [], 'innerBlocks' => [],
            ],
            'order' => 2,
        ]);

        /** @var EmailVariableRegistry $registry */
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name', label: 'First name', type: 'text', sample: 'Sample',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'unsubscribe_url', label: 'Unsubscribe URL', type: 'url',
            sample: 'https://example.test/unsub/sample',
        ));

        return $post;
    }

    public function test_it_personalizes_subject_html_and_text_from_a_plain_array_argument(): void
    {
        $post = $this->makeEmailWithTokens();

        // Third argument is a plain array — normalized to a strict runtime context.
        $mailable = new HeisenbergMailable($post->id, 'en', [
            'user.first_name' => 'Ada',
            'unsubscribe_url' => 'https://example.test/unsub/ada',
        ]);

        $this->assertSame('Welcome Ada', $mailable->result->subject);
        $mailable->assertHasSubject('Welcome Ada');
        $mailable->assertSeeInHtml('Hi Ada');
        $mailable->assertSeeInHtml('Open Ada');
        $this->assertStringContainsString('href="https://example.test/unsub/ada"', $mailable->result->html);
        $mailable->assertSeeInText('Open Ada (https://example.test/unsub/ada)');
    }

    public function test_an_explicit_email_variable_context_is_preserved_verbatim(): void
    {
        $post = $this->makeEmailWithTokens();

        // Pass a context explicitly — the mailable must NOT re-wrap it as a
        // plain array and must preserve its mode.
        $context = EmailVariableContext::runtime([
            'user.first_name' => 'Ben',
            'unsubscribe_url' => 'https://example.test/unsub/ben',
        ]);

        $mailable = new HeisenbergMailable($post->id, 'en', $context);

        $this->assertSame('Welcome Ben', $mailable->result->subject);
        $this->assertStringContainsString('Hi Ben', $mailable->result->html);
    }

    public function test_two_mailable_instances_with_different_recipients_produce_different_payloads(): void
    {
        $post = $this->makeEmailWithTokens();

        $ada = new HeisenbergMailable($post->id, 'en', [
            'user.first_name' => 'Ada',
            'unsubscribe_url' => 'https://example.test/unsub/ada',
        ]);
        $ben = new HeisenbergMailable($post->id, 'en', [
            'user.first_name' => 'Ben',
            'unsubscribe_url' => 'https://example.test/unsub/ben',
        ]);

        $this->assertNotSame($ada->result->html, $ben->result->html);
        $this->assertSame('Welcome Ada', $ada->result->subject);
        $this->assertSame('Welcome Ben', $ben->result->subject);
    }

    public function test_mailable_subject_is_raw_plain_text_without_html_entities(): void
    {
        $post = $this->makeEmailWithTokens();

        $mailable = new HeisenbergMailable($post->id, 'en', [
            'user.first_name' => '<unsafe> & "quoted"',
            'unsubscribe_url' => 'https://example.test/unsub/ada',
        ]);

        // The subject the Mailable forwards to Symfony Mime is the raw plain
        // text — no HTML entities. wrapShell() escapes at its own boundary.
        $this->assertSame('Welcome <unsafe> & "quoted"', $mailable->result->subject);
        $this->assertStringNotContainsString('&lt;', $mailable->result->subject);
        $this->assertStringNotContainsString('&quot;', $mailable->result->subject);
    }

    public function test_missing_runtime_value_throws_before_mailable_construction_can_send(): void
    {
        $post = $this->makeEmailWithTokens();

        $this->expectException(EmailVariableResolutionException::class);
        $this->expectExceptionMessage('user.first_name');

        // `user.first_name` is registered but the runtime map omits it.
        new HeisenbergMailable($post->id, 'en', [
            'unsubscribe_url' => 'https://example.test/unsub/ada',
        ]);
    }

    public function test_omitted_third_argument_means_strict_empty_runtime_context(): void
    {
        // No third argument — must behave like a strict empty runtime map,
        // never silently fall back to samples.
        $post = $this->makeEmailWithTokens();

        $this->expectException(EmailVariableResolutionException::class);
        $this->expectExceptionMessage('user.first_name');

        new HeisenbergMailable($post->id, 'en');
    }

    public function test_existing_two_argument_construction_still_renders_byte_for_byte(): void
    {
        // The pre-existing two-arg constructor (post id + locale) must keep
        // working exactly as it did — no behavior change for an email that
        // carries no tokens at all.
        $post = $this->makeEmail();

        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertSame('Weekly Digest', $mailable->result->subject);
        $mailable->assertHasSubject('Weekly Digest');
        $mailable->assertSeeInHtml('Digest Time');
        $mailable->assertSeeInText('Here is what happened this week.');
    }
}
