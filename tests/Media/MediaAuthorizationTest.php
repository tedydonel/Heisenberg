<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media;

use Heisenberg\Models\PublicFile;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The authorization matrix for the public media HTTP API (routes/media.php +
 * MediaLibraryController + PublicFilePolicy), pinned against the REAL
 * ConfigRoleGate driven by config('heisenberg.roles') — not FakeRoleGate (see
 * MediaLibraryTest, which fakes the gate to test the upload/delete PIPELINE
 * in isolation). This class exists to prove the role wiring itself:
 *
 *  - canonical roles admin/editor/author/viewer resolve through the
 *    admins/editors/authors tiers and the media.* ability lists exactly as
 *    config/heisenberg.php declares them;
 *  - the GuestActor + LocalDevRoleGate seam (PublicFilePolicy, Media
 *    LibraryController::authorize(), UploadPublicFileRequest::authorize())
 *    only ever opens up in a real 'local' environment, and only while
 *    heisenberg.allow_anonymous_in_local stays true;
 *  - ownership (the "original uploader may edit their own file" rule in
 *    PublicFilePolicy::update()) never matches an anonymous GuestActor, even
 *    when the bypass itself is what let the request through.
 *
 * No real `users` table is created (unlike MediaLibraryTest) — none of these
 * tests exercise the uploaded_by->users FK, only the policy's own ownership
 * comparison against a plain int id carried by FakeActor, so a bare
 * RefreshDatabase (package migrations only) is enough.
 */
class MediaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');

        // Every test below either stays in the default 'testing' env (where
        // Laravel's CSRF middleware auto-skips via runningUnitTests()) or
        // flips to 'local' to exercise the dev bypass — and flipping the env
        // turns that auto-skip off (see MediaLibraryLivewireTest's
        // simulateLocalDevSession() docblock). Disabling it unconditionally
        // here, the same way CategoryControllerTest does, keeps every test
        // method free to switch environments without re-deriving this.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function actingAsRole(int $id, string $role): FakeActor
    {
        $actor = new FakeActor($id, $role);
        $this->actingAs($actor);

        return $actor;
    }

    private function createFile(?int $uploadedBy = null): PublicFile
    {
        return PublicFile::create([
            'uploaded_by' => $uploadedBy,
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/owned-' . uniqid('', true) . '.jpg',
            'original_name' => 'owned.jpg',
            'stored_name' => 'owned.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
    }

    // ── 1. Guest, testing env: denied everything ────────────────────────

    public function test_guest_in_testing_env_is_denied_everything(): void
    {
        $this->getJson(route('media.index'))->assertForbidden();

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertForbidden();

        $this->getJson(route('media.select'))->assertForbidden();

        $this->assertSame(0, PublicFile::count());
    }

    // ── 2. Guest, local env: allowed (fresh-local-install experience) ────

    public function test_guest_in_local_env_is_allowed(): void
    {
        $this->app['env'] = 'local';

        $this->getJson(route('media.select'))->assertOk();

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);
        $response->assertCreated();

        $file = PublicFile::first();
        $this->assertNotNull($file);
        $this->assertNull($file->uploaded_by, 'an anonymous local-dev upload must not be attributed to any user id');
    }

    // ── 3. Guest, local env, bypass config off: denied ───────────────────

    public function test_guest_in_local_env_with_bypass_disabled_is_denied(): void
    {
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => false]);

        $this->getJson(route('media.select'))->assertForbidden();

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertForbidden();

        $this->assertSame(0, PublicFile::count());
    }

    // ── 4. viewer: read-only ──────────────────────────────────────────────

    public function test_viewer_can_view_but_cannot_write(): void
    {
        $this->actingAsRole(1, 'viewer');

        $this->getJson(route('media.index'))->assertOk();
        $this->getJson(route('media.select'))->assertOk();

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertForbidden();

        $file = $this->createFile(2);

        $this->putJson(route('media.update', ['file' => $file->id]), ['alt_text_en' => 'nope'])
            ->assertForbidden();
        $this->deleteJson(route('media.destroy', ['file' => $file->id]))->assertForbidden();

        $this->assertNotNull(PublicFile::find($file->id));
    }

    // ── 5. author: create + own-file metadata edit, nothing more ──────────

    public function test_author_can_upload_and_edit_only_their_own_file_but_never_delete(): void
    {
        $author = $this->actingAsRole(10, 'author');

        $response = $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);
        $response->assertCreated();
        $this->assertSame($author->id, PublicFile::first()->uploaded_by);

        $own = $this->createFile($author->id);
        $this->putJson(route('media.update', ['file' => $own->id]), ['alt_text_en' => 'mine'])
            ->assertOk();
        $this->assertSame('mine', $own->fresh()->alt_text_en);

        $someoneElses = $this->createFile(999);
        $this->putJson(route('media.update', ['file' => $someoneElses->id]), ['alt_text_en' => 'not mine'])
            ->assertForbidden();
        $this->assertNotSame('not mine', $someoneElses->fresh()->alt_text_en);

        // Ownership grants metadata edit rights only — never delete, even for
        // a file the author uploaded themselves.
        $this->deleteJson(route('media.destroy', ['file' => $own->id]))->assertForbidden();
        $this->assertNotNull(PublicFile::find($own->id));
    }

    // ── 6. editor: manages everyone's files ────────────────────────────────

    public function test_editor_can_update_and_delete_anyones_file(): void
    {
        $this->actingAsRole(20, 'editor');

        $file = $this->createFile(999);

        $this->putJson(route('media.update', ['file' => $file->id]), ['alt_text_en' => 'edited'])
            ->assertOk();
        $this->assertSame('edited', $file->fresh()->alt_text_en);

        $this->deleteJson(route('media.destroy', ['file' => $file->id]))->assertOk();
        $this->assertNull(PublicFile::find($file->id));
    }

    // ── 7. admin: full access ──────────────────────────────────────────────

    public function test_admin_has_full_access(): void
    {
        $this->actingAsRole(30, 'admin');

        $this->postJson(route('media.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();

        $file = $this->createFile(999);

        $this->putJson(route('media.update', ['file' => $file->id]), ['alt_text_en' => 'admin edit'])
            ->assertOk();
        $this->assertSame('admin edit', $file->fresh()->alt_text_en);

        $this->deleteJson(route('media.destroy', ['file' => $file->id]))->assertOk();
        $this->assertNull(PublicFile::find($file->id));
    }

    // ── 8. Ownership never matches a guest ────────────────────────────────

    public function test_ownership_never_matches_a_guest(): void
    {
        $this->app['env'] = 'local';

        $file = $this->createFile(42);

        // Bypass ON: the guest PUT succeeds — but only via LocalDevRoleGate's
        // environment bypass, never because a GuestActor's identity happens
        // to "own" the file.
        $this->putJson(route('media.update', ['file' => $file->id]), ['alt_text_en' => 'via bypass'])
            ->assertOk();

        // Bypass OFF, still local: if ownership had somehow been granted to
        // the anonymous actor, this would still succeed. It must not — proof
        // that the earlier success came from the bypass alone.
        config(['heisenberg.allow_anonymous_in_local' => false]);

        $this->putJson(route('media.update', ['file' => $file->id]), ['alt_text_en' => 'should be denied'])
            ->assertForbidden();
        $this->assertNotSame('should be denied', $file->fresh()->alt_text_en);
    }
}
