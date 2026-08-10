<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\CategoryController;
use Heisenberg\Http\Controllers\EditorController;
use Heisenberg\Http\Controllers\FontController;
use Heisenberg\Http\Controllers\LocaleController;
use Heisenberg\Http\Controllers\PostCategoryController;
use Heisenberg\Http\Controllers\PostController;
use Heisenberg\Http\Controllers\PostRevisionsController;
use Heisenberg\Http\Controllers\PostSettingsController;
use Heisenberg\Http\Controllers\PostTagController;
use Heisenberg\Http\Controllers\PreviewController;
use Heisenberg\Http\Controllers\SavedThemeController;
use Heisenberg\Http\Controllers\TagController;
use Heisenberg\Http\Controllers\ThemeController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('heisenberg.middleware.editor', ['web']))->group(function (): void {
    Route::get('/editor', [EditorController::class, 'index'])->name('heisenberg.editor.index');
    Route::get('/editor/components', [EditorController::class, 'components'])->name('heisenberg.editor.components');
    Route::get('/editor/media', [EditorController::class, 'media'])->name('heisenberg.editor.media');
    // Open an EXISTING post in the editor shell (vs. the blank `/editor` above) — hydrates the
    // canvas from its saved block tree. `whereNumber` disambiguates from the literal `components`/
    // `media` segments above (Post's PK is a plain auto-increment id, same reasoning as the
    // `/editor/posts/{post}` routes below) — order relative to them doesn't matter because of it,
    // but it's kept after them for readability (page routes together, then the JSON API routes).
    Route::get('/editor/{post}', [EditorController::class, 'show'])->whereNumber('post')->name('heisenberg.editor.show');
    // Post + block-tree save/load (blueprint §2.3, §7) — PostController. A
    // numeric {post} constraint since Post's PK is a plain auto-increment id
    // (not implicit route-model binding: the Post class is config-swappable
    // via heisenberg.models.post, so PostController resolves it manually).
    Route::post('/editor/posts', [PostController::class, 'store'])->name('heisenberg.editor.posts.store');
    Route::put('/editor/posts/{post}', [PostController::class, 'update'])->whereNumber('post')->name('heisenberg.editor.posts.update');
    Route::get('/editor/posts/{post}', [PostController::class, 'show'])->whereNumber('post')->name('heisenberg.editor.posts.show');
    // Revision history (read-only — restore is client-driven via hbEditor.replaceDoc()).
    Route::get('/editor/posts/{post}/revisions', [PostRevisionsController::class, 'index'])->whereNumber('post')->name('heisenberg.editor.posts.revisions.index');
    Route::get('/editor/posts/{post}/revisions/{revision}', [PostRevisionsController::class, 'show'])->whereNumber('post')->whereNumber('revision')->name('heisenberg.editor.posts.revisions.show');
    // Category/tag taxonomy CRUD (blueprint §9.5 TaxonomyController) — literal
    // `categories`/`tags` segments never collide with the numeric-only
    // `/editor/{post}` above (whereNumber rejects a non-numeric segment
    // outright, so route order doesn't matter here, but these are kept
    // grouped with the other JSON API routes for readability).
    Route::get('/editor/categories', [CategoryController::class, 'index'])->name('heisenberg.editor.categories.index');
    Route::post('/editor/categories', [CategoryController::class, 'store'])->name('heisenberg.editor.categories.store');
    Route::put('/editor/categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('heisenberg.editor.categories.update');
    Route::delete('/editor/categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category')->name('heisenberg.editor.categories.destroy');
    Route::get('/editor/tags', [TagController::class, 'index'])->name('heisenberg.editor.tags.index');
    Route::post('/editor/tags', [TagController::class, 'store'])->name('heisenberg.editor.tags.store');
    Route::put('/editor/tags/{tag}', [TagController::class, 'update'])->whereNumber('tag')->name('heisenberg.editor.tags.update');
    Route::delete('/editor/tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->name('heisenberg.editor.tags.destroy');
    // Style/Themes panel backend (TODO 6.7) — re-mounted here under /editor's own
    // config('heisenberg.middleware.editor') gate; both controllers previously lived only
    // under the deleted builder route group. show()/search() are read-only and open; update()
    // carries its own admins-tier RoleGate check (see ThemeController's docblock).
    Route::get('/editor/theme', [ThemeController::class, 'show'])->name('heisenberg.editor.theme.show');
    Route::put('/editor/theme', [ThemeController::class, 'update'])->name('heisenberg.editor.theme.update');
    Route::get('/editor/fonts', [FontController::class, 'search'])->name('heisenberg.editor.fonts.search');
    // The user's named theme library ("Save to Themes") — DELETE takes its target via a JSON
    // body (`name`), not a route segment, so an arbitrary user-typed name never has to survive
    // URL encoding/decoding round-trip.
    Route::get('/editor/themes', [SavedThemeController::class, 'index'])->name('heisenberg.editor.themes.index');
    Route::post('/editor/themes', [SavedThemeController::class, 'store'])->name('heisenberg.editor.themes.store');
    Route::delete('/editor/themes', [SavedThemeController::class, 'destroy'])->name('heisenberg.editor.themes.destroy');
    // Post <-> category / tag attach-detach — both BelongsToMany as of 2026-08-03 (see
    // Post::categories()/tags() docblocks), so both routes are shaped identically.
    Route::post('/editor/posts/{post}/categories/{category}', [PostCategoryController::class, 'attach'])->whereNumber('post')->whereNumber('category')->name('heisenberg.editor.posts.categories.attach');
    Route::delete('/editor/posts/{post}/categories/{category}', [PostCategoryController::class, 'detach'])->whereNumber('post')->whereNumber('category')->name('heisenberg.editor.posts.categories.detach');
    Route::post('/editor/posts/{post}/tags/{tag}', [PostTagController::class, 'attach'])->whereNumber('post')->whereNumber('tag')->name('heisenberg.editor.posts.tags.attach');
    Route::delete('/editor/posts/{post}/tags/{tag}', [PostTagController::class, 'detach'])->whereNumber('post')->whereNumber('tag')->name('heisenberg.editor.posts.tags.detach');
    // Lightweight per-post settings (Page layout, Discussion, Featured image, Table of contents) —
    // see PostSettingsController's own docblock for why these bypass PostController::update() entirely.
    Route::put('/editor/posts/{post}/layout', [PostSettingsController::class, 'updateLayout'])->whereNumber('post')->name('heisenberg.editor.posts.layout.update');
    Route::put('/editor/posts/{post}/discussion', [PostSettingsController::class, 'updateDiscussion'])->whereNumber('post')->name('heisenberg.editor.posts.discussion.update');
    Route::put('/editor/posts/{post}/featured-image', [PostSettingsController::class, 'updateFeaturedImage'])->whereNumber('post')->name('heisenberg.editor.posts.featured-image.update');
    Route::put('/editor/posts/{post}/toc', [PostSettingsController::class, 'updateToc'])->whereNumber('post')->name('heisenberg.editor.posts.toc.update');
    // "Preview in another page" (topbar's eye button) — reaches the SAME
    // PreviewController the deprecated builder route group already wires at
    // POST/GET /builder/preview (routes/web.php), but under the editor's own
    // config('heisenberg.middleware.editor') gate, so /editor never depends
    // on /builder's route group or its separate config('heisenberg.middleware
    // .builder') gate. Two flows:
    //  - never-saved document: POST the current doc into the session
    //    (store), then open the session-backed GET below — exactly the round
    //    trip PreviewController::show() already renders through BlockRenderer.
    //  - an already-saved post: GET /editor/{post}/preview renders straight
    //    from the DB's saved block tree (PreviewController::showPost) — no
    //    session round-trip, so it can't reflect a stale/unrelated document
    //    left in the session by a different editing session.
    Route::post('/editor/preview', [PreviewController::class, 'store'])->name('heisenberg.editor.preview.store');
    Route::get('/editor/preview', [PreviewController::class, 'show'])->name('heisenberg.editor.preview');
    Route::get('/editor/{post}/preview', [PreviewController::class, 'showPost'])->whereNumber('post')->name('heisenberg.editor.preview.post');
    // Locale switcher — POST flips the active locale (session), then redirects
    // back to the referer (or a provided `return` query param). Whitelist lives
    // in LocaleController::LOCALES; anything outside it 404s.
    Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
        ->where('locale', '[a-z]{2}')
        ->name('heisenberg.locale.switch');
    // Dev-only static server for the uploads disk (see EditorController::servedUpload).
    Route::get('/uploads/{path}', [EditorController::class, 'servedUpload'])->where('path', '.*')->name('heisenberg.editor.uploads');
    Route::get('/heisenberg-assets/editor.css', [EditorController::class, 'css'])->name('heisenberg.editor.asset.css');
    Route::get('/heisenberg-assets/editor-animations.css', [EditorController::class, 'animationsCss'])->name('heisenberg.editor.asset.animations');
    Route::get('/heisenberg-assets/editor-supports.css', [EditorController::class, 'supportsCss'])->name('heisenberg.editor.asset.supports');
    Route::get('/heisenberg-assets/editor-fonts/{file}', [EditorController::class, 'font'])->name('heisenberg.editor.asset.font');
    Route::get('/heisenberg-assets/editor-logo.svg', [EditorController::class, 'logo'])->name('heisenberg.editor.asset.logo');
    // Block-icon library (the imported VvvebJs collection): the picker's search feed and the
    // per-icon SVG asset the canvas runtime fetch-injects. Both manifest-gated — see
    // IconLibraryService for the fail-closed set/slug allow-list.
    Route::get('/editor/icons', [EditorController::class, 'iconsSearch'])->name('heisenberg.editor.icons.search');
    Route::get('/heisenberg-assets/icon/{set}/{slug}.svg', [EditorController::class, 'icon'])
        ->where(['set' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'])
        ->name('heisenberg.editor.asset.icon');
});
