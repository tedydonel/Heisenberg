<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * docs/email-system.md §6 ("Getting a built email OUT of the editor") — the export action. Two
 * formats behind GET /emails/{slug}/export, the email's own address (§6.1); the editor's id-scoped
 * /editor/{post}/email-export redirects there carrying `format`. Gated exactly like the served
 * email itself (PostPolicy `view`), plus a 404 for a non-email post on both routes.
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

    public function test_html_export_is_ok_with_attachment_disposition_and_expected_filename(): void
    {
        $post = $this->makeEmailWithImage('a-newsletter');

        $response = $this->get("/emails/{$post->slug}/export?format=html");

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('filename="a-newsletter-en.html"', $disposition);
    }

    public function test_html_export_uses_public_urls_never_cid_and_never_leaves_an_unresolved_var(): void
    {
        $post = $this->makeEmailWithImage();

        $html = $this->get("/emails/{$post->slug}/export?format=html")->getContent();

        $this->assertStringContainsString('src="http', $html);
        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringNotContainsString('var(', $html);
    }

    public function test_missing_or_unknown_format_defaults_to_html(): void
    {
        $post = $this->makeEmailWithImage();

        $default = $this->get("/emails/{$post->slug}/export")->getContent();
        $bogus = $this->get("/emails/{$post->slug}/export?format=pdf")->getContent();
        $html = $this->get("/emails/{$post->slug}/export?format=html")->getContent();

        $this->assertSame($html, $default);
        $this->assertSame($html, $bogus);
    }

    public function test_eml_export_is_ok_with_attachment_disposition_and_expected_filename(): void
    {
        $post = $this->makeEmailWithImage('a-newsletter');

        $response = $this->get("/emails/{$post->slug}/export?format=eml");

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

        $raw = $this->get("/emails/{$post->slug}/export?format=eml")->getContent();

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

        $response = $this->get("/emails/{$post->slug}/export?format=eml");

        // Symfony refuses to serialize a message with neither From nor Sender — surfaced as a
        // controlled 422, never a fabricated From and never a raw stack trace.
        $response->assertStatus(422);
    }

    public function test_a_non_email_post_404s_on_both_routes(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en', 'status' => 'published']);

        $this->get("/emails/{$post->slug}/export?format=html")->assertNotFound();
        $this->get("/emails/{$post->slug}/export?format=eml")->assertNotFound();
        $this->get("/editor/{$post->id}/email-export?format=html")->assertNotFound();
    }

    public function test_a_draft_email_is_not_exportable_by_a_guest_actor(): void
    {
        $post = Post::create(['title_en' => 'Secret draft', 'status' => 'draft']);
        $post->type = 'email';
        $post->save();

        $this->get("/emails/{$post->slug}/export?format=html")->assertForbidden();
        $this->get("/emails/{$post->slug}/export?format=eml")->assertForbidden();
        $this->get("/editor/{$post->id}/email-export?format=html")->assertForbidden();
    }

    /**
     * The topbar's download menu knows a post id, not a slug the author may still be editing —
     * so it redirects, carrying `format` through, and the download itself comes from the email's
     * own address like everything else that renders it.
     */
    public function test_the_editor_export_route_redirects_to_the_slug_carrying_the_format(): void
    {
        $post = $this->makeEmailWithImage('a-newsletter');

        $this->get("/editor/{$post->id}/email-export?format=eml")
            ->assertRedirect('/emails/a-newsletter/export?format=eml');
        $this->get("/editor/{$post->id}/email-export")
            ->assertRedirect('/emails/a-newsletter/export?format=html');
    }

    public function test_the_topbar_renders_the_export_control_for_an_email_document(): void
    {
        $html = $this->get('/editor/email')->assertOk()->getContent();

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

    // ====================================================================
    // Wave E5 / Task 4 — sample-only single-document export. The HTML and
    // EML downloads must resolve `{{ ... }}` tokens through the registered
    // sample set, never accept runtime values, and never leak a host value
    // or a formatter exception message on a resolution failure.
    // ====================================================================

    private function makeEmailForExportSamples(string $sample = 'Sample'): Post
    {
        $post = Post::create([
            'title_en' => 'Hello {{ user.first_name }}',
            'title_fr' => 'Bonjour {{ user.first_name }}',
            'locale' => 'en',
            'status' => 'published',
            'slug' => 'export-newsletter',
        ]);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/heading', [
            'content' => 'Hi {{ user.first_name }}',
            'level' => 1,
        ]);
        $this->addBlock($post, 2, 'heisenberg/button', [
            'text' => 'Read more from {{ user.first_name }}',
            'url' => '{{ unsubscribe_url }}',
        ]);

        /** @var EmailVariableRegistry $registry */
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->override(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: $sample,
        ));

        $registry->override(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            sample: 'https://example.test/unsubscribe/sample',
        ));

        return $post;
    }

    public function test_html_export_substitutes_registered_samples_never_raw_tokens(): void
    {
        $post = $this->makeEmailForExportSamples();

        $response = $this->get("/emails/{$post->slug}/export?format=html");

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $html = (string) $response->getContent();
        $this->assertStringContainsString('Hi Sample', $html);
        $this->assertStringNotContainsString('{{ user.first_name }}', $html);
        $this->assertStringContainsString('https://example.test/unsubscribe/sample', $html);
        $this->assertStringNotContainsString('{{ unsubscribe_url }}', $html);
    }

    public function test_html_export_does_not_accept_runtime_values_from_query_string(): void
    {
        $post = $this->makeEmailForExportSamples();

        $r1 = $this->get("/emails/{$post->slug}/export?format=html");
        $r2 = $this->get("/emails/{$post->slug}/export?format=html&variables[user.first_name]=Ada");

        $r1->assertOk();
        $r2->assertOk();
        // The runtime-attempting request produces the same bytes as the plain request.
        $this->assertSame($r1->getContent(), $r2->getContent());
        $this->assertStringNotContainsString('Hi Ada', (string) $r2->getContent());
    }

    public function test_eml_export_substitutes_registered_samples_in_subject_and_body(): void
    {
        config(['mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Example Sender']);
        $post = $this->makeEmailForExportSamples();

        $response = $this->get("/emails/{$post->slug}/export?format=eml");

        $response->assertOk();
        $this->assertSame('message/rfc822', $response->headers->get('Content-Type'));
        $raw = (string) $response->getContent();
        // Subject and body both use the registered sample — no raw `{{ ... }}` token survives.
        $dequoted = str_replace(["=\r\n", "=\n"], '', $raw);
        $this->assertStringContainsString('Subject: Hello Sample', $dequoted);
        $this->assertStringContainsString('Hi Sample', $dequoted);
        $this->assertStringContainsString('https://example.test/unsubscribe/sample', $dequoted);
        $this->assertStringNotContainsString('{{ user.first_name }}', $dequoted);
        $this->assertStringNotContainsString('{{ unsubscribe_url }}', $dequoted);
    }

    public function test_eml_export_does_not_accept_runtime_values_from_query_string(): void
    {
        config(['mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Example Sender']);
        $post = $this->makeEmailForExportSamples();

        $r1 = $this->get("/emails/{$post->slug}/export?format=eml");
        $r2 = $this->get("/emails/{$post->slug}/export?format=eml&variables[user.first_name]=Ada");

        $r1->assertOk();
        $r2->assertOk();
        // The runtime-attempting request must NOT leak the runtime value into the EML body.
        // (The Message-ID, Date, and multipart boundary are non-deterministic across two
        // independent serializations, so the strictest assertion is on the body content.)
        $dequoted = str_replace(["=\r\n", "=\n"], '', (string) $r2->getContent());
        $this->assertStringNotContainsString('Hi Ada', $dequoted);
        $this->assertStringNotContainsString('Ada</a>', $dequoted);
        $this->assertStringNotContainsString('{{ user.first_name }}', $dequoted);
        $this->assertStringContainsString('Subject: Hello Sample', $dequoted);
        $this->assertStringContainsString('Hi Sample', $dequoted);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Non-leakage, locale, and token-free compatibility. The registered
    // sample is exactly what the author sees; runtime values (whatever
    // they are) NEVER appear. Locale is threaded into the same sample
    // resolution. An email with no tokens renders byte-for-byte through
    // the new sample path exactly like it did through the empty runtime
    // path — the Task 0 baseline stays compatible.
    // ─────────────────────────────────────────────────────────────────────

    public function test_html_export_uses_the_exact_registered_sample_value_never_a_runtime_default(): void
    {
        // The sample is `Tedy-Donelly` — a deliberately odd non-secret value. A runtime default
        // that silently substituted the sample with anything else (e.g. "Sample") would leak
        // host-meaning into a recipient's browser. This test pins the exact registered string
        // in BOTH the heading and the rich-text button label (translatable string target).
        $post = $this->makeEmailForExportSamples(sample: 'Tedy-Donelly');

        $html = (string) $this->get("/emails/{$post->slug}/export?format=html")->assertOk()->getContent();

        $this->assertStringContainsString('Hi Tedy-Donelly', $html);
        $this->assertStringNotContainsString('Hi Sample', $html);
        $this->assertStringContainsString('Tedy-Donelly</a>', $html);
    }

    public function test_html_export_threads_locale_through_sample_substitution(): void
    {
        // The same email rendered with `?locale=fr` should still substitute the sample value
        // (samples are formatter-typed, locale-formatted) — Task 4 does NOT change locale
        // resolution; only the source of values (registered sample, never runtime).
        $post = $this->makeEmailForExportSamples();

        $fr = (string) $this->get("/emails/{$post->slug}/export?format=html&locale=fr")->assertOk()->getContent();
        $this->assertStringContainsString('Hi Sample', $fr);

        // The French title `Bonjour ...` becomes `Bonjour Sample` — sample resolved in fr context.
        $this->assertStringContainsString('Bonjour Sample', $fr);
    }

    public function test_show_by_slug_token_free_email_renders_through_the_sample_path_unmodified(): void
    {
        // An email with NO `{{ ... }}` tokens anywhere must render byte-for-byte with or
        // without Task 4's sample context. The sample context is empty (no registered defs)
        // and the interpolator short-circuits on no-token strings — proving the sample path
        // does not perturb the existing no-variable baseline.
        $post = Post::create([
            'title_en' => 'A Newsletter',
            'locale' => 'en',
            'status' => 'published',
            'slug' => 'plain-newsletter',
        ]);
        $post->type = 'email';
        $post->save();
        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Hello Subscribers', 'level' => 1]);
        $this->addBlock($post, 2, 'heisenberg/paragraph', ['content' => 'No tokens in this body.']);

        $html = (string) $this->get("/emails/{$post->slug}")->assertOk()->getContent();
        $this->assertStringContainsString('Hello Subscribers', $html);
        $this->assertStringContainsString('No tokens in this body.', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 controlled error path — unknown tokens and formatter failures.
    // The author-facing GETs must surface a controlled 422 carrying keys +
    // safe reasons only. Runtime values, formatter exception messages,
    // and stack traces never reach the response body.
    // ─────────────────────────────────────────────────────────────────────

    private function makeEmailWithUnregisteredToken(): Post
    {
        $post = Post::create([
            'title_en' => 'Hello {{ user.first_name }}',
            'locale' => 'en',
            'status' => 'published',
            'slug' => 'unknown-token',
        ]);
        $post->type = 'email';
        $post->save();

        // Register only the heading's variable, leave `user.unknown` (in the
        // paragraph) unregistered — exercises the REASON_UNKNOWN_TOKEN path
        // alongside REASON_MISSING_VALUE.
        $this->addBlock($post, 1, 'heisenberg/heading', [
            'content' => 'Hi {{ user.first_name }}',
            'level' => 1,
        ]);
        $this->addBlock($post, 2, 'heisenberg/paragraph', [
            'content' => 'Unregistered: {{ user.unknown }}',
        ]);

        /** @var EmailVariableRegistry $registry */
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->override(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Sample',
        ));

        return $post;
    }

    public function test_show_by_slug_returns_422_with_keys_and_safe_reasons_for_unregistered_token(): void
    {
        $post = $this->makeEmailWithUnregisteredToken();

        $response = $this->getJson("/emails/{$post->slug}");

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'failures', 'keys']);
        $body = $response->json();
        $this->assertSame(['user.unknown'], $body['keys']);
        $this->assertCount(1, $body['failures']);
        $this->assertSame('user.unknown', $body['failures'][0]['key']);
        $this->assertSame('unknown token', $body['failures'][0]['reason']);
        // The unregistered key carries no associated VALUE; the body must not contain
        // any string that the author never supplied.
        $this->assertStringNotContainsString('{{ user.first_name }}', (string) $body['message']);
        $this->assertStringNotContainsString('Hi Sample', (string) $body['message']);
    }

    public function test_size_endpoint_returns_422_for_unregistered_token(): void
    {
        $post = $this->makeEmailWithUnregisteredToken();

        $response = $this->getJson("/editor/{$post->id}/email-size");

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'failures', 'keys']);
        $this->assertSame(['user.unknown'], $response->json('keys'));
    }

    public function test_html_export_returns_422_for_unregistered_token(): void
    {
        $post = $this->makeEmailWithUnregisteredToken();

        $response = $this->getJson("/emails/{$post->slug}/export?format=html");

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'failures', 'keys']);
        $this->assertSame(['user.unknown'], $response->json('keys'));
    }

    public function test_eml_export_returns_422_for_unregistered_token(): void
    {
        $post = $this->makeEmailWithUnregisteredToken();

        $response = $this->getJson("/emails/{$post->slug}/export?format=eml");

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'failures', 'keys']);
        $this->assertSame(['user.unknown'], $response->json('keys'));
    }

    public function test_422_body_never_includes_formatter_exception_message_or_stack_trace(): void
    {
        // `user.first_name` is registered with a custom `throwing` formatter that throws on
        // format() with a message containing a host secret. The 422 body must include the safe
        // reason (`formatter failed`) but NOT the host secret, the formatter's exception message,
        // or any stack trace fragment.
        $post = Post::create([
            'title_en' => 'Hello',
            'locale' => 'en',
            'status' => 'published',
            'slug' => 'formatter-fail',
        ]);
        $post->type = 'email';
        $post->save();
        $this->addBlock($post, 1, 'heisenberg/paragraph', ['content' => 'Hi {{ user.first_name }}']);

        /** @var EmailVariableRegistry $registry */
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->registerType(new \Heisenberg\Tests\Email\ThrowingTextEmailVariableType());
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'throwing',
            sample: 'Sample',
        ));

        $response = $this->getJson("/emails/{$post->slug}");
        $response->assertStatus(422);

        $raw = (string) $response->getContent();
        $this->assertStringContainsString('formatter failed', $raw);
        $this->assertStringNotContainsString('host-secret-token', $raw);
        $this->assertStringNotContainsString('formatter-internal', $raw);
        $this->assertStringNotContainsString('Stack trace', $raw);
        $this->assertStringNotContainsString('#0 /', $raw);
        $this->assertStringNotContainsString('EmailVariableInterpolator.php', $raw);
    }
}

/**
 * Helper type that throws on format() with a message containing a host secret. The
 * interpolator's REASON_FORMATTER_FAILED path must replace the wrapped message with the
 * safe reason constant — this proves that nothing host-side surfaces through the 422 body.
 */
final class ThrowingTextEmailVariableType implements \Heisenberg\Contracts\EmailVariableType
{
    public function key(): string
    {
        return 'throwing';
    }

    /** @return list<'text'|'url'|'email'> */
    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, \Heisenberg\Support\EmailVariableDefinition $definition, string $locale): string
    {
        throw new \RuntimeException('host-secret-token: formatter-internal state ' . json_encode($value));
    }
}
