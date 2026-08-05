<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\MediaLibraryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg media library routes (opt-in)
|--------------------------------------------------------------------------
| Loaded by the service provider when config('heisenberg.media.routes') is
| true (default). Separate from routes/web.php's builder group — different
| config keys (heisenberg.media.routes / heisenberg.middleware.media) so a
| host can enable/protect the builder and the media library independently.
|
| Public media itself is never served through these routes — files are
| reachable directly via the `uploads` disk symlink with zero PHP in the
| read path (blueprint §12). This group is only the authenticated
| management API (upload/update/destroy) and the JSON grid/picker
| (index/select); a host typically mounts its own authenticated admin
| routes at the same MediaLibraryController actions instead.
*/
Route::middleware(config('heisenberg.middleware.media', ['web']))
    ->prefix('media')
    ->name('media.')
    ->group(function (): void {
        Route::get('/', [MediaLibraryController::class, 'index'])->name('index');
        Route::post('/upload', [MediaLibraryController::class, 'upload'])->name('upload');
        Route::get('/select', [MediaLibraryController::class, 'select'])->name('select');
        Route::put('/{file}', [MediaLibraryController::class, 'update'])->name('update');
        Route::delete('/{file}', [MediaLibraryController::class, 'destroy'])->name('destroy');
    });
