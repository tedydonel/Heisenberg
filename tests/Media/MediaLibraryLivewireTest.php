<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media;

use Heisenberg\Contracts\RoleGate;
use Heisenberg\Contracts\VirusScanner;
use Heisenberg\Livewire\MediaLibrary;
use Heisenberg\Models\PublicFile;
use Heisenberg\Tests\Media\Fakes\FakeRoleGate;
use Heisenberg\Tests\Media\Fakes\FakeUser;
use Heisenberg\Tests\Media\Fakes\FakeVirusScanner;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The Livewire media-library path (src/Livewire/MediaLibrary.php) had ZERO
 * test coverage while it had ZERO authorization and ZERO extension
 * allow-list — the HTTP path (MediaLibraryController + UploadPublicFileRequest,
 * covered by MediaLibraryTest.php) was correct all along; this file is
 * specifically about the Livewire bypass and its fix, plus the local-dev-only
 * anonymous-access bypass (see src/Adapters/LocalDevRoleGate.php).
 */
class MediaLibraryLivewireTest extends TestCase
{
    private FakeRoleGate $roleGate;

    private FakeVirusScanner $scanner;

    private FakeUser $user;

    /**
     * tests/TestCase.php only registers HeisenbergServiceProvider — enough
     * for every other suite, since nothing else actually invokes a Livewire
     * component (the `<livewire:...>` Blade tag silently no-ops as plain
     * markup without Livewire's provider booted, which is why the editor
     * smoke tests pass without it). Testing this component for real needs
     * Livewire's own provider too.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \Livewire\LivewireServiceProvider::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * Forcing `app()->environment('local')` to actually exercise the bypass
     * has two side effects unrelated to what these tests verify, both of
     * which stem from `runningUnitTests()` checking that same underlying
     * 'env' value against the literal string 'testing':
     *
     *  - Laravel's CSRF middleware (ValidateCsrfToken) only auto-skips
     *    verification when `runningUnitTests()` is true; flipping env to
     *    'local' turns that off, and these tests never send a real token.
     *  - Livewire's checksum verification rate-limits failures via the
     *    default cache store; the testbench skeleton's default cache store
     *    is database-backed, and this suite's minimal schema never migrates
     *    a `cache` table.
     *
     * Neither has anything to do with authorization — both are worked around
     * here so the test can isolate the one thing it's actually checking.
     */
    private function simulateLocalDevSession(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['cache.default' => 'array']);
    }

    // ── Extension allow-list (previously enforced ONLY in the FormRequest) ──

    public function test_disallowed_extension_is_rejected_via_livewire(): void
    {
        $component = Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ]);

        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertNotNull($component->get('error'));
    }

    public function test_allowed_extension_still_uploads_via_livewire(): void
    {
        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $this->assertSame(1, PublicFile::count());
        $file = PublicFile::first();
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    public function test_upload_denied_without_create_ability_via_livewire(): void
    {
        $this->roleGate->abilities['media.create'] = false;

        $component = Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertNotNull($component->get('error'));
    }

    public function test_true_guest_cannot_upload_via_livewire(): void
    {
        // Not merely an ability-flag denial — a real guest, no acting-as user
        // at all, hitting the default (non-local) environment.
        $this->actingAsGuest();

        $component = Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertNotNull($component->get('error'));
    }

    public function test_true_guest_cannot_delete_via_livewire(): void
    {
        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);
        $file = PublicFile::first();
        $this->assertNotNull($file);

        $this->actingAsGuest();

        $component = Livewire::test(MediaLibrary::class)->call('remove', $file->id);

        $this->assertNotNull(PublicFile::find($file->id));
        Storage::disk('uploads')->assertExists($file->stored_path);
        $this->assertNotNull($component->get('error'));
    }

    public function test_unauthorized_actor_cannot_delete_someone_elses_file_via_livewire(): void
    {
        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);
        $file = PublicFile::first();
        $this->assertNotNull($file);

        // A different, authenticated actor without media.deleteAny.
        $this->roleGate->abilities['media.deleteAny'] = false;
        $this->actingAs(FakeUser::create(['name' => 'Other', 'email' => 'other@example.test']));

        $component = Livewire::test(MediaLibrary::class)->call('remove', $file->id);

        $this->assertNotNull(PublicFile::find($file->id), 'the file must survive an unauthorized delete attempt');
        Storage::disk('uploads')->assertExists($file->stored_path);
        $this->assertNotNull($component->get('error'));
    }

    public function test_authorized_actor_can_still_delete_via_livewire(): void
    {
        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);
        $file = PublicFile::first();
        $this->assertNotNull($file);

        Livewire::test(MediaLibrary::class)->call('remove', $file->id);

        $this->assertNull(PublicFile::find($file->id));
    }

    // ── Local-dev-only anonymous bypass (src/Adapters/LocalDevRoleGate.php) ─

    public function test_anonymous_upload_is_allowed_when_the_local_dev_bypass_is_active(): void
    {
        $this->simulateLocalDevSession();
        $this->actingAsGuest();

        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $this->assertSame(1, PublicFile::count(), 'a genuinely anonymous upload must succeed in a local dev session');
    }

    public function test_anonymous_delete_is_allowed_when_the_local_dev_bypass_is_active(): void
    {
        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);
        $file = PublicFile::first();
        $this->assertNotNull($file);

        $this->simulateLocalDevSession();
        $this->actingAsGuest();

        Livewire::test(MediaLibrary::class)->call('remove', $file->id);

        $this->assertNull(PublicFile::find($file->id));
    }

    public function test_local_dev_bypass_does_not_apply_outside_local(): void
    {
        // The suite's own APP_ENV (phpunit.xml.dist) is already 'testing', not
        // 'local' — set explicitly here so the intent reads clearly regardless
        // of that external default ever changing.
        $this->app['env'] = 'testing';
        $this->actingAsGuest();

        $component = Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $this->assertSame(0, PublicFile::count(), 'the bypass must never activate outside app()->environment(\'local\')');
        $this->assertNotNull($component->get('error'));
    }

    public function test_local_dev_bypass_can_be_disabled_via_config_even_in_local(): void
    {
        $this->simulateLocalDevSession();
        config(['heisenberg.allow_anonymous_in_local' => false]);
        $this->actingAsGuest();

        Livewire::test(MediaLibrary::class)->set('uploads', [
            UploadedFile::fake()->image('photo.jpg', 100, 100),
        ]);

        $this->assertSame(0, PublicFile::count());
    }

    public function test_svg_with_a_script_tag_is_rejected_even_with_the_local_bypass_active(): void
    {
        $this->simulateLocalDevSession();
        $this->actingAsGuest();

        $svg = UploadedFile::fake()->createWithContent(
            'xss.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>',
        );

        $component = Livewire::test(MediaLibrary::class)->set('uploads', [$svg]);

        $this->assertSame(0, PublicFile::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertNotNull($component->get('error'));
    }
}
