<?php

declare(strict_types=1);

namespace Heisenberg\Mail;

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Support\EmailRenderResult;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File as MimeFile;

/**
 * The bundled Mailable (docs/email-system.md §6): construct it from a post id (and optional
 * locale) and it is ready for `Mail::to($recipient)->send(...)` — subject, HTML, plain-text and
 * every embedded image already resolved via {@see EmailRenderer}. Container-resolvable with no
 * config: `EmailRenderer` and `Heisenberg\Models\Post` (config-swappable the same way every
 * other model class in this package is, `heisenberg.models.post`) are the only dependencies,
 * both already bound by the package's service provider.
 *
 * NOT `ShouldQueue` by construction choice — a host that wants this queued wraps it
 * (`Mail::to(...)->queue(new HeisenbergMailable(...))` still works without it, or a host
 * subclass adds the interface) rather than this package forcing a queue connection to exist.
 *
 * HTML/TEXT are set via the base `Mailable::html()`/`text()` methods directly in the
 * constructor, not the newer `content(): Content` DSL — deliberately: `Content` accepts a
 * pre-rendered HTML string (`htmlString`) but has NO raw-string equivalent for `text`, only a
 * Blade view name, and this Mailable's content is never a view, it is `EmailRenderer`'s already-
 * finished string. Wrapping the plain text in `Illuminate\Support\HtmlString` (which the base
 * `Mailer` already special-cases via `Htmlable`, exactly the mechanism `Content::htmlString`
 * itself relies on for HTML) gets the identical "ship this string verbatim, render no view"
 * behavior for text too, using ordinary public API — no `Mailable` internals overridden.
 *
 * EMBEDS (§2, §5): each entry in {@see EmailRenderResult::$embeds} is attached via
 * `withSymfonyMessage()` — the supported hook for reaching the underlying
 * `Symfony\Component\Mime\Email` before send — as an INLINE `DataPart` with its Content-ID set
 * to the EXACT `cid` `EmailRenderer` already wrote into the HTML's `src="cid:$cid"`
 * (`Illuminate\Mail\Attachment`'s `attachments()` array has no way to pin a specific Content-ID,
 * only Symfony's own `DataPart::setContentId()` does).
 */
class HeisenbergMailable extends Mailable
{
    public readonly EmailRenderResult $result;

    public function __construct(int|string $postId, ?string $locale = null)
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);
        /** @var Post $post */
        $post = $class::query()->findOrFail($postId);

        $locale = $locale !== null && LocaleConfig::isValid($locale) ? $locale : LocaleConfig::default();

        $this->result = app(EmailRenderer::class)->render($post, $locale);

        $this->subject($this->result->subject);
        $this->html($this->result->html);
        $this->text(new HtmlString($this->result->text));

        foreach ($this->result->embeds as $embed) {
            $this->withSymfonyMessage(function (SymfonyEmail $message) use ($embed): void {
                $part = (new DataPart(new MimeFile($embed['path']), null, $embed['mime']))
                    ->asInline()
                    ->setContentId($embed['cid']);

                $message->addPart($part);
            });
        }
    }
}
