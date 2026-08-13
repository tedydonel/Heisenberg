<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * docs/email-system.md §6 ("Getting a built email OUT of the editor") — EmailPreviewController::
 * export(). Two formats behind the same GET /editor/{post}/email-export route, gated exactly like
 * email-preview/email-size (PostPolicy `view`), plus a 404 for a non-email post.
 */
class EmailExportControllerTest extends TestCase
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

    private function addBlock(Post $post, int $order, string $name, array $attributes): Block
    {
        return Block::create([
            'post_id' => $post->id,
            'type' => substr($name, strrpos($name, '/') + 1),
            'content' => [
                'id' => 'b' . $order,
                'name' => $name,
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => $order,
        ]);
    }

    private function makeEmailWithImage(string $slug = 'a-newsletter'): Post
    {
        Storage::disk('uploads')->put('media/2026/07/photo.jpg', $this->tinyImageBytes());

        PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/photo.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'width' => 400,
            'height' => 300,
            'variants' => [],
        ]);

        // Published, same reasoning as EmailPreviewControllerTest::makeEmailWithImage(): a guest
        // actor's PostPolicy::view() grants access with no acting-user stub needed.
        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en', 'status' => 'published', 'slug' => $slug]);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Hello', 'level' => 2]);
        $this->addBlock($post, 2, 'heisenberg/image', ['url' => '/uploads/media/2026/07/photo.jpg', 'alt' => 'A photo']);

        return $post;
    }

    // ── format=html ──────────────────────────────────────────────────────────

    public function test_html_export_is_ok_with_attachment_disposition_and_expected_filename(): void
    {
        $post = $this->makeEmailWithImage('a-newsletter');

        $response = $this->get("/editor/{$post->id}/email-export?format=html");

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('filename="a-newsletter-en.html"', $disposition);
    }

    public function test_html_export_uses_public_urls_never_cid_and_never_leaves_an_unresolved_var(): void
    {
        $post = $this->makeEmailWithImage();

        $html = $this->get("/editor/{$post->id}/email-export?format=html")->getContent();

        $this->assertStringContainsString('src="http', $html);
        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringNotContainsString('var(', $html);
    }

    public function test_missing_or_unknown_format_defaults_to_html(): void
    {
        $post = $this->makeEmailWithImage();

        $default = $this->get("/editor/{$post->id}/email-export")->getContent();
        $bogus = $this->get("/editor/{$post->id}/email-export?format=pdf")->getContent();
        $html = $this->get("/editor/{$post->id}/email-export?format=html")->getContent();

        $this->assertSame($html, $default);
        $this->assertSame($html, $bogus);
    }

    // ── format=eml ───────────────────────────────────────────────────────────

    public function test_eml_export_is_ok_with_attachment_disposition_and_expected_filename(): void
    {
        $post = $this->makeEmailWithImage('a-newsletter');

        $response = $this->get("/editor/{$post->id}/email-export?format=eml");

        $response->assertOk();
        $this->assertSame('message/rfc822', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('filename="a-newsletter-en.eml"', $disposition);
    }

    /**
     * Hand-rolled recursive multipart splitter (RFC 2046 boundary walking) — no MIME-parsing
     * package is on disk, and the deliverable is "the body parses as a MIME message", not "we
     * depend on a third-party parser" for a single test. Header folding (continuation lines
     * starting with whitespace) is unfolded first; each leaf is returned as
     * {headers: array<lowercase-name, value>, body: string}.
     *
     * @return list<array{headers: array<string, string>, body: string}>
     */
    private function mimeLeaves(string $raw): array
    {
        $normalized = str_replace("\r\n", "\n", (string) preg_replace("/\r\n[ \t]+/", ' ', $raw));

        return $this->mimeLeavesOf($normalized);
    }

    /** @return list<array{headers: array<string, string>, body: string}> */
    private function mimeLeavesOf(string $part): array
    {
        [$headerBlock, $body] = array_pad(explode("\n\n", $part, 2), 2, '');
        $headers = [];
        foreach (explode("\n", $headerBlock) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $contentType = $headers['content-type'] ?? '';
        if (preg_match('#^multipart/#i', $contentType) && preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $bm)) {
            $leaves = [];
            foreach (explode('--' . $bm[1], $body) as $segment) {
                $segment = trim($segment);
                if ($segment === '' || $segment === '--') {
                    continue;
                }
                $leaves = array_merge($leaves, $this->mimeLeavesOf(ltrim($segment, "\n")));
            }

            return $leaves;
        }

        return [['headers' => $headers, 'body' => $body]];
    }

    public function test_eml_export_parses_as_a_mime_message_with_text_html_and_a_matching_cid_image_part(): void
    {
        config(['mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Example Sender']);
        $post = $this->makeEmailWithImage();

        $raw = $this->get("/editor/{$post->id}/email-export?format=eml")->getContent();

        // The html part is quoted-printable encoded (RFC 2045 §6.7): a `cid:` reference can land
        // across a soft line-wrap (`=\r\n`), which is not part of the data and must be undone
        // before matching — the html body's ACTUAL content never contains it.
        $dequoted = str_replace(["=\r\n", "=\n"], '', $raw);

        $this->assertMatchesRegularExpression('/cid:([^"\']+)/', $dequoted, 'expected an embedded cid: reference in the html part');
        preg_match('/cid:([^"\']+)/', $dequoted, $m);
        $cid = $m[1];

        $leaves = $this->mimeLeaves($raw);

        $foundText = false;
        $foundHtml = false;
        $foundImagePart = false;
        foreach ($leaves as $leaf) {
            $contentType = $leaf['headers']['content-type'] ?? '';
            $contentId = $leaf['headers']['content-id'] ?? '';
            if (str_starts_with($contentType, 'text/plain')) {
                $foundText = true;
            }
            if (str_starts_with($contentType, 'text/html')) {
                $foundHtml = true;
            }
            if (str_starts_with($contentType, 'image/') && str_contains($contentId, $cid)) {
                $foundImagePart = true;
            }
        }

        $this->assertTrue($foundText, 'expected a text/plain part');
        $this->assertTrue($foundHtml, 'expected a text/html part');
        $this->assertTrue($foundImagePart, "expected an inline image part whose Content-ID matches the html's cid:");
        $this->assertStringContainsString('From: Example Sender <sender@example.test>', $raw);
    }

    public function test_eml_export_never_sets_a_from_header_when_not_configured(): void
    {
        config(['mail.from.address' => '', 'mail.from.name' => '']);
        $post = $this->makeEmailWithImage();

        $response = $this->get("/editor/{$post->id}/email-export?format=eml");

        // Symfony refuses to serialize a message with neither From nor Sender — surfaced as a
        // controlled 422, never a fabricated From and never a raw stack trace.
        $response->assertStatus(422);
    }

    // ── gating ───────────────────────────────────────────────────────────────

    public function test_a_non_email_post_404s(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en', 'status' => 'published']);

        $this->get("/editor/{$post->id}/email-export?format=html")->assertNotFound();
        $this->get("/editor/{$post->id}/email-export?format=eml")->assertNotFound();
    }

    public function test_a_draft_email_is_not_exportable_by_a_guest_actor(): void
    {
        $post = Post::create(['title_en' => 'Secret draft', 'status' => 'draft']);
        $post->type = 'email';
        $post->save();

        $this->get("/editor/{$post->id}/email-export?format=html")->assertForbidden();
        $this->get("/editor/{$post->id}/email-export?format=eml")->assertForbidden();
    }

    // ── topbar wiring (server-rendered, not a script-execution test) ───────────

    public function test_the_topbar_renders_the_export_control_for_an_email_document(): void
    {
        $html = $this->get('/editor?type=email')->assertOk()->getContent();

        // Matched on the ROOT TAG itself, not the bare data-attribute/class name alone — the
        // topbar's own (unconditional) wiring script references `[data-hb-export-toggle]` and
        // `.hb-topbar__exportsel` as selectors regardless of whether the control renders.
        $this->assertStringContainsString('<div class="hb-topbar__exportsel">', $html);
        $this->assertStringContainsString('data-hb-export-item data-format="html"', $html);
        $this->assertStringContainsString('data-hb-export-item data-format="eml"', $html);
    }

    public function test_the_topbar_never_renders_the_export_control_for_a_plain_post(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringNotContainsString('<div class="hb-topbar__exportsel">', $html);
        $this->assertStringNotContainsString('data-hb-export-item data-format="html"', $html);
    }
}
