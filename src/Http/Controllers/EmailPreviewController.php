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

/**
 * Email authoring's two read-only endpoints (docs/email-system.md §7-E3): a browser-renderable
 * preview of a `type = 'email'` post, and its rendered size for the topbar/footer's size chip.
 * Both run the SAME PostPolicy `view` check {@see PreviewController::showPost()} and
 * {@see EditorController::show()} already run — an email's rendered content is exactly as
 * sensitive as a post's, so an anonymous visitor must not read a draft email by enumerating ids.
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
