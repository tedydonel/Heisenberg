<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg SEO routes (opt-out)
|--------------------------------------------------------------------------
| Loaded by HeisenbergServiceProvider::registerSeoRoutes() when
| config('heisenberg.seo.sitemap') is true (default). `heisenberg.middleware.seo`
| defaults to just ['web'] -- the lightest stack a real visitor's/crawler's GET
| needs (same posture as routes/comments.php's public thread/submit group; no
| auth is ever required to read a sitemap).
*/

Route::middleware(config('heisenberg.middleware.seo', ['web']))->group(function (): void {
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('heisenberg.seo.sitemap');
});
