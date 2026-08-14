<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\EmailPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg email routes (opt-out)
|--------------------------------------------------------------------------
| A built email's OWN address (docs/email-system.md §6.1). Everything that
| renders an email document lives here, under its slug -- the editor's
| id-scoped /editor/{post}/email-preview only redirects into this group, and
| the post preview route 404s for an email outright, so there is exactly one
| URL a built email is ever served at.
|
| `heisenberg.middleware.email` defaults to ['web'] -- a recipient following a
| "view in browser" link is not an authenticated editor. That is not the access
| control: EmailPreviewController runs the same PostPolicy `view` check the
| editor does, so a DRAFT email 403s for a visitor however open this stack is.
*/

Route::middleware(config('heisenberg.middleware.email', ['web']))->group(function (): void {
    $prefix = trim((string) config('heisenberg.email.route_prefix', 'emails'), '/') ?: 'emails';

    // The slug pattern matches what Post's generator actually emits (Str::slug + the numeric
    // collision suffix), so a request for anything else never reaches a DB lookup at all.
    Route::get($prefix . '/{slug}', [EmailPreviewController::class, 'showBySlug'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('heisenberg.email.show');

    Route::get($prefix . '/{slug}/export', [EmailPreviewController::class, 'exportBySlug'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('heisenberg.email.export.slug');
});
