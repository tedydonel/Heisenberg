<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media;

use Heisenberg\Contracts\RoleGate;
use Heisenberg\Contracts\VirusScanner;
use Heisenberg\Http\Requests\UploadPublicFileRequest;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\MediaLibraryService;
use Heisenberg\Tests\Media\Fakes\FakeRoleGate;
use Heisenberg\Tests\Media\Fakes\FakeUser;
use Heisenberg\Tests\Media\Fakes\FakeVirusScanner;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Acceptance tests for the public media library backend
 * (docs/media-library-backend-blueprint.md §11.10). Every test boots a fresh
 * in-memory SQLite schema (a real `users` table + the package migrations, in
 * that order, so the guarded uploaded_by FK actually gets created — see
 * setUp()), fakes the `uploads` disk, and binds a fake RoleGate + VirusScanner
 * so authorization and the scan gate are fully test-controlled.
 */
class MediaLibraryTest extends TestCase
{
    private FakeRoleGate $roleGate;

    private FakeVirusScanner $scanner;

    private FakeUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A minimal real `users` table, created BEFORE the package migrations
        // run, so create_heisenberg_public_files_table's guarded
        // Schema::hasTable($usersTable) check sees it and adds the real
        // uploaded_by FK (nullOnDelete) — required for the "deleting a user
        // nulls uploaded_by" scenario to exercise actual DB behavior.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Artisan::call('migrate', ['--force' => true]);

        $this->roleGate = new FakeRoleGate();
        $this->scanner = new FakeVirusScanner();
        $this->app->instance(RoleGate::class, $this->roleGate);
        $this->app->instance(VirusScanner::class, $this->scanner);

        Storage::fake('uploads');

        $this->user = FakeUser::create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->actingAs($this->user);
    }

    // ── Upload pipeline ────────────────────────────────────────────────────

    public function test_image_upload_creates_row_physical_file_and_variants(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 1600, 1200),
            'alt_text_en' => 'A mountain',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('files.0.original_name', 'photo.jpg');

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertSame('photo.jpg', $file->original_name);
        $this->assertSame('photo.jpg', $file->stored_name);
        $this->assertSame('jpg', $file->type);
        $this->assertSame(1600, $file->width);
        $this->assertSame(1200, $file->height);
        $this->assertSame('A mountain', $file->alt_text_en);
        $this->assertSame($this->user->id, $file->uploaded_by);

        Storage::disk('uploads')->assertExists($file->stored_path);

        $this->assertArrayHasKey('small', $file->variants);
        $this->assertArrayHasKey('medium', $file->variants);
        Storage::disk('uploads')->assertExists($file->variants['small']['path']);
        Storage::disk('uploads')->assertExists($file->variants['medium']['path']);
        $this->assertSame(320, $file->variants['small']['width']);
        $this->assertSame(768, $file->variants['medium']['width']);
    }

    public function test_document_upload_has_no_dimensions_or_variants(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertSame('pdf', $file->type);
        $this->assertNull($file->width);
        $this->assertNull($file->height);
        $this->assertSame([], $file->variants);
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    public function test_duplicate_filenames_become_collision_free_and_original_name_is_preserved(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ])->assertCreated();

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ])->assertCreated();

        $files = PublicFile::orderBy('id')->get();
        $this->assertCount(2, $files);

        $this->assertSame('photo.jpg', $files[0]->original_name);
        $this->assertSame('photo.jpg', $files[0]->stored_name);
        $this->assertSame('photo.jpg', $files[1]->original_name, 'original_name must be preserved even when deduped');
        $this->assertSame('photo(1).jpg', $files[1]->stored_name);

        Storage::disk('uploads')->assertExists($files[0]->stored_path);
        Storage::disk('uploads')->assertExists($files[1]->stored_path);
        $this->assertNotSame($files[0]->stored_path, $files[1]->stored_path);

        // Variant names never clash either.
        Storage::disk('uploads')->assertExists($files[0]->variants['small']['path']);
        Storage::disk('uploads')->assertExists($files[1]->variants['small']['path']);
        $this->assertNotSame($files[0]->variants['small']['path'], $files[1]->variants['small']['path']);
        $this->assertStringContainsString('photo-small.jpg', $files[0]->variants['small']['path']);
        $this->assertStringContainsString('photo(1)-small.jpg', $files[1]->variants['small']['path']);
    }

    public function test_dots_only_filename_does_not_clobber_the_month_directory(): void
    {
        // A crafted, extension-less filename of ".." reduces (via PHP's
        // pathinfo() PATHINFO_FILENAME quirk) to a base name of ".". Without
        // sanitizeBaseName() guarding against dots-only names, the stored
        // filename becomes exactly "." — which Storage::putFileAs() joins
        // onto the destination dir as "media/2026/07/.", and Flysystem's
        // path normalizer collapses that trailing "." away, so the write
        // lands on "media/2026/07" itself (the directory's own path)
        // instead of a new file inside it. That clobbers the directory with
        // a regular file and breaks every subsequent upload for the month.
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create('..', 10, 'application/pdf'),
        ]);
        $response->assertCreated();

        $dir = 'media/' . now()->format('Y/m');
        $this->assertNotSame('.', PublicFile::first()->stored_name);
        $this->assertFalse(
            in_array($dir, Storage::disk('uploads')->allFiles(), true),
            'the month directory path itself must never be the stored file'
        );

        // The month directory must still be usable for a normal upload.
        $second = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf'),
        ]);
        $second->assertCreated();
        Storage::disk('uploads')->assertExists("{$dir}/brochure.pdf");
    }

    public function test_disallowed_mime_is_rejected(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    // ── Extension allow-list enforced at the SERVICE layer ──────────────────
    //
    // The allow-list used to live ONLY in UploadPublicFileRequest's `mimes:`
    // rule, so any caller reaching MediaLibraryService::storeOne() directly
    // (or through a path with no FormRequest, e.g. the Livewire upload
    // handler — see MediaLibraryLivewireTest) could store an arbitrary file
    // type, including an .svg carrying an inline <script> later served back
    // from this app's own origin with its real image/svg+xml mime type. These
    // tests call the service directly, bypassing UploadPublicFileRequest
    // entirely, to prove the allow-list holds without it.

    public function test_service_layer_rejects_a_disallowed_extension_with_no_form_request_involved(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'xss.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>',
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(MediaLibraryService::class)->storeOne($svg);
    }

    public function test_service_layer_extension_rejection_holds_regardless_of_environment(): void
    {
        // A local dev session (see LocalDevRoleGate) only ever bypasses
        // AUTHORIZATION — never the extension allow-list. Simulate the most
        // permissive possible dev session and confirm the .svg is still
        // rejected and nothing reaches disk.
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => true]);

        $svg = UploadedFile::fake()->createWithContent(
            'xss.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>',
        );

        try {
            app(MediaLibraryService::class)->storeOne($svg);
            $this->fail('Expected storeOne() to reject a disallowed .svg extension.');
        } catch (\Illuminate\Validation\ValidationException) {
            // expected
        }

        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_oversized_file_is_rejected(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create('big.jpg', PublicFile::MAX_KB + 1, 'image/jpeg'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, PublicFile::count());
    }

    public function test_infected_file_is_rejected_before_any_disk_write(): void
    {
        $this->scanner->result = 'infected';

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('malware.jpg', 100, 100),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles(), 'no bytes may reach disk before the scan passes');
    }

    public function test_scanner_unavailable_fails_closed_by_default(): void
    {
        $this->scanner->result = 'unavailable';

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_scanner_unavailable_fails_open_when_configured(): void
    {
        config(['heisenberg.media.scan.fail_open' => true]);
        $this->scanner->result = 'unavailable';

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $response->assertCreated();
        $this->assertSame(1, PublicFile::count());
    }

    public function test_variant_failure_mid_loop_does_not_leave_an_orphaned_earlier_variant_on_disk(): void
    {
        // Decorate the faked `uploads` disk so writes to the *medium* variant
        // path throw, while the *small* variant (processed first — see
        // PublicFile::VARIANTS order) and the original file both still
        // succeed. This reproduces MediaLibraryService::variants() failing
        // partway through its loop: the small variant's bytes are written to
        // disk BEFORE the medium write throws.
        $existing = Storage::disk('uploads');
        $decorated = new class($existing->getDriver(), $existing->getAdapter(), $existing->getConfig())
            extends \Illuminate\Filesystem\LocalFilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                if (is_string($path) && str_contains($path, '-medium')) {
                    throw new \RuntimeException('Simulated write failure for the medium variant.');
                }

                return parent::put($path, $contents, $options);
            }
        };
        Storage::set('uploads', $decorated);

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ]);

        // The upload as a whole must still succeed — variant generation
        // failure never fails the upload (blueprint §6).
        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertSame([], $file->variants, 'a mid-loop variant failure must fall back to no variants at all');

        Storage::disk('uploads')->assertExists($file->stored_path);

        // The small variant's bytes were written before the medium write
        // threw. They must have been cleaned up, not left as an orphan that
        // no DB row ever references.
        $dir = 'media/' . now()->format('Y/m');
        $base = pathinfo($file->stored_name, PATHINFO_FILENAME);
        Storage::disk('uploads')->assertMissing("{$dir}/{$base}-small.jpg");
        Storage::disk('uploads')->assertMissing("{$dir}/{$base}-medium.jpg");
    }

    public function test_multi_file_upload_creates_a_row_and_physical_file_per_file(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'files' => [
                UploadedFile::fake()->image('one.jpg', 100, 100),
                UploadedFile::fake()->image('two.png', 100, 100),
                UploadedFile::fake()->create('three.pdf', 10, 'application/pdf'),
            ],
        ]);

        $response->assertCreated();
        $this->assertCount(3, $response->json('files'));

        $files = PublicFile::orderBy('id')->get();
        $this->assertCount(3, $files);
        $this->assertSame(['one.jpg', 'two.png', 'three.pdf'], $files->pluck('original_name')->all());

        foreach ($files as $file) {
            Storage::disk('uploads')->assertExists($file->stored_path);
        }
    }

    // ── Decompression-bomb guard (max_megapixels) ───────────────────────────

    public function test_image_over_the_megapixel_cap_is_stored_with_no_variants(): void
    {
        // 0.05 megapixels = 50,000px. A 300x300 fake image is 90,000px — over
        // the cap — while staying cheap to generate in-test (no need to build
        // an actual multi-hundred-megapixel bomb to exercise the guard).
        config(['heisenberg.media.max_megapixels' => 0.05]);

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('bomb.jpg', 300, 300),
        ]);

        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertSame(300, $file->width);
        $this->assertSame(300, $file->height);
        $this->assertSame([], $file->variants, 'an over-cap image must be stored with no variants generated');
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    public function test_image_under_the_megapixel_cap_still_gets_variants(): void
    {
        config(['heisenberg.media.max_megapixels' => 0.05]); // 50,000px cap

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('normal.jpg', 100, 100), // 10,000px
        ]);

        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertArrayHasKey('small', $file->variants);
        $this->assertArrayHasKey('medium', $file->variants);
        Storage::disk('uploads')->assertExists($file->variants['small']['path']);
    }

    // ── Filename sanitization robustness ────────────────────────────────────

    public function test_windows_illegal_and_control_characters_are_sanitized_out_of_the_stored_name(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create("report: q1 * \"final\"? <draft>|v1.pdf", 10, 'application/pdf'),
        ]);

        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        // original_name preserves exactly what the user uploaded...
        $this->assertSame("report: q1 * \"final\"? <draft>|v1.pdf", $file->original_name);
        // ...but the name actually written to disk must be clean.
        foreach ([':', '*', '"', '<', '>', '|', '?'] as $illegal) {
            $this->assertStringNotContainsString($illegal, $file->stored_name);
        }
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    public function test_absurdly_long_filename_is_truncated_to_a_sane_length(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create(str_repeat('a', 400) . '.pdf', 10, 'application/pdf'),
        ]);

        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        // 200-char base cap + ".pdf" — comfortably under filesystem limits.
        $this->assertLessThanOrEqual(204, strlen($file->stored_name));
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    // ── Path separator validation ────────────────────────────────────────────
    //
    // Symfony\Component\HttpFoundation\File\File::getName() unconditionally
    // reduces getClientOriginalName() to a basename (everything after the
    // last '/' or '\\') INSIDE UploadedFile's own constructor — for both a
    // real multipart HTTP upload and UploadedFile::fake(). That means a
    // hostile "sub/evil.pdf" (or "sub\\evil.pdf") filename can never
    // actually reach noPathSeparatorsRule containing a separator: it is
    // silently basenamed to "evil.pdf" before validation ever runs, and the
    // upload succeeds. The rule is still correct, load-bearing
    // defense-in-depth (nothing guarantees every future caller of
    // UploadPublicFileRequest hands it a stock Symfony-constructed
    // UploadedFile) — so it is exercised directly below, plus one test that
    // documents the real, safe end-to-end behavior.

    public function test_no_path_separators_rule_rejects_a_name_containing_a_separator(): void
    {
        $request = new UploadPublicFileRequest();
        $method = new \ReflectionMethod($request, 'noPathSeparatorsRule');
        $method->setAccessible(true);
        /** @var \Closure $rule */
        $rule = $method->invoke($request);

        $tmpPath = tempnam(sys_get_temp_dir(), 'hb_media_test_');
        $this->assertNotFalse($tmpPath);

        try {
            foreach (['sub/evil.pdf', 'sub\\evil.pdf'] as $hostileName) {
                $file = new class($tmpPath, $hostileName) extends UploadedFile {
                    public function __construct(string $path, private readonly string $hostileName)
                    {
                        parent::__construct($path, 'benign.pdf', 'application/pdf', null, true);
                    }

                    public function getClientOriginalName(): string
                    {
                        return $this->hostileName;
                    }
                };

                $failed = null;
                $rule('file', $file, function (string $message) use (&$failed): void {
                    $failed = $message;
                });

                $this->assertNotNull($failed, "expected the rule to reject a name containing a separator: {$hostileName}");
            }
        } finally {
            @unlink($tmpPath);
        }
    }

    public function test_http_upload_with_a_slash_in_the_filename_is_basenamed_before_validation_runs(): void
    {
        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->create('sub/evil.pdf', 10, 'application/pdf'),
        ]);

        // Framework-level basenaming means this is a normal, successful
        // upload — not a 422 — because the separator never survives into
        // getClientOriginalName().
        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertStringNotContainsString('/', $file->original_name);
        $this->assertStringNotContainsString('/', $file->stored_name);
    }

    // ── Security / audit logging ─────────────────────────────────────────────

    public function test_infected_scan_result_logs_a_security_warning(): void
    {
        Log::spy();
        $this->scanner->result = 'infected';

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('malware.jpg', 100, 100),
        ])->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'heisenberg: media upload rejected by virus scan'
                && ($context['result'] ?? null) === 'infected'
                && ($context['filename'] ?? null) === 'malware.jpg');
    }

    public function test_scanner_unavailable_fail_closed_logs_a_security_warning(): void
    {
        Log::spy();
        $this->scanner->result = 'unavailable';

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'heisenberg: media upload rejected by virus scan'
                && ($context['result'] ?? null) === 'unavailable');
    }

    // ── Users / soft delete ────────────────────────────────────────────────

    public function test_deleting_a_user_nulls_uploaded_by_without_deleting_the_media_row(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();

        $file = PublicFile::first();
        $this->assertSame($this->user->id, $file->uploaded_by);

        $this->user->delete();

        $file->refresh();
        $this->assertNull($file->uploaded_by);
        $this->assertNotNull(PublicFile::find($file->id), 'the media row itself must survive the user deletion');
    }

    // ── Authorization ───────────────────────────────────────────────────────

    public function test_index_requires_view_any_ability(): void
    {
        $this->roleGate->abilities['media.viewAny'] = false;

        $this->getJson(route('media.index'))->assertForbidden();
    }

    public function test_upload_denied_without_create_ability(): void
    {
        $this->roleGate->abilities['media.create'] = false;

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $response->assertForbidden();
        $this->assertSame(0, PublicFile::count());
    }

    public function test_true_guest_cannot_upload(): void
    {
        // Not merely an ability-flag denial (FakeRoleGate would never even be
        // consulted for a genuinely unauthenticated request) — a real guest,
        // no acting-as user at all.
        $this->actingAsGuest();

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $response->assertForbidden();
        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_true_guest_cannot_delete(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();
        $file = PublicFile::first();

        $this->actingAsGuest();

        $response = $this->deleteJson(route('media.destroy', ['file' => $file->id]));

        $response->assertForbidden();
        $this->assertNotNull(PublicFile::find($file->id));
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    public function test_unauthorized_actor_cannot_delete(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();
        $file = PublicFile::first();

        $this->roleGate->abilities['media.deleteAny'] = false;

        $response = $this->deleteJson(route('media.destroy', ['file' => $file->id]));

        $response->assertForbidden();
        $this->assertNotNull(PublicFile::find($file->id));
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    public function test_update_allows_the_original_uploader_even_without_update_any_ability(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();
        $file = PublicFile::first();

        $this->roleGate->abilities['media.updateAny'] = false;

        $response = $this->putJson(route('media.update', ['file' => $file->id]), [
            'alt_text_en' => 'Owner edit',
        ]);

        $response->assertOk();
        $this->assertSame('Owner edit', $file->fresh()->alt_text_en);
    }

    public function test_update_denied_for_a_non_owner_without_update_any_ability(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();
        $file = PublicFile::first();

        $this->roleGate->abilities['media.updateAny'] = false;
        $this->actingAs(FakeUser::create(['name' => 'Other', 'email' => 'other@example.test']));

        $response = $this->putJson(route('media.update', ['file' => $file->id]), [
            'alt_text_en' => 'Should not apply',
        ]);

        $response->assertForbidden();
        $this->assertNotSame('Should not apply', $file->fresh()->alt_text_en);
    }

    // ── Delete pipeline ─────────────────────────────────────────────────────

    public function test_delete_removes_original_and_variant_bytes_and_soft_deletes_the_row(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ])->assertCreated();

        $file = PublicFile::first();
        $originalPath = $file->stored_path;
        $smallPath = $file->variants['small']['path'];
        $mediumPath = $file->variants['medium']['path'];

        Storage::disk('uploads')->assertExists($originalPath);
        Storage::disk('uploads')->assertExists($smallPath);
        Storage::disk('uploads')->assertExists($mediumPath);

        $response = $this->deleteJson(route('media.destroy', ['file' => $file->id]));
        $response->assertOk();
        $this->assertTrue((bool) $response->json('deleted'));

        Storage::disk('uploads')->assertMissing($originalPath);
        Storage::disk('uploads')->assertMissing($smallPath);
        Storage::disk('uploads')->assertMissing($mediumPath);

        $this->assertNull(PublicFile::find($file->id), 'soft-deleted rows are excluded by the default scope');
        $this->assertNotNull(PublicFile::withTrashed()->find($file->id), 'the record itself is retained for audit/relink safety');
    }

    // ── select / update payload shape ───────────────────────────────────────

    public function test_select_returns_the_full_picker_payload(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            'alt_text_en' => 'Alt EN',
            'alt_text_fr' => 'Alt FR',
        ])->assertCreated();

        $response = $this->getJson(route('media.select'));
        $response->assertOk();

        $payload = $response->json('files.0');
        foreach ([
            'id', 'url', 'thumbnail_url', 'medium_url', 'large_url', 'srcset', 'sizes', 'variants',
            'original_name', 'stored_name', 'alt_text_en', 'alt_text_fr', 'width', 'height', 'human_size',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload, "select payload is missing key: {$key}");
        }

        $this->assertSame('Alt EN', $payload['alt_text_en']);
        $this->assertSame('Alt FR', $payload['alt_text_fr']);
        $this->assertArrayHasKey('next_page_url', $response->json());
    }

    public function test_update_only_mutates_meta_fields(): void
    {
        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ])->assertCreated();

        $file = PublicFile::first();
        $originalStoredPath = $file->stored_path;
        $originalDisk = $file->disk;
        $originalMime = $file->mime_type;
        $originalVariants = $file->variants;

        $response = $this->putJson(route('media.update', ['file' => $file->id]), [
            'alt_text_en' => 'Updated alt',
            'caption_fr' => 'Légende',
            'credit' => 'Photographer X',
            // Attempted mass-assignment of binary/path fields — must be ignored.
            'stored_path' => 'media/hacked/evil.jpg',
            'disk' => 's3',
            'mime_type' => 'application/x-evil',
            'variants' => ['small' => ['path' => 'evil.jpg']],
        ]);

        $response->assertOk();

        $file->refresh();
        $this->assertSame('Updated alt', $file->alt_text_en);
        $this->assertSame('Légende', $file->caption_fr);
        $this->assertSame('Photographer X', $file->credit);

        $this->assertSame($originalStoredPath, $file->stored_path);
        $this->assertSame($originalDisk, $file->disk);
        $this->assertSame($originalMime, $file->mime_type);
        $this->assertSame($originalVariants, $file->variants);
    }

    // ── Model API (§7) ──────────────────────────────────────────────────────

    public function test_model_url_thumbnail_medium_large_and_srcset(): void
    {
        $file = PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/photo.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'width' => 1600,
            'height' => 1200,
            'variants' => [
                'small' => ['path' => 'media/2026/07/photo-small.jpg', 'width' => 320, 'height' => 240, 'size_bytes' => 18000],
                'medium' => ['path' => 'media/2026/07/photo-medium.jpg', 'width' => 768, 'height' => 576, 'size_bytes' => 60000],
            ],
        ]);

        $this->assertSame('/uploads/media/2026/07/photo.jpg', $file->url);
        $this->assertSame('/uploads/media/2026/07/photo-small.jpg', $file->thumbnail_url);
        $this->assertSame($file->thumbnail_url, $file->small_url);
        $this->assertSame('/uploads/media/2026/07/photo-medium.jpg', $file->medium_url);
        $this->assertSame($file->url, $file->large_url);

        $srcset = $file->srcset();
        $this->assertNotNull($srcset);
        $this->assertStringContainsString('/uploads/media/2026/07/photo-small.jpg 320w', $srcset);
        $this->assertStringContainsString('/uploads/media/2026/07/photo-medium.jpg 768w', $srcset);
        $this->assertStringContainsString('/uploads/media/2026/07/photo.jpg 1600w', $srcset);
    }

    public function test_model_url_encodes_each_path_segment(): void
    {
        $file = PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/my photo (1).jpg',
            'original_name' => 'my photo (1).jpg',
            'stored_name' => 'my photo (1).jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);

        $this->assertSame('/uploads/media/2026/07/my%20photo%20%281%29.jpg', $file->url);
    }

    public function test_model_image_payload_and_non_image_srcset_is_null(): void
    {
        $image = PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/a.jpg',
            'original_name' => 'a.jpg',
            'stored_name' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'width' => 800,
            'height' => 600,
        ]);

        $payload = $image->imagePayload('hero');
        $this->assertSame($image->url, $payload['url']);
        $this->assertSame('100vw', $payload['sizes']);

        $doc = PublicFile::create([
            'type' => 'pdf',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/doc.pdf',
            'original_name' => 'doc.pdf',
            'stored_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ]);

        $this->assertNull($doc->srcset());
        $this->assertNull($doc->imagePayload()['sizes']);
    }

    public function test_model_get_alt_falls_back_across_locales(): void
    {
        $enOnly = PublicFile::create([
            'type' => 'jpg', 'disk' => 'uploads', 'stored_path' => 'a.jpg',
            'original_name' => 'a.jpg', 'stored_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'alt_text_en' => 'English alt',
        ]);
        $this->assertSame('English alt', $enOnly->getAlt('en'));
        $this->assertSame('English alt', $enOnly->getAlt('fr')); // falls back

        $frOnly = PublicFile::create([
            'type' => 'jpg', 'disk' => 'uploads', 'stored_path' => 'b.jpg',
            'original_name' => 'b.jpg', 'stored_name' => 'b.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'alt_text_fr' => 'Texte français',
        ]);
        $this->assertSame('Texte français', $frOnly->getAlt('fr'));
        $this->assertSame('Texte français', $frOnly->getAlt('en')); // falls back

        $neither = PublicFile::create([
            'type' => 'jpg', 'disk' => 'uploads', 'stored_path' => 'c.jpg',
            'original_name' => 'c.jpg', 'stored_name' => 'c.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
        ]);
        $this->assertSame('', $neither->getAlt('en'));
    }

    public function test_model_human_size(): void
    {
        $file = PublicFile::create([
            'type' => 'jpg', 'disk' => 'uploads', 'stored_path' => 'a.jpg',
            'original_name' => 'a.jpg', 'stored_name' => 'a.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 204800, // exactly 200 KB
        ]);

        $this->assertSame('200.0 KB', $file->human_size);
        $this->assertSame('512 B', PublicFile::formatBytes(512));
    }

    public function test_model_reverse_lookup_resolves_url_back_to_the_record(): void
    {
        $file = PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/photo.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'variants' => [
                'small' => ['path' => 'media/2026/07/photo-small.jpg', 'width' => 320, 'height' => 240, 'size_bytes' => 100],
            ],
        ]);

        $found = PublicFile::forUrl('/uploads/media/2026/07/photo.jpg');
        $this->assertNotNull($found);
        $this->assertSame($file->id, $found->id);

        $foundVariant = PublicFile::forUrl('/uploads/media/2026/07/photo-small.jpg');
        $this->assertNotNull($foundVariant);
        $this->assertSame($file->id, $foundVariant->id);

        $this->assertNull(PublicFile::forUrl('https://example.com/not-uploads/x.jpg'));
        $this->assertSame('media/2026/07/photo.jpg', PublicFile::storedPathFromUrl('/uploads/media/2026/07/photo.jpg'));
    }
}
