<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Exception\LogicException as MimeLogicException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File as MimeFile;

/**
 * Email authoring's read-only endpoints (docs/email-system.md §6.1, §7-E3).
 *
 * A built email is served at ONE address — `{email.route_prefix}/{slug}` (routes/email.php) —
 * and nowhere else. That is the whole shape of this controller: `showBySlug()`/`exportBySlug()`
 * render, while the editor's id-scoped `/editor/{post}/email-preview` and `/editor/{post}/
 * email-export` only look the post up and REDIRECT there. The editor knows a post id (a slug is
 * a value the author can still be editing), the reader gets a real, shareable, slug-shaped URL,
 * and there is exactly one route to reason about when asking who can read a built email.
 *
 * Every entry point runs the same PostPolicy `view` check {@see PreviewController::showPost()}
 * and {@see EditorController::show()} run — an email's rendered content is exactly as sensitive
 * as a post's, so an anonymous visitor must not read a draft email by enumerating ids or
 * guessing slugs. And every one of them 404s for a non-email post: these routes only ever make
 * sense for `type = 'email'`, and the post's own surface (preview, sitemap) reciprocates by
 * 404ing/excluding emails, so neither document type is ever reachable through the other's URL.
 */
class EmailPreviewController
{
    public function __construct(private EmailRenderer $renderer)
    {
    }

    /**
     * GET `{prefix}/{slug}` — the built email itself, the one URL it is ever served at.
     *
     * Renders through the SAME {@see EmailRenderer} the Mailable uses, with one difference:
     * `preview: true` asks it to rewrite embedded images to their real, publicly reachable URLs
     * instead of `cid:` references — a `cid:` scheme has no meaning outside a MIME multipart
     * message, so a browser tab given the Mailable's actual HTML would render every image
     * broken. See {@see EmailRenderer::rewriteImages()}'s own docblock.
     */
    public function showBySlug(Request $request, string $slug): Response
    {
        $locale = $this->contentLocale($request);
        $model = $this->findBySlugOrFail($slug);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $result = $this->renderer->render($model, $locale, preview: true);

        return response($result->html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // A served email is a real page on the host's domain, and an email is not web content:
            // it has no place in a search index (the sitemap excludes `type = 'email'` for the
            // same reason). Sent as a HEADER rather than injected into the markup so the bytes a
            // reader receives stay exactly the bytes the renderer produced — the same HTML the
            // export hands an ESP and the Mailable sends, not a web-only variant of it.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * GET `{prefix}/{slug}/export?format=html|eml` — {@see self::export()}'s real destination,
     * reached by the same slug the email is served at rather than by id.
     */
    public function exportBySlug(Request $request, string $slug): BaseResponse
    {
        $locale = $this->contentLocale($request);
        $model = $this->findBySlugOrFail($slug);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        return $this->exportModel($request, $model, $locale);
    }

    /**
     * GET /editor/{post}/email-preview — the topbar's Preview button target for a saved email
     * document, which the editor can only address by id. Resolves and authorizes here (so a
     * draft's slug is never handed to someone who may not read the email), then redirects to
     * {@see self::showBySlug()}: the rendering happens at the slug URL, so that is the address
     * the author's tab lands on and can share.
     */
    public function show(Request $request, string $post): BaseResponse
    {
        $model = $this->findOrFail($post);
        abort_unless($model->type === 'email', 404);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        return redirect()->to($this->slugUrl($model) . $this->localeQuery($request));
    }

    /**
     * GET /editor/{post}/email-size — the topbar/footer's size chip (fetched after every save).
     * Renders the REAL, cid-embedded output (never the preview's swapped-URL variant) since that
     * — attachments included — is what {@see EmailRenderResult::$sizeBytes} actually measures
     * and what a host's mailer would actually ship.
     */
    public function size(Request $request, string $post): JsonResponse
    {
        $locale = $this->contentLocale($request);
        $model = $this->findOrFail($post);
        abort_unless($model->type === 'email', 404);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $result = $this->renderer->render($model, $locale);

        return response()->json(['sizeBytes' => $result->sizeBytes]);
    }

    /**
     * GET /editor/{post}/email-export?format=html|eml — the topbar's download menu, which (like
     * its Preview button) can only address a post by id. Redirects to {@see self::exportBySlug()}
     * carrying `format` through, so the download too comes from the email's own slug URL.
     */
    public function export(Request $request, string $post): BaseResponse
    {
        $model = $this->findOrFail($post);
        abort_unless($model->type === 'email', 404);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $format = $request->query('format') === 'eml' ? 'eml' : 'html';

        return redirect()->to($this->slugUrl($model, 'export') . '?format=' . $format . $this->localeQuery($request, '&'));
    }

    /**
     * The "get it out of the editor" seam (docs/email-system.md §6). `format=html` is the ESP
     * paste/upload case: the SAME `preview: true` render {@see self::showBySlug()} uses (real,
     * publicly-fetchable image URLs, no `cid:` scheme a platform ingesting raw HTML could never
     * resolve). `format=eml` is the self-contained case: a real RFC-822 message built directly
     * with Symfony Mime from the REAL, cid-embedded render (never the preview variant) —
     * subject, text/plain, text/html, and every embed re-attached as an inline part keyed to the
     * exact `cid` already burned into the HTML, the identical pairing
     * {@see \Heisenberg\Mail\HeisenbergMailable} does for a live send. Any `format` other than
     * the literal `eml` defaults to `html`.
     */
    private function exportModel(Request $request, Post $model, string $locale): BaseResponse
    {
        $format = $request->query('format') === 'eml' ? 'eml' : 'html';

        return $format === 'eml'
            ? $this->exportEml($model, $locale)
            : $this->exportHtml($model, $locale);
    }

    /**
     * `EmailRenderer`'s `preview: true` swap ({@see EmailRenderer::rewriteImages()}) already
     * replaces `cid:` with {@see \Heisenberg\Models\PublicFile::urlForPath()}'s output — a
     * root-relative `/uploads/...` path, correct for a browser tab on the same origin (show()
     * above) but NOT the "absolute, publicly-fetchable URL" an ESP ingesting raw HTML needs (no
     * page context to resolve a relative path against). {@see self::absolutizeImageUrls()}
     * closes that last gap by prefixing every such `<img src>` with this app's own base URL.
     */
    private function exportHtml(Post $model, string $locale): BaseResponse
    {
        $result = $this->renderer->render($model, $locale, preview: true);
        $html = $this->absolutizeImageUrls($result->html);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->exportFilename($model, $locale, 'html') . '"',
        ]);
    }

    /** Every `<img src="/...">` (root-relative) becomes `<img src="{base-url}/...">`. */
    private function absolutizeImageUrls(string $html): string
    {
        $base = rtrim(url('/'), '/');

        return (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc=")(\/[^"]*)(")/i',
            static fn (array $m): string => $m[1] . $base . $m[2] . $m[3],
            $html
        );
    }

    /**
     * Builds the message with `Symfony\Component\Mime\Email` directly (already on disk via
     * laravel/framework's mailer, no new dependency) rather than routing through
     * {@see \Heisenberg\Mail\HeisenbergMailable} — that class is shaped for `Mail::send()`
     * (it resolves the post by id in its own constructor and never exposes the raw string this
     * download needs), so the same embed-attaching pattern is repeated here directly on a bare
     * `SymfonyEmail`. `From` is set ONLY when a host has actually configured
     * `mail.from.address` — never fabricated. Symfony's `Message::getPreparedHeaders()` (called
     * from inside `toString()`) throws when NEITHER `From` nor `Sender` is present at all; an
     * unconfigured host sees that surfaced as a 422 rather than a stack trace, since the fix
     * (set `MAIL_FROM_ADDRESS`) is entirely on the host's side.
     */
    private function exportEml(Post $model, string $locale): BaseResponse
    {
        $result = $this->renderer->render($model, $locale);

        $email = (new SymfonyEmail())
            ->subject($result->subject)
            ->text($result->text)
            ->html($result->html);

        $fromAddress = trim((string) config('mail.from.address', ''));
        if ($fromAddress !== '') {
            $fromName = trim((string) config('mail.from.name', ''));
            $email->from($fromName !== '' ? new Address($fromAddress, $fromName) : new Address($fromAddress));
        }

        foreach ($result->embeds as $embed) {
            $part = (new DataPart(new MimeFile($embed['path']), null, $embed['mime']))
                ->asInline()
                ->setContentId($embed['cid']);
            $email->addPart($part);
        }

        try {
            $raw = $email->toString();
        } catch (MimeLogicException) {
            return response()->json([
                'message' => 'Cannot export .eml: configure mail.from.address (or mail.from.name) first.',
            ], 422);
        }

        return response($raw, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="' . $this->exportFilename($model, $locale, 'eml') . '"',
        ]);
    }

    /** `<slug>-<locale>.<extension>`, slug falling back to `email` — see export()'s own docblock. */
    private function exportFilename(Post $model, string $locale, string $extension): string
    {
        $slug = Str::slug((string) ($model->slug ?? '')) ?: 'email';
        $safeLocale = (string) preg_replace('/[^a-z0-9]/i', '', $locale) ?: 'en';

        return "{$slug}-{$safeLocale}.{$extension}";
    }

    /**
     * Which language of this email to render — `?locale=`, validated against the configured set,
     * falling back to the app locale.
     *
     * The app locale is the wrong default on its own: it is the UI language (EditorLocaleMiddleware
     * reads it from the session), while a translation is a property of the CONTENT — the editor's
     * own locale dropdown, `hbEditor.getEditingLocale()`, which is client state and never touched
     * that session value. An author working on the French version of an email therefore exported
     * the English one, because the only locale reaching this controller was the language the
     * editor's chrome happened to be in. Every render entry point takes it explicitly now, and the
     * editor passes what it is actually showing.
     *
     * `App::setLocale()` as well as returning it: the render path reaches `__()` and the post's own
     * title accessor through call sites this controller does not thread a parameter into, and all
     * of them must agree with the locale the blocks were rendered in.
     */
    /**
     * `?locale=fr` (or `&locale=fr`) to carry an explicit request through a redirect, `''` when
     * none was asked for — the id-scoped editor routes must not silently drop the author's choice
     * on the way to the slug URL that actually renders.
     */
    private function localeQuery(Request $request, string $separator = '?'): string
    {
        $requested = trim((string) $request->query('locale', ''));

        return ($requested !== '' && LocaleConfig::isValid($requested))
            ? $separator . 'locale=' . $requested
            : '';
    }

    private function contentLocale(Request $request): string
    {
        $requested = trim((string) $request->query('locale', ''));

        if ($requested !== '' && LocaleConfig::isValid($requested)) {
            app()->setLocale($requested);
        }

        return app()->getLocale();
    }

    private function findOrFail(string $post): Post
    {
        return $this->query()->findOrFail($post);
    }

    /**
     * Resolve an email by the slug it is served at. Scoped to `type = 'email'` — a POST sharing
     * this slug is a different document with its own URL and must not surface here — and, when
     * the same slug exists in several locales (the posts table's unique index is
     * `['locale', 'slug']`, so that is legal), the row for the active locale wins over an
     * arbitrary first match.
     */
    private function findBySlugOrFail(string $slug): Post
    {
        $matches = $this->query()->emails()->where('slug', $slug)->get();
        abort_if($matches->isEmpty(), 404);

        $locale = app()->getLocale();

        return $matches->firstWhere('locale', $locale) ?? $matches->first();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Post> */
    private function query()
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);

        return $class::query()->with('blocks');
    }

    /**
     * Where this email lives publicly. Two ways there is no such place, both answered with 404
     * rather than a redirect into nothing:
     *
     * - the slug is empty. A saved post always has one (Post's saving hook derives it from the
     *   title and falls back to `untitled`), so this means a row written around the model.
     * - the host set `heisenberg.email.routes` false, so routes/email.php never loaded. An email
     *   then has no public address at all, by the host's own choice — which is exactly why these
     *   id-scoped routes redirect instead of rendering: turning the group off has to actually turn
     *   serving off, not leave a second way in through the editor's URLs.
     */
    private function slugUrl(Post $model, string $suffix = ''): string
    {
        $name = $suffix === '' ? 'heisenberg.email.show' : 'heisenberg.email.export.slug';
        abort_unless(Route::has($name), 404);

        $slug = trim((string) ($model->slug ?? ''));
        abort_if($slug === '', 404);

        return route($name, ['slug' => $slug]);
    }


    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }
}
