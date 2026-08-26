<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * HTTP-layer coverage for the admin batch export endpoint
 * (.hermes/plans/2026-08-25_190059-email-template-variables.md, Task 6):
 * `POST /editor/email/{post}/batch-export` (authenticated editor stack),
 * gated by PostPolicy `generateEmailBatch` (LocalDevRoleGate +
 * `email.generate` tier + email type + published status). Body is JSON
 * only, success returns `application/zip` with an attachment disposition,
 * every failure surfaces as a controlled 4xx — and the on-disk zip is
 * always deleted on the cleanup path, so the editor's storage directory
 * does not accumulate half-zipped files.
 */
class EmailBatchExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
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

    private function makePublishedEmail(string $slug = 'august-letter', string $subject = 'Hello {{ user.first_name }}'): Post
    {
        $post = Post::create([
            'title_en' => $subject,
            'locale' => 'en',
            'status' => 'published',
            'slug' => $slug,
        ]);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/heading', [
            'content' => 'Hi {{ user.first_name }}',
            'level' => 1,
        ]);

        return $post;
    }

    private function registerStandardVariables(): void
    {
        /** @var EmailVariableRegistry $registry */
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Sample',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            sample: 'https://example.test/unsubscribe/sample',
        ));
    }

    private function postBatch(Post $post, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/editor/email/{$post->id}/batch-export", $body);
    }

    private function postBatchRaw(Post $post, string $body, string $contentType): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            method: 'POST',
            uri: "/editor/email/{$post->id}/batch-export",
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => $contentType],
            content: $body,
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Auth matrix — anonymous / viewer / author / editor / admin.
    // ────────────────────────────────────────────────────────────────────

    public function test_an_anonymous_actor_is_forbidden(): void
    {
        // Outside `local`, the LocalDevRoleGate's GuestActor bypass is OFF — a real anonymous
        // actor (no logged-in user) gets the same 403 a viewer would, never 422 (the Gate runs
        // BEFORE validation, so a forged body never gets to leak).
        $this->app['env'] = 'testing';
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ])->assertForbidden();
    }

    public function test_an_author_is_forbidden_under_default_config(): void
    {
        // `email.generate` defaults to `admin` only. An author may view/update the email
        // (their own draft or any published), but they MUST NOT be able to mass-export a
        // personalized zip from it.
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'author'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ])->assertForbidden();
    }

    public function test_an_editor_is_forbidden_under_default_config(): void
    {
        // Same as `author` — the default tier is `admin` only. Editors may publish a post,
        // but they MUST NOT be able to spin up a personalized batch.
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'editor'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ])->assertForbidden();
    }

    public function test_an_admin_is_allowed(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $response = $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
    }

    public function test_an_editor_is_allowed_when_the_host_remaps_email_generate_to_include_them(): void
    {
        // Host-rebindable: the controller reads the role gate; the gate reads
        // config('heisenberg.roles') live on every call (see ConfigRoleGate's class docblock).
        // The published config's tier map uses literal `email.generate` keys (not nested
        // arrays) because Arr::set treats dots in keys as path separators, so a host that
        // wants editors in `email.generate` sets the whole `heisenberg.roles` map in their
        // published config file — no policy or container change required. The test simulates
        // that published-config posture.
        $this->app['env'] = 'testing';
        config(['heisenberg.roles' => [
            'super' => ['admin'],
            'admins' => ['admin'],
            'editors' => ['admin', 'editor'],
            'authors' => ['admin', 'editor', 'author'],
            'media.viewAny' => ['admin', 'editor', 'author', 'viewer'],
            'media.create' => ['admin', 'editor', 'author'],
            'media.updateAny' => ['admin', 'editor'],
            'media.deleteAny' => ['admin', 'editor'],
            'comments.moderate' => ['admin', 'editor'],
            'email.generate' => ['admin', 'editor'], // <-- the remap
        ]]);
        $this->app->forgetInstance(RoleGate::class);

        $this->actingAs(new FakeActor(1, 'editor'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ])->assertOk();
    }

    public function test_a_draft_email_is_forbidden_for_an_admin(): void
    {
        // The plan's "Batch generate uses … Also require the email type = 'email' AND status = 'published'"
        // rule — even an admin cannot spin a batch from a draft (the host has not approved the
        // content yet, so a "ready to send" guarantee is missing).
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $draft = Post::create(['title_en' => 'Not yet', 'status' => 'draft']);
        $draft->type = 'email';
        $draft->save();
        $this->registerStandardVariables();

        $this->postBatch($draft, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ])->assertForbidden();
    }

    public function test_a_non_email_post_is_not_found(): void
    {
        // The plan's "Also require the email type = 'email'" rule — a plain blog post is not a
        // batch-able surface. The controller returns 404 for non-email posts rather than 403
        // (an admin could theoretically view it, but it is structurally not a batch address).
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $plain = Post::create(['title_en' => 'A Blog Post', 'status' => 'published']);
        $this->registerStandardVariables();

        $this->postJson("/editor/email/{$plain->id}/batch-export", [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']]],
        ])->assertNotFound();
    }

    // ────────────────────────────────────────────────────────────────────
    // JSON-only request body.
    // ────────────────────────────────────────────────────────────────────

    public function test_a_non_json_body_is_rejected(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $response = $this->postBatchRaw($post, 'format=html', 'application/x-www-form-urlencoded');

        $response->assertStatus(422);
    }

    // ────────────────────────────────────────────────────────────────────
    // Validation failures surface as 422 with a structured JSON body.
    // ────────────────────────────────────────────────────────────────────

    public function test_invalid_format_is_a_422_with_a_safe_message(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $response = $this->postBatch($post, [
            'format' => 'pdf',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertArrayHasKey('message', $body);
        $this->assertStringContainsString('format', strtolower((string) $body['message']));
    }

    public function test_over_cap_is_a_422(): void
    {
        config(['heisenberg.email.batch_max_recipients' => 2]);
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $response = $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'A', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u2', 'values' => ['user.first_name' => 'B', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u3', 'values' => ['user.first_name' => 'C', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_unregistered_variable_key_in_values_is_a_422(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $response = $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => [
                    'user.first_name' => 'Ada',
                    'unsubscribe_url' => 'https://e.test/u',
                    'user.unknown' => 'oops',
                ]],
            ],
        ]);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertStringContainsString('user.unknown', (string) $body['message']);
    }

    public function test_missing_translation_is_a_422_not_a_zip(): void
    {
        // The admin asks for a `fr` zip from an `en`-only row — the controller surfaces this
        // as a 422 with the missing-translation message, NOT as a zip containing the English
        // body mislabeled as French.
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $response = $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['fr'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertStringContainsString('fr', (string) $body['message']);
    }

    // ────────────────────────────────────────────────────────────────────
    // Cleanup: no partial zip on a failure.
    // ────────────────────────────────────────────────────────────────────

    public function test_validation_failure_leaves_no_zip_in_storage(): void
    {
        // An invalid format means the exporter never runs — the storage directory must NOT
        // grow a half-zipped file. (The exporter's own all-or-nothing path covers runtime
        // failures; this case tests the controller's "fail before the exporter" path.)
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $before = glob(storage_path('app/email-batch-*.zip')) ?: [];

        $this->postBatch($post, [
            'format' => 'pdf',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ])->assertStatus(422);

        $after = glob(storage_path('app/email-batch-*.zip')) ?: [];
        $this->assertSame($before, $after);
    }

    public function test_runtime_resolution_failure_leaves_no_zip_in_storage(): void
    {
        // The exporter's own all-or-nothing path: the first recipient resolves fine, the
        // second is missing `user.first_name`. The exporter must aggregate the failure, throw,
        // and the controller must not delete-then-respond-as-success — i.e. NO zip survives
        // on disk after the 422.
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $before = glob(storage_path('app/email-batch-*.zip')) ?: [];

        $response = $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u2', 'values' => ['unsubscribe_url' => 'https://e.test/u']], // missing user.first_name
            ],
        ]);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertStringContainsString('user.first_name', (string) $body['message']);

        $after = glob(storage_path('app/email-batch-*.zip')) ?: [];
        $this->assertSame($before, $after, 'no zip may remain on disk after a per-recipient resolution failure');
    }

    // ────────────────────────────────────────────────────────────────────
    // Happy path: ZIP response, headers, contents.
    // ────────────────────────────────────────────────────────────────────

    public function test_successful_export_returns_a_valid_zip_with_personalised_files(): void
    {
        config(['mail.from.address' => 'sender@example.test']);
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $post = $this->makePublishedEmail('august-letter');
        $this->registerStandardVariables();

        $response = $this->postBatch($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u1']],
                ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://e.test/u2']],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        // BinaryFileResponse::getContent() always returns false (the bytes stream at send
        // time, never load into memory); deleteFileAfterSend(true) means the temp file is
        // unlinked in the same finally block that streamed it. The way to inspect the zip in
        // a unit test is to claim the response's source File object BEFORE send, then disable
        // the auto-delete for the duration of the assertion.
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $ref = new \ReflectionObject($response->baseResponse);
        $fileProp = $ref->getProperty('file');
        $fileProp->setAccessible(true);
        /** @var \Symfony\Component\HttpFoundation\File\File $file */
        $file = $fileProp->getValue($response->baseResponse);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\File::class, $file);
        $this->assertFileExists($file->getPathname());

        $deleteProp = $ref->getProperty('deleteFileAfterSend');
        $deleteProp->setAccessible(true);
        $deleteProp->setValue($response->baseResponse, false);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file->getPathname()) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['august-letter/en/u1.html', 'august-letter/en/u2.html'], $names);

        $u1 = $zip->getFromName('august-letter/en/u1.html');
        $u2 = $zip->getFromName('august-letter/en/u2.html');
        $this->assertNotFalse($u1);
        $this->assertNotFalse($u2);
        $this->assertStringContainsString('Hi Ada', $u1);
        $this->assertStringContainsString('Hi Ben', $u2);

        $zip->close();

        // Restore deleteFileAfterSend(true) and explicitly send to confirm the controller's
        // promise: the on-disk temp zip must NOT survive a successful response.
        $deleteProp->setValue($response->baseResponse, true);
        $zipPath = $file->getPathname();
        ob_start();
        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($zipPath, 'the controller must delete its temp zip after streaming the response');
    }
}
