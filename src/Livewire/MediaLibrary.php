<?php

declare(strict_types=1);

namespace Heisenberg\Livewire;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\PublicFile;
use Heisenberg\Policies\PublicFilePolicy;
use Heisenberg\Services\MediaLibraryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The media picker/library, wired to the real backend. This is the "live" half
 * of the design's Media Dialog (WO65C/Krcma): search + grid page through
 * MediaLibraryService::paginate(), uploads run through the same service the
 * HTTP controller uses (WithFileUploads → store()), and a pick dispatches a
 * `media-selected` event a host can listen for. No business logic lives here —
 * every mutation delegates to MediaLibraryService (blueprint §12 layering).
 *
 * SECURITY: unlike MediaLibraryController (the HTTP path), this component used
 * to run every mutation with zero authorization and no `mimes:` validation —
 * `/editor/media` is unauthenticated by default
 * (`config('heisenberg.middleware.editor')`), so that was a full bypass of
 * UploadPublicFileRequest's allow-list AND of PublicFilePolicy. Both mutating
 * actions below now authorize through the SAME PublicFilePolicy the
 * controller uses (see authorizeMedia()), and MediaLibraryService::storeOne()
 * independently re-enforces the extension allow-list regardless of what
 * happens here (defense in depth).
 *
 * SECURITY (viewAny gap, fixed 2026-09-19): render() and select() used to
 * expose the file list / a selected file's id to ANY actor with zero
 * authorization at all — MediaLibraryController's HTTP twin authorizes
 * `viewAny` on both its `index()` and `select()` actions, but this component
 * had no equivalent check anywhere in its read path, only on the two
 * mutations above. A caller unauthorized for `media.viewAny` (e.g. a
 * genuinely anonymous visitor outside the local-dev bypass) could still page
 * through and pick from the entire media library through this component.
 * render() now authorizes `viewAny` before calling
 * MediaLibraryService::paginate() and renders an empty grid with `$error`
 * set instead of the real listing when it fails (never a raw 500 — the
 * component has no "unauthorized" state of its own, so an empty paginator is
 * the closest equivalent to "nothing to show you"). select() authorizes the
 * same ability before dispatching `media-selected` — a pick is itself a way
 * to read (and hand to a listener) which file lives at a given id.
 */
class MediaLibrary extends Component
{
    use WithFileUploads;

    public string $search = '';

    public ?int $selectedId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public ?string $error = null;

    /** Fired by Livewire the moment files finish uploading to temp storage. */
    public function updatedUploads(): void
    {
        $this->error = null;

        try {
            $this->authorizeMedia('create');

            $maxKb = (int) config('heisenberg.media.max_kb', PublicFile::MAX_KB);
            $extensions = implode(',', (array) config('heisenberg.media.extensions', PublicFile::TYPES));

            $this->validate([
                'uploads.*' => "file|mimes:{$extensions}|max:{$maxKb}",
            ]);

            $files = array_values(array_filter($this->uploads));
            if ($files !== []) {
                app(MediaLibraryService::class)->store($files);
            }
        } catch (AuthorizationException) {
            $this->error = 'You are not authorized to upload files.';
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        } catch (Throwable $e) {
            $this->error = 'Upload failed: ' . $e->getMessage();
        }

        $this->uploads = [];

        // Fires after this update's DOM has morphed in the real cards, so the
        // client can drop its optimistic uploading cards at exactly that moment.
        $this->dispatch('uploads-done');
    }

    public function select(int $id): void
    {
        try {
            $this->authorizeMedia('viewAny');
        } catch (AuthorizationException) {
            $this->error = 'You are not authorized to view media.';

            return;
        }

        $this->selectedId = $id;
        $this->dispatch('media-selected', id: $id);
    }

    public function remove(int $id): void
    {
        $file = PublicFile::query()->find($id);
        if ($file === null) {
            return;
        }

        try {
            $this->authorizeMedia('delete', $file);
        } catch (AuthorizationException) {
            $this->error = 'You are not authorized to delete this file.';

            return;
        }

        app(MediaLibraryService::class)->delete($file);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    /**
     * Mirrors MediaLibraryController's `$this->authorize('create'|'delete', ...)`
     * calls, against the exact same PublicFilePolicy — but constructed
     * directly here instead of routed through Laravel's Gate, because Gate
     * refuses to even invoke a policy method typed `Authenticatable $user`
     * (non-nullable, by design) when there is no logged-in user at all
     * (`Gate::canBeCalledWithUser()`), and `/editor` has no logged-in user by
     * default. Calling the policy directly sidesteps that guest short-circuit
     * without loosening PublicFilePolicy's type-hint for every other caller:
     * a GuestActor stands in for "no user" so the policy still always
     * receives a real Authenticatable, and the RoleGate it consults is
     * wrapped in LocalDevRoleGate — see that class for the full, tightly
     * scoped local-dev-only bypass this enables.
     *
     * @throws AuthorizationException
     */
    private function authorizeMedia(string $ability, ?PublicFile $file = null): void
    {
        $actor = Auth::user() ?? new GuestActor();
        $policy = new PublicFilePolicy(new LocalDevRoleGate(app(RoleGate::class)));

        $allowed = match ($ability) {
            'create' => $policy->create($actor),
            'delete' => $file !== null && $policy->delete($actor, $file),
            'viewAny' => $policy->viewAny($actor),
            default => false,
        };

        if (! $allowed) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    public function render(): View
    {
        try {
            $this->authorizeMedia('viewAny');
        } catch (AuthorizationException) {
            // Only set when nothing more specific (e.g. an upload/delete
            // rejection) already explains itself — render() runs after every
            // action, and an unauthorized actor's more specific error from
            // updatedUploads()/remove() must not be clobbered by this generic
            // one on the very next re-render.
            $this->error ??= 'You are not authorized to view media.';

            return view('heisenberg::livewire.media-library', [
                'files' => new LengthAwarePaginator([], 0, 12),
            ]);
        }

        $files = app(MediaLibraryService::class)->paginate(
            ['search' => trim($this->search)],
            12,
        );

        return view('heisenberg::livewire.media-library', [
            'files' => $files,
        ]);
    }
}
