<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Exception\LogicException as MimeLogicException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File as MimeFile;

/**
 * Email authoring's read-only endpoints (docs/email-system.md §7-E3, §6): a browser-renderable
 * preview of a `type = 'email'` post, its rendered size for the topbar/footer's size chip, and
 * — the "get it OUT of the editor" seam (§6) — the export action that hands back either an
 * ESP-ready HTML file or a self-contained .eml. All run the SAME PostPolicy `view` check
 * {@see PreviewController::showPost()} and {@see EditorController::show()} already run — an
 * email's rendered content is exactly as sensitive as a post's, so an anonymous visitor must not
 * read a draft email by enumerating ids. `export()` additionally 404s for a non-email post — this
 * route only ever makes sense for `type = 'email'`, and existing() alone (as show()/size() do)
 * would otherwise happily "export" an ordinary post.
 */
class EmailPreviewController
{
    public function __construct(private EmailRenderer $renderer)
    {
    }

    /**
     * GET /editor/{post}/email-preview — the topbar's Preview button target for a saved email
     * document. Renders through the SAME {@see EmailRenderer} the Mailable uses, with one
     * difference: `preview: true` asks it to rewrite embedded images to their real, publicly
     * reachable URLs instead of `cid:` references — a `cid:` scheme has no meaning outside a
     * MIME multipart message, so a browser tab given the Mailable's actual HTML would render
     * every image broken. See {@see EmailRenderer::rewriteImages()}'s own docblock.
     */
    public function show(Request $request, string $post): Response
    {
        $model = $this->findOrFail($post);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $result = $this->renderer->render($model, app()->getLocale(), preview: true);

        return response($result->html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /editor/{post}/email-size — the topbar/footer's size chip (fetched after every save).
     * Renders the REAL, cid-embedded output (never the preview's swapped-URL variant) since that
     * — attachments included — is what {@see EmailRenderResult::$sizeBytes} actually measures
     * and what a host's mailer would actually ship.
     */
    public function size(Request $request, string $post): JsonResponse
    {
        $model = $this->findOrFail($post);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $result = $this->renderer->render($model, app()->getLocale());

        return response()->json(['sizeBytes' => $result->sizeBytes]);
    }

    /**
     * GET /editor/{post}/email-export?format=html|eml — the "get it out of the editor" seam
     * (docs/email-system.md §6). `format=html` is the ESP paste/upload case: the SAME
     * `preview: true` render show() uses (real, publicly-fetchable image URLs, no `cid:`
     * scheme a platform ingesting raw HTML could never resolve). `format=eml` is the
     * self-contained case: a real RFC-822 message built directly with Symfony Mime from the
     * REAL, cid-embedded render (never the preview variant) — subject, text/plain, text/html,
     * and every embed re-attached as an inline part keyed to the exact `cid` already burned
     * into the HTML, the identical pairing {@see \Heisenberg\Mail\HeisenbergMailable} does for
     * a live send. Any `format` other than the literal `eml` defaults to `html`.
     */
    public function export(Request $request, string $post): BaseResponse
    {
        $model = $this->findOrFail($post);
        abort_unless($model->type === 'email', 404);
        Gate::forUser($this->actor($request))->authorize('view', $model);

        $locale = app()->getLocale();
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

    private function findOrFail(string $post): Post
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);

        return $class::query()->with('blocks')->findOrFail($post);
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }
}
