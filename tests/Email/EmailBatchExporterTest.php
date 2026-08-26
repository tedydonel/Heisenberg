<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailBatchExporter;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailBatchExportResult;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\EmailVariableResolutionException;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ZipArchive;

/**
 * docs/email-system.md §6 (and .hermes/plans/2026-08-25_190059-email-template-variables.md
 * Task 6): the admin-only batch file-factory. Host-supplied recipient value maps × requested
 * locales → one zip on disk; sending those files is the host's mailer, never this package.
 *
 * Vertical-slice TDD: every batch-export contract the plan pins — cap, empty, format whitelist,
 * locale whitelist/default, recipient id shape, duplicate ids, missing translation, persisted
 * translation requirement, all-or-nothing zip, no recipient-value leakage in the DTO,
 * cleanup on failure, HTML preview parity, EML CID + From parity — gets its own test below.
 */
class EmailBatchExporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/email-batch-*.zip')) ?: [] as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function exporter(): EmailBatchExporter
    {
        return $this->app->make(EmailBatchExporter::class);
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

    /**
     * Published, email-typed post with a `{{ user.first_name }}` token in the
     * subject and a heading, used by the personalisation tests below.
     */
    private function makePublishedEmail(string $slug = 'august-newsletter', string $subject = 'Hello {{ user.first_name }}'): Post
    {
        $post = Post::create([
            'title_en' => $subject,
            'title_fr' => 'Bonjour {{ user.first_name }}',
            'locale' => 'en',
            'status' => 'published',
            'slug' => $slug,
        ]);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/heading', [
            'content' => 'Hi {{ user.first_name }}',
            'content_fr' => 'Salut {{ user.first_name }}',
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

    // ────────────────────────────────────────────────────────────────────
    // 1. Format / strict validation
    // ────────────────────────────────────────────────────────────────────

    public function test_format_html_is_accepted(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $this->assertInstanceOf(EmailBatchExportResult::class, $result);
        $this->assertFileExists($result->path);
    }

    public function test_format_eml_is_accepted(): void
    {
        config(['mail.from.address' => 'sender@example.test']);
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'eml',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $this->assertFileExists($result->path);
    }

    public function test_unknown_format_is_rejected_deterministically(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'pdf',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // 2. Locales: default to all configured; reject invalid; missing translation fails
    // ────────────────────────────────────────────────────────────────────

    public function test_default_locales_are_all_locale_config_locales(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $this->assertSame(['en', 'fr'], $result->locales);
        $this->assertSame(2, $result->fileCount);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['zz'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);
    }

    public function test_missing_translation_for_a_requested_locale_aggregates_a_failure(): void
    {
        // Single-row Post model: every persisted email has one `locale` (the locale that row was
        // saved for, default `en`). The plan's "persisted translation required per locale, no
        // mislabeled fallback" rule means: if the admin requests a locale that is NOT this row's
        // own, the exporter must refuse to ship the en row's body labeled as the requested
        // locale. The exporter aggregates that as a resolution-style failure so the caller sees
        // the same shape every other row-level failure uses — no partial zip, no silent fallback.
        $post = $this->makePublishedEmail();
        $post->title_fr = '';
        $post->save();
        $this->registerStandardVariables();

        try {
            $this->exporter()->export($post, [
                'format' => 'html',
                'locales' => ['fr'],
                'recipients' => [
                    ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
                ],
            ]);
            $this->fail('expected aggregated failure for the untranslated locale pair');
        } catch (\Heisenberg\Support\EmailBatchTranslationMissingException $e) {
            $this->assertSame(['fr'], $e->locales);
            $this->assertSame('en', $e->postLocale);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // 3. Recipient list: empty, cap, id shape, duplicate ids
    // ────────────────────────────────────────────────────────────────────

    public function test_empty_recipient_list_is_rejected(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [],
        ]);
    }

    public function test_recipients_over_the_configured_cap_are_rejected(): void
    {
        config(['heisenberg.email.batch_max_recipients' => 2]);
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'A', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u2', 'values' => ['user.first_name' => 'B', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u3', 'values' => ['user.first_name' => 'C', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);
    }

    public function test_recipient_id_must_be_filename_safe(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'has spaces', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);
    }

    public function test_recipient_id_must_be_unique_within_a_batch(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // 4. Values map: every key must be registered, no extras
    // ────────────────────────────────────────────────────────────────────

    public function test_recipient_values_keys_must_be_registered_variables(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        // `user.unknown` is not registered — the strict map check must reject it.
        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
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
    }

    public function test_recipient_values_map_must_be_a_flat_map_not_nested(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => [
                    'user' => ['first_name' => 'Ada'],
                ]],
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // 5. Layout: slug/locale/id.{html|eml} per recipient
    // ────────────────────────────────────────────────────────────────────

    public function test_zip_layout_is_slug_locale_id_format_for_every_recipient_and_locale(): void
    {
        $post = $this->makePublishedEmail('august-letter');
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en', 'fr'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $this->assertSame(4, $result->fileCount, 'N×locales (2×2) = 4 files');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($result->path) === true, 'zip opens');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        $this->assertSame([
            'august-letter/en/u1.html',
            'august-letter/en/u2.html',
            'august-letter/fr/u1.html',
            'august-letter/fr/u2.html',
        ], $names);

        $zip->close();
    }

    public function test_one_export_call_uses_each_persisted_locale_from_the_same_post(): void
    {
        $post = $this->makePublishedEmail('bilingual', 'Hello {{ user.first_name }}');
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en', 'fr'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u']],
                ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://e.test/u']],
            ],
        ]);

        $zip = new ZipArchive();
        $zip->open($result->path);
        $this->assertStringContainsString('Hi Ada', $zip->getFromName('bilingual/en/u1.html'));
        $this->assertStringContainsString('Salut Ada', $zip->getFromName('bilingual/fr/u1.html'));
        $this->assertStringContainsString('<title>Hello Ada</title>', $zip->getFromName('bilingual/en/u1.html'));
        $this->assertStringContainsString('<title>Bonjour Ada</title>', $zip->getFromName('bilingual/fr/u1.html'));
        $zip->close();
    }

    public function test_custom_formatter_value_objects_are_allowed_in_the_flat_runtime_map(): void
    {
        $post = $this->makePublishedEmail('money-letter', 'Balance {{ account.balance }}');
        $this->registerStandardVariables();
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->registerType(new BatchMoneyEmailVariableType());
        $registry->register(new EmailVariableDefinition(
            key: 'account.balance',
            label: 'Balance',
            type: 'batch_money',
            sample: new BatchMoney(1000),
        ));

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [['id' => 'u1', 'values' => [
                'account.balance' => new BatchMoney(250000),
                'user.first_name' => 'Ada',
                'unsubscribe_url' => 'https://e.test/u1',
            ]]],
        ]);

        $zip = new ZipArchive();
        $zip->open($result->path);
        $this->assertStringContainsString('Balance NGN 2,500.00', $zip->getFromName('money-letter/en/u1.html'));
        $zip->close();
    }

    public function test_resolution_failures_are_aggregated_across_every_recipient_and_locale(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        try {
            $this->exporter()->export($post, [
                'format' => 'html',
                'locales' => ['en', 'fr'],
                'recipients' => [
                    ['id' => 'u1', 'values' => ['unsubscribe_url' => 'https://e.test/u1']],
                    ['id' => 'u2', 'values' => ['unsubscribe_url' => 'https://e.test/u2']],
                ],
            ]);
            $this->fail('expected aggregate failure');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame([
                'u1/en/user.first_name',
                'u2/en/user.first_name',
                'u1/fr/user.first_name',
                'u2/fr/user.first_name',
            ], $e->getKeys());
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // 6. Per-recipient content: each file gets the runtime-substituted values
    // ────────────────────────────────────────────────────────────────────

    public function test_two_recipients_produce_two_distinct_personalised_files(): void
    {
        $post = $this->makePublishedEmail('august-letter');
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u1']],
                ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://e.test/u2']],
            ],
        ]);

        $zip = new ZipArchive();
        $zip->open($result->path);

        $this->assertStringContainsString('Hi Ada', $zip->getFromName('august-letter/en/u1.html'));
        $this->assertStringContainsString('Hi Ben', $zip->getFromName('august-letter/en/u2.html'));
        $this->assertStringNotContainsString('Hi Ada', $zip->getFromName('august-letter/en/u2.html'));
        $this->assertStringNotContainsString('Hi Ben', $zip->getFromName('august-letter/en/u1.html'));
        $this->assertStringNotContainsString('{{ user.first_name }}', $zip->getFromName('august-letter/en/u1.html'));
        $this->assertStringNotContainsString('{{ user.first_name }}', $zip->getFromName('august-letter/en/u2.html'));

        $zip->close();
    }

    // ────────────────────────────────────────────────────────────────────
    // 7. EML: CID embed + configured From header parity
    // ────────────────────────────────────────────────────────────────────

    public function test_eml_zip_uses_cid_embeds_and_configured_from_header(): void
    {
        config(['mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Sender Name']);

        // Put a real PublicFile-backed image so the renderer attaches at least one cid embed.
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
        Storage::disk('uploads')->put('media/2026/07/photo.jpg', $bytes);
        \Heisenberg\Models\PublicFile::create([
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

        $post = $this->makePublishedEmail('august-letter');
        $this->addBlock($post, 2, 'heisenberg/image', [
            'url' => '/uploads/media/2026/07/photo.jpg',
            'alt' => 'A photo',
        ]);
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'eml',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u1']],
            ],
        ]);

        $zip = new ZipArchive();
        $zip->open($result->path);
        $eml = $zip->getFromName('august-letter/en/u1.eml');

        $this->assertStringContainsString('From: Sender Name <sender@example.test>', $eml);
        $this->assertStringContainsString('Subject: Hello Ada', $eml);
        // cid: reference reaches the HTML
        $this->assertMatchesRegularExpression('/cid:[^"\']+/', $eml);
        $zip->close();
    }

    // ────────────────────────────────────────────────────────────────────
    // 8. DTO carries path/counts/locales only — no recipient values
    // ────────────────────────────────────────────────────────────────────

    public function test_result_dto_carries_path_file_count_recipient_count_and_locales_only(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        $result = $this->exporter()->export($post, [
            'format' => 'html',
            'locales' => ['en'],
            'recipients' => [
                ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u1']],
            ],
        ]);

        $r = (array) $result;
        // Public properties are exactly path, fileCount, recipientCount, locales — no values, no zip bytes.
        $this->assertSame(['path', 'fileCount', 'recipientCount', 'locales'], array_keys($r));
        $this->assertSame(1, $result->fileCount);
        $this->assertSame(1, $result->recipientCount);
        $this->assertSame(['en'], $result->locales);
        $this->assertFileExists($result->path);
        $this->assertStringNotContainsString('Ada', $result->path);
    }

    // ────────────────────────────────────────────────────────────────────
    // 9. All-or-nothing: a per-recipient failure leaves NO zip on disk
    // ────────────────────────────────────────────────────────────────────

    public function test_a_single_recipient_failure_produces_no_partial_zip_and_cleans_up(): void
    {
        $post = $this->makePublishedEmail();
        $this->registerStandardVariables();

        // The first recipient resolves cleanly; the second is missing `user.first_name`. The
        // exporter must aggregate the failure, throw, and leave NO zip on disk (the planned
        // "all-or-nothing, so an admin never ships a truncated campaign").
        $storage = Storage::disk('local')->path('');

        try {
            $this->exporter()->export($post, [
                'format' => 'html',
                'locales' => ['en'],
                'recipients' => [
                    ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://e.test/u1']],
                    ['id' => 'u2', 'values' => ['unsubscribe_url' => 'https://e.test/u2']],
                ],
            ]);
            $this->fail('expected aggregated failure');
        } catch (EmailVariableResolutionException) {
            // expected
        }

        $leftover = glob($storage . '/email-batch-*.zip') ?: [];
        $this->assertSame([], $leftover, 'no partial zip must remain on disk after a failure');
    }
}

final class BatchMoney
{
    public function __construct(public readonly int $minorUnits)
    {
    }
}

final class BatchMoneyEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'batch_money';
    }

    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (! $value instanceof BatchMoney) {
            throw new InvalidArgumentException('Expected BatchMoney.');
        }

        return 'NGN ' . number_format($value->minorUnits / 100, 2, '.', ',');
    }
}
