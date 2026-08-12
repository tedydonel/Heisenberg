<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\CommentController;
use Heisenberg\Http\Controllers\CommentModerationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg native comments routes (opt-out)
|--------------------------------------------------------------------------
| Loaded by the service provider when config('heisenberg.comments.routes')
| is true (default). Two groups with deliberately different exposure:
|
| - The public thread/submit pair lives under the `comments` middleware
|   stack (default ['web']) because blog VISITORS hit it — reading a
|   published post's thread and submitting a comment must not require the
|   editor stack. Authorization is per-request, not per-group: the
|   controller gates on PostPolicy view (so drafts stay invisible to
|   guests) plus the post's own allow_comments flag.
|
| - Moderation lives under the editor middleware stack and behind the
|   `comments.moderate` ability (admin/editor tiers by default) — it is
|   management surface, same posture as the media library's mutating
|   routes.
*/

Route::middleware(config('heisenberg.middleware.comments', ['web']))
    ->prefix('heisenberg/posts/{post}/comments')
    ->name('heisenberg.comments.')
    ->group(function (): void {
        Route::get('/', [CommentController::class, 'thread'])->name('thread');
        Route::post('/', [CommentController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('store');
    });

Route::middleware(config('heisenberg.middleware.editor', ['web']))
    ->prefix('editor/comments')
    ->name('heisenberg.comments.moderation.')
    ->group(function (): void {
        Route::get('/', [CommentModerationController::class, 'page'])->name('page');
        Route::get('/data', [CommentModerationController::class, 'index'])->name('index');
        Route::post('/{comment}/reply', [CommentModerationController::class, 'reply'])->name('reply');
        Route::patch('/{comment}', [CommentModerationController::class, 'update'])->name('update');
        Route::delete('/{comment}', [CommentModerationController::class, 'destroy'])->name('destroy');
    });
