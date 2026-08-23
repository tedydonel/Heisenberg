<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\PostPublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg public routes (opt-in)
|--------------------------------------------------------------------------
|
| Loaded by HeisenbergServiceProvider when `heisenberg.public.routes` is true
| (default FALSE — turnkey blog out of the box is opt-in, mirroring comments/
| email/translations). `heisenberg.middleware.public` defaults to `['web']`,
| the lightest stack a real visitor's GET needs (same posture as routes/
| comments.php and routes/seo.php).
|
| Serves ONLY `type = 'post'` rows in `status = 'published'`; draft/scheduled/
| archived/trashed/email documents all 404 from inside the controller.
| PostPolicy `view` is bypassed on purpose for the published surface (this IS
| the public view), so a host that needs post-level visibility rules binds their
| own gate inside `PostUrlResolver` (or sets `heisenberg.public.routes` false
| and serves their own route from the same Post model).
*/

Route::middleware(config('heisenberg.middleware.public', ['web']))->group(function (): void {
    Route::get('/posts/{locale}/{slug}', [PostPublicController::class, 'show'])
        ->where(['locale' => '[a-z]{2}', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
        ->name('heisenberg.public.posts.show');
});
