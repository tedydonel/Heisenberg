# Media Library — Backend Blueprint

> How the platform handles **public file uploads and storage**, and how the
> subsystem is structured internally — everything you need to recreate it.
>
> Scope: **public media only** (the "WordPress pattern" — blog images,
> tour/service/team photos, downloadable docs).

---

## 1. Core Design — Public Media Only

The media library handles files that are intended to be publicly reachable by
URL, such as blog images, tour thumbnails, team photos, brand assets, brochures,
and downloadable public documents.

| Concern | Public Media Rule |
|---|---|
| Binary lives | On the **filesystem** (`uploads` disk) |
| DB row holds | **Metadata + variant map only** |
| Served by | **Web server directly** via symlink/CDN (no PHP in the read path) |
| Who uploads | Any authenticated actor allowed by the app's media-upload policy |
| Auth on read | **None** — files are public by URL |

Its guiding principle: *the database stores metadata, the disk stores bytes, and
the web server serves them with zero PHP in the hot path.*

---

## 2. Internal File Structure

The whole subsystem lives at the **application root** (`app/`), not inside a
module — it is shared infrastructure consumed by many modules (Blog, Tours, etc.).

```
app/
├── Models/
│   └── PublicFile.php                 # Eloquent model: metadata, URL/variant/srcset logic
├── Services/
│   ├── MediaLibraryService.php        # ALL business logic: store / paginate / updateMeta / delete
│   └── VirusScanner.php               # scanner adapter: clean / infected / unavailable handling
├── Http/
│   ├── Controllers/
│   │   └── MediaLibraryController.php  # thin HTTP adapter (index/upload/update/destroy/select)
│   └── Requests/
│       └── UploadPublicFileRequest.php # validation: mimes, 10 MB, filename safety, upload ability
├── Policies/
│   └── PublicFilePolicy.php            # viewAny / create / update / delete authorization
└── Providers/
    └── AppServiceProvider.php          # registers Gate::policy(PublicFile, PublicFilePolicy)

config/
├── filesystems.php                     # 'uploads' disk + public/uploads symlink
└── media.php                           # scan settings + allowed extensions + max size

database/migrations/
├── 2026_05_02_000001_create_public_files_table.php
├── 2026_05_04_000001_add_variants_to_public_files_table.php
├── 2026_05_04_000002_normalize_public_file_types_to_extensions.php
└── 2026_05_04_000003_ensure_public_file_variants_column_exists.php

routes/
└── media.php    # media.*  (index, upload, update, destroy, select)

storage/
└── uploads/                            # ← physical bytes live here (the 'uploads' disk root)
    └── media/{YYYY}/{MM}/...

public/
└── uploads  ->  ../storage/uploads     # symlink created by `php artisan storage:link`

tests/Feature/
└── MediaLibraryTest.php                # 18 passing feature tests
```

**Layered responsibility (strictly enforced):**
`Request → Controller (adapter) → FormRequest (validation) → Policy (authz) →
Service (logic) → Model (persistence + URL rendering) → Disk (bytes)`.
The controller contains **no business logic**; the model contains **no HTTP**.

---

## 3. Storage Architecture

### 3.1 The `uploads` disk (`config/filesystems.php`)

A dedicated disk, separate from Laravel's default `public` disk:

```php
'uploads' => [
    'driver'     => 'local',
    'root'       => storage_path('uploads'),   // → storage/uploads
    'url'        => '/uploads',
    'visibility' => 'public',
    'throw'      => false,
    'report'     => false,
],
```

And a symlink so the web server serves bytes directly:

```php
'links' => [
    public_path('storage') => storage_path('app/public'),
    public_path('uploads') => storage_path('uploads'),   // ← media library
],
```

Run `php artisan storage:link` once. After that:

```
DB stored_path:  media/2026/07/photo.jpg
Physical file:   storage/uploads/media/2026/07/photo.jpg
Public URL:      /uploads/media/2026/07/photo.jpg   (served by nginx/apache, no PHP)
```

### 3.2 Path & filename policy

- **Directory pattern:** `media/{year}/{month}/` (e.g. `media/2026/07/`), computed
  at upload time with `now()->format('Y/m')`.
- **Filename:** the **original uploaded filename is preserved** as the visible
  filename whenever possible (not replaced by a UUID). Path separators (`/`,
  `\`) are rejected outright (`UploadPublicFileRequest`'s `noPathSeparatorsRule`
  — though in practice Symfony's `UploadedFile` already basenames
  `getClientOriginalName()` before the app ever sees it; the rule is
  defense-in-depth). `MediaLibraryService::sanitizeBaseName()` additionally
  guards the stored *base name* (not `original_name`, which is preserved
  verbatim): dots-only names (`.`, `..`, …) become `file`; Windows-illegal
  characters (`: * ? " < > |`) and ASCII control characters (`\x00`-`\x1F`)
  are replaced with `-`; and an absurdly long name is truncated to 200
  characters — all so a hostile/odd filename yields a clean stored name
  instead of a raw Flysystem exception.
- **Collision handling:** if the name (or any of its variant names) already
  exists on disk, a counter suffix is appended: `photo.jpg` → `photo(1).jpg` →
  `photo(2).jpg`. The conflict check considers the base file **and** all image
  variant paths, so an original and its `-small`/`-medium` derivatives never clash.
- **Original-name tracking:** keep both the uploaded display name and the final
  stored name. If `photo.jpg` becomes `photo(1).jpg`, the DB row still remembers
  the user's uploaded name while `stored_path` points to the collision-free file.

### 3.3 Cloud / CDN migration path (built-in)

Nothing above is hardcoded to local disk. To move to S3/R2/CDN later:
1. Point `MediaLibraryService::DISK` (or the record's `disk` column) at `s3`
   (already configured in `filesystems.php`).
2. `PublicFile::urlFor()` reads `config("filesystems.disks.{$disk}.url")`, so
   every generated URL follows the new host automatically.
No model or controller changes required — this is the Section 10.4 CDN swap.

### 3.4 Web-server hardening (read path)

Section 1 and §12's "no PHP in the read path" invariant assume the host's web
server is configured so that `storage/uploads` (and its `public/uploads`
symlink target) can **never execute a script**, no matter what gets uploaded
into it. This is not optional, and it is **not** something the application
layer can fully guarantee on its own:

- `UploadPublicFileRequest`'s `mimes:` rule is **content-based** (Laravel/
  Symfony sniff the file's actual bytes, not just its extension or the
  client-supplied `Content-Type`), which blocks a `shell.php` renamed to
  `shell.jpg`. It does **not** block an **image polyglot** — a file that is
  simultaneously a valid JPEG/GIF/PNG (so it passes the MIME sniff and even
  renders as an image) **and** a valid PHP script (because PHP tags embedded
  in image metadata/pixel data are ignored by image decoders but are still
  live PHP source to the PHP interpreter). A polyglot passes every check this
  package performs and is stored under `media/{Y}/{m}/…` like any other
  upload.
- The only thing standing between an uploaded polyglot and remote code
  execution is whether the web server would ever hand that file to the PHP
  interpreter instead of serving its bytes directly. Because reads never go
  through PHP here (§1), the fix belongs entirely at the **web-server
  config** layer, applied once, at deploy time — not per-upload.

**The operator/host MUST configure the web server so that PHP (or any other
script handler — CGI, Perl, Python, …) never executes for anything under
`storage/uploads` or `public/uploads`.** Two ready-to-use configs:

**Apache** — drop an `.htaccess` at the root of the uploads directory (a
ready-made copy ships at `resources/stubs/uploads.htaccess` — copy it into
`storage/uploads/.htaccess` and/or `public/uploads/.htaccess` on the host;
this package deliberately does **not** place it there automatically, since a
package must not silently drop dotfiles into a host's public tree):

```apache
# mod_php
<IfModule mod_php.c>
    php_flag engine off
</IfModule>

# handler-based PHP (mod_fcgid, PHP-FPM via handler, ...)
<IfModule mod_mime.c>
    RemoveHandler .php .php3 .php4 .php5 .php7 .phtml .pht
    RemoveType .php .php3 .php4 .php5 .php7 .phtml .pht
</IfModule>

# belt-and-suspenders: refuse to serve these extensions from here at all
<FilesMatch "\.(php|php3|php4|php5|php7|phtml|pht|phar|cgi|pl|py)$">
    Require all denied
</FilesMatch>
```

**nginx** — add a `location` block ahead of the generic PHP-FPM `location`
so it matches first and never falls through to the `fastcgi_pass`:

```nginx
location ^~ /uploads/ {
    # Never hand anything under here to PHP-FPM, regardless of extension.
    location ~ \.php$ {
        deny all;
        return 403;
    }
}
```

This is host/deploy configuration, not something `php artisan` or a service
provider can enforce from inside the app — verify it explicitly as a
deployment checklist item (e.g. `curl https://host/uploads/x.php` against a
harmless test file must return the raw PHP source or a 403, never executed
output).

---

## 4. Data Model — `public_files` table

Metadata-only. **No binary is ever stored in this table**.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uploaded_by` | nullable FK → `users.id` | `nullOnDelete`; deleting a user must **not** delete their uploaded media rows |
| `type` | string(60), **indexed** | **= file extension** (`jpg`, `pdf`, …) after migration `000002` normalized it. Originally a category; now an extension used for filtering. |
| `disk` | string(32), default `uploads` | which disk holds the bytes |
| `stored_path` | string | relative path on the disk |
| `original_name` | string | the user-provided filename before deduplication |
| `stored_name` | string | the final deduplicated filename on disk |
| `mime_type` | string(127) | |
| `size_bytes` | unsignedBigInteger | |
| `width` / `height` | unsignedSmallInteger, nullable | images only |
| `variants` | **JSON**, nullable | responsive derivatives map (see §6) |
| `alt_text_en` / `alt_text_fr` | string(255), nullable | **bilingual** alt text |
| `caption_en` / `caption_fr` | string(500), nullable | bilingual captions |
| `credit` | string(255), nullable | attribution |
| `deleted_at` | softDeletes | "old files soft-deleted" |
| `created_at` / `updated_at` | timestamps | |

**Indexes:** `type`, composite `(type, created_at)`, `uploaded_by`.

**Model constants** (`App\Models\PublicFile`):
```php
const TYPES = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv'];
const IMAGE_EXTENSIONS = ['jpg','jpeg','png','webp','gif'];
const MAX_KB = 10_240;                                   // 10 MB
const VARIANTS = ['small' => ['width'=>320], 'medium' => ['width'=>768]];
```

`$fillable` covers every column except `id`/timestamps; `variants` casts to
`array`; the numeric columns cast to `integer`. **No `$guarded = []`** (platform
rule — mass-assignment protection is mandatory).

> **Migration note:** the schema was built in 4 steps — create table, add
> `variants` JSON, back-fill `type` to be the extension (chunked update over
> existing rows), then an idempotent "ensure variants column exists" guard. When
> recreating fresh you can collapse these into the single create migration in §11.

---

## 5. Upload Pipeline

`POST /media/upload` → `MediaLibraryController::upload()` →
`MediaLibraryService::store()`. Step by step:

1. **Authorize** — `UploadPublicFileRequest::authorize()` requires the app's
   configured media-upload ability; controller also calls
   `$this->authorize('create', PublicFile::class)`.
2. **Validate** — allowed `mimes` (from `PublicFile::TYPES`), `max:10240` KB,
   no path separators in the filename. Accepts **either** a single `file` **or**
   a `files[]` array (multi-upload). See §8.
3. **Virus scan** — scan the temporary uploaded file before it is moved to the
   public disk. If the scanner reports infected/suspicious content, reject the
   upload with a validation-style error and write an audit/security log entry.
   If the scanner is unavailable, fail closed by default unless the environment
   explicitly enables `MEDIA_SCAN_FAIL_OPEN=true` for local development.
4. **Resolve destination** — `media/{Y}/{m}`; compute a collision-free filename;
   derive `type` from the extension.
5. **Persist bytes** — `Storage::disk('uploads')->putFileAs($dir, $file, $name)`.
   The file is written straight to disk; the DB row stores metadata only.
6. **Read dimensions** — `getimagesize()` for images → `width`/`height`
   (null for non-images, and on any failure — never throws).
7. **Generate variants** — for images only, build `small` + `medium` (see §6).
8. **Create the DB row** — one `PublicFile::create([...])` with all metadata +
   the `variants` map + any bilingual meta passed in.
9. **Failure cleanup** — if the DB insert throws, every file already written to
   disk (original + variants) is deleted, then the exception re-throws. No orphans.

Response:
- **JSON** (`expectsJson`) → `201` with a message and a rich `attachmentPayload`
  per file (used by the media dialog / pickers).
- **Redirect** → back to `media.index` with a status flash.

### 5.1 Virus Scanner Contract

`VirusScanner` is a small adapter used by `MediaLibraryService` before any file
is written to `storage/uploads`.

Required behavior:

| Result | Upload behavior |
|---|---|
| `clean` | Continue the upload pipeline |
| `infected` / `suspicious` | Reject upload, log security event, write no public file |
| `unavailable` | Fail closed by default; optionally fail open only in local/dev |

Recommended implementation:

- Use ClamAV/clamd in production, behind a `VirusScanner` interface so the app
  can swap providers later.
- Scan the temporary upload path, not the final public path.
- Use a timeout so uploads cannot hang forever if the scanner is unhealthy.
- Log scanner result, filename, uploader id, MIME type, and detected signature
  when available.
- Never create the `PublicFile` DB row until the scan passes.

---

## 6. Responsive Variants (image derivatives)

On image upload, `MediaLibraryService::variants()` uses **Intervention Image (GD
driver)** to produce downscaled copies:

```
for each of PublicFile::VARIANTS ([small=>320w, medium=>768w]):
    clone the decoded image
    scaleDown(width: N)                       # never upscales
    encodeByExtension(ext, quality: 82)       # jpeg normalized to jpg
    write to  media/{Y}/{m}/{base}-{variant}.{ext}
    record  { path, width, height, size_bytes }  into the variants JSON
```

The `variants` JSON column ends up like:
```json
{
  "small":  { "path": "media/2026/07/photo-small.jpg",  "width": 320, "height": 213, "size_bytes": 18234 },
  "medium": { "path": "media/2026/07/photo-medium.jpg", "width": 768, "height": 512, "size_bytes": 61200 }
}
```
If variant generation fails, it returns `[]` — the original is still usable.

### 6.1 Decompression-bomb guard

Before any of the above runs, `MediaLibraryService` checks the image's pixel
count using **only** the header dimensions already read via `getimagesize()`
(cheap — no decode) against `config('heisenberg.media.max_megapixels')`
(default `40`, env `HEISENBERG_MEDIA_MAX_MEGAPIXELS`). A tiny file can declare
an enormous pixel grid (e.g. a few-KB PNG claiming 40000×40000px) that is
trivial to read the header of but catastrophic to actually decode — GD/
Intervention would allocate a raw bitmap proportional to width×height,
exhausting memory/CPU. If `width * height` exceeds the cap, variant
generation is skipped entirely (Intervention's `read()` — the actual decode
— is never called); the original file is still stored and served, just
without `small`/`medium` derivatives (`variants` ends up `[]`, same shape as
any other variant-generation skip in §6). Set `max_megapixels` to `0` to
disable the cap.

---

## 7. Retrieval & Responsive Delivery (Model API)

All URL logic lives on `PublicFile` so views/JSON stay dumb. Key methods:

| Method / accessor | Returns |
|---|---|
| `url` / `urlFor(null)` | full-size public URL (disk-aware, rawurlencoded) |
| `thumbnail_url` / `small_url` | `small` variant URL |
| `medium_url` / `large_url` | `medium` / original URL |
| `srcset(['small','medium'])` | a `srcset` string (`… 320w, … 768w, … Nw`) for images, else `null` |
| `imagePayload($context)` | `{ url, srcset, sizes }` where `sizes` is tuned per context (`thumbnail`, `card`, `gallery`, `hero`, `article`) |
| `getAlt($locale)` | locale alt text with fallback to the other locale |
| `human_size` | `"1.4 MB"` style string |
| `forUrl($url)` / `storedPathFromUrl($url)` | **reverse lookup**: resolve a `/uploads/…` URL back to its `PublicFile` record — used to re-link content blocks to library records |
| `uploadsUrl($path)` | builds `/uploads/…` with each path segment `rawurlencode`d |

`urlFor()` is disk-aware: for the `uploads` disk it returns `/uploads/…`;
otherwise it prefixes with the configured disk `url` (the S3/CDN case).

---

## 8. Update & Delete Pipelines

**Update metadata** (`PATCH`/`PUT /media/{file}` → `update()` → `updateMeta()`):
- Only the whitelisted meta fields are mutable: `alt_text_en/fr`,
  `caption_en/fr`, `credit`. Binary/path fields (`stored_path`, `disk`,
  `mime_type`, `variants`, …) are **never** touched here.
- `array_intersect_key` guarantees no other field can be mass-assigned.

**Delete** (`DELETE /media/{file}` → `destroy()` → `delete()`):
- Collect the original `stored_path` + every variant path.
- `Storage::disk($file->disk)->delete([...])` removes all physical files.
- `$file->delete()` **soft-deletes** the DB row (record retained for audit/relink
  safety; the bytes are gone).
- Delete permission is controlled only by `PublicFilePolicy::delete`; the route
  exists, but unauthorized actors receive a normal authorization failure.

---

## 9. Authorization

Two enforcement layers, both policy/capability based. The media library does not
hardcode named user roles. Those names belong to the host application, not this
subsystem.

**`UploadPublicFileRequest::authorize()`** — gate at the request boundary:
uploads require a configured media-upload ability such as `media.create`.

**`PublicFilePolicy`** — per-action authorization, invoked via
`$this->authorize()` in every controller action:

| Ability | Rule |
|---|---|
| `viewAny` | Any authenticated actor with `media.viewAny` |
| `create` | Any authenticated actor with `media.create` |
| `update` | The original uploader or any actor with `media.updateAny` |
| `delete` | Any actor with `media.deleteAny` |

If the host app has a global privileged-user bypass, keep that bypass outside
the media library and audit it there. The media policy itself should stay
role-name agnostic. Register the policy in `AppServiceProvider::boot()`:
```php
Gate::policy(PublicFile::class, PublicFilePolicy::class);
```

---

## 10. HTTP API (routes)

The media library exposes one route group. The host application decides which
authenticated actors can reach the route group, while the policy decides what
each actor can do inside it.

**Media routes** — `routes/media.php`, authenticated middleware, prefix `media`,
name `media.`:

| Method | URI | Action | Route name |
|---|---|---|---|
| GET | `/media` | `index` | `media.index` |
| POST | `/media/upload` | `upload` | `media.upload` |
| PUT | `/media/{file}` | `update` | `media.update` |
| DELETE | `/media/{file}` | `destroy` | `media.destroy` |
| GET | `/media/select` | `select` | `media.select` |

> The delete route is present for consistency, but deletion is still policy
> guarded. An actor without `media.deleteAny` cannot delete files.

**Endpoint semantics:**
- `index` → renders the `media.index` grid view with paginated files + filters
  (`extension`, `search`, `uploader_id`, `date_from`, `date_to`) + the
  `PublicFile::TYPES` list for the filter dropdown.
- `upload` → stores 1..N files; returns JSON (`201`) or redirects.
- `select` → **JSON picker** for modal image pickers (blog builder, tour/service
  editors). Returns per file: `id, url, thumbnail_url, medium_url, large_url,
  srcset, sizes, variants, original_name, stored_name, alt_text_en/fr, width,
  height, human_size` plus `next_page_url` for infinite scroll.

---

## 11. Recreation Checklist

Rebuild the whole subsystem from zero:

1. **Disk + symlink** — add the `uploads` disk and the `public/uploads` link to
   `config/filesystems.php` (§3.1), then `php artisan storage:link`.
2. **Migration** — create `public_files` with the columns in §4 (collapse the
   4 historical migrations into one; make `type` = extension from the start):
   ```php
   Schema::create('public_files', function (Blueprint $t) {
       $t->id();
       $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
       $t->string('type', 60)->index();          // file extension
       $t->string('disk', 32)->default('uploads');
       $t->string('stored_path');
       $t->string('original_name');
       $t->string('stored_name');
       $t->string('mime_type', 127);
       $t->unsignedBigInteger('size_bytes');
       $t->unsignedSmallInteger('width')->nullable();
       $t->unsignedSmallInteger('height')->nullable();
       $t->json('variants')->nullable();
       $t->string('alt_text_en', 255)->nullable();
       $t->string('alt_text_fr', 255)->nullable();
       $t->string('caption_en', 500)->nullable();
       $t->string('caption_fr', 500)->nullable();
       $t->string('credit', 255)->nullable();
       $t->softDeletes();
       $t->timestamps();
       $t->index(['type', 'created_at']);
       $t->index('uploaded_by');
   });
   ```
3. **Model** — `App\Models\PublicFile` with `SoftDeletes`, `$fillable`, the casts,
   the constants (`TYPES`, `IMAGE_EXTENSIONS`, `MAX_KB`, `VARIANTS`), and the URL/
   variant/srcset/reverse-lookup methods (§7).
4. **Services** — `App\Services\MediaLibraryService` with `store()`,
   `paginate()`, `updateMeta()`, `delete()`, and the internal helpers
   (`dimensions`, `availableFilename`, `filenameConflicts`, `variants`,
   `isImage`). Add `VirusScanner` and inject it into the upload pipeline before
   disk writes. Requires `intervention/image` (GD) and a production scanner such
   as ClamAV/clamd.
5. **FormRequest** — `UploadPublicFileRequest`: ability-gated `authorize()`,
   `mimes`/`max`/no-separators rules, single-or-multi (`file` + `files.*`),
   bilingual meta fields.
6. **Policy** — `PublicFilePolicy` (§9); register it in `AppServiceProvider`.
7. **Controller** — `MediaLibraryController` (thin): `index`, `upload`, `update`,
   `destroy`, `select`, plus `attachmentPayload()` helpers. Use the
   `AuthorizesRequests` trait and `$this->authorize()` in every action.
8. **Routes** — the generic authenticated media route group in §10.
9. **Views** — a `media.index` grid + a picker/dialog consuming `/media/select`
   JSON (front-end; out of backend scope).
10. **Tests** — feature-test the pipeline: upload creates a row + physical file +
    variants, duplicate filenames become collision-free while preserving
    `original_name`, mimes/size rejected, infected files rejected before disk
    write, scanner outage fails closed, deleting a user nulls `uploaded_by`
    without deleting media rows, unauthorized users can't delete, delete removes
    bytes and soft-deletes the row, `select` returns the picker payload.

---

## 12. Invariants (don't break these when recreating)

- **Metadata in DB, bytes on disk** — never store public-file binary in the DB.
- **No PHP in the read path** — public files are served by the web server via the
  `public/uploads` symlink; there is no public-media download controller. This
  invariant is only as strong as the web-server config that backs it — see §3.4
  for why (image polyglots defeat content-based MIME validation) and the
  Apache/nginx snippets that make PHP execution under `storage/uploads` /
  `public/uploads` actually impossible, not just unlikely.
- **Controller stays thin** — all logic in `MediaLibraryService`; all authz in
  `PublicFilePolicy`; all validation in `UploadPublicFileRequest`.
- **No role names in the subsystem** — upload, update, and delete rights come
  from policies/capabilities supplied by the host app.
- **Delete = physical delete + soft-delete row** — bytes go, record stays.
- **Disk-agnostic URLs** — always render via `PublicFile::urlFor()`, never
  hand-build a path, so the S3/CDN swap stays a config change.
- **Bilingual metadata** — `alt`/`caption` carry `_en` and `_fr` with fallback.

---

*Backend blueprint for the public media library. File: `docs/media-library-backend-blueprint.md`.*
```
