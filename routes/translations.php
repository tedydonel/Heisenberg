<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\PostTranslationsApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg public translations API (opt-out)
|--------------------------------------------------------------------------
| Loaded by the service provider when config('heisenberg.translations.routes')
| is true (default). docs/content-translation.md §7: a translation group
| presents as ONE post with ONE shared slug (locale comes from the HOST's own
| URL prefix, never the slug text) — this single read-only endpoint lets a
| host build its own language-switcher buttons without reaching into the
| database directly.
|
| Sits under the `translations` middleware stack (default ['web']) — same
| lightest-stack posture as routes/comments.php's public thread/submit pair:
| a blog visitor's language-switcher fetch must not require the editor
| stack. Authorization is per-row inside the controller (Gate view, guests
| see published only), not per-group — see PostTranslationsApiController's
| own docblock.
*/

Route::middleware(config('heisenberg.middleware.translations', ['web']))
    ->prefix('heisenberg/posts/{post}/translations')
    ->name('heisenberg.translations.')
    ->group(function (): void {
        Route::get('/', [PostTranslationsApiController::class, 'index'])->name('index');
    });
