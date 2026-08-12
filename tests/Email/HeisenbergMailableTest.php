<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Mail\HeisenbergMailable;
use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
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
}
