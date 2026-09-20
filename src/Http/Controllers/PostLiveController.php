<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * LIVE EDITOR UPDATES — parity for externally-authored content (docs: the gap this closes is
 * that an MCP client's create_post/update_post/write_canvas write reaches the database but an
 * editor tab already open on that post never learns about it until a manual reload; the
 * in-editor AI assistant has no such gap because it calls window.hbEditor.replaceDoc() /
 * applyCanvasWrite() directly in the same tab while streaming).
 *
 * MECHANISM CHOICE — polling, not SSE/broadcasting: this package ships no broadcast
 * dependency (no Pusher/Reverb/websockets) and must not add one. An SSE endpoint would hold a
 * PHP worker open for the lifetime of every open editor tab; on the bundled `php -S` dev
 * server (single-threaded by default) that can starve the ENTIRE app the moment two tabs are
 * open, which is a worse failure mode than the staleness this feature fixes. A tiny polling
 * endpoint returning only the post's `content_version` is one cheap, stateless request every
 * few seconds — cheap enough on any deployment target (dev server, php-fpm, octane) and trivial
 * to disable/tune via config('heisenberg.editor.live_refresh').
 *
 * WHY NOT REUSE PostController::show() FOR THE POLL ITSELF: that endpoint returns the full
 * post + block tree on every call — needless bytes/serialization for a request whose only job,
 * nine polls out of ten, is "has anything changed at all?". This endpoint returns just the
 * version (+ an ETag so a client can send `If-None-Match` and get a bodyless 304 on repeat
 * polls); the editor only calls PostController::show() the moment it actually needs the new
 * content, via heisenberg.editor.posts.show — the SAME endpoint the rest of the editor already
 * uses to load a post, so there is exactly one "fetch a post" code path, not two.
 *
 * AUTHORIZATION: identical gate to PostController::show() (`PostPolicy::view`) — a poller must
 * never leak a draft/scheduled/archived post's version to a viewer who couldn't load its
 * content anyway. A soft-deleted (trashed) or genuinely missing post 404s, same as a viewer
 * would see from any other post-scoped route — the client-side poller stops on 404, which is
 * the "trashed while a tab is open" degrade-safely case in this feature's spec.
 */
class PostLiveController
{
    public function status(Request $request, string $post): JsonResponse
    {
        // The feature can be switched off host-wide without touching any route/view — the
        // client-side poller also reads this same config key and never starts a timer at all
        // when it's off, but a stray/cached request must still resolve harmlessly rather than
        // leak version data an operator explicitly asked to keep this endpoint from serving.
        if (! config('heisenberg.editor.live_refresh.enabled', true)) {
            return response()->json(['enabled' => false], 200);
        }

        $class = $this->postClass();
        $model = $class::query()->find($post);

        if ($model === null) {
            // Covers both "no such id" and "soft-deleted" (default query scope excludes
            // trashed rows) — the client treats a 404 as "stop polling", not an error to surface.
            return response()->json(['message' => 'Not found.'], 404);
        }

        $actor = $this->actor($request);
        if (! Gate::forUser($actor)->allows('view', $model)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $version = (int) $model->content_version;
        $etag = '"hb-cv-' . $version . '"';

        // Conditional GET: a client that already knows this exact version sends it back as
        // If-None-Match and gets a bodyless 304 instead of re-parsing an identical small JSON
        // payload. Optional from the client's side — a plain GET with no header still works.
        if ($this->matchesEtag($request, $etag)) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json([
            'content_version' => $version,
        ], 200, ['ETag' => $etag, 'Cache-Control' => 'no-store']);
    }

    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = trim((string) $request->headers->get('If-None-Match', ''));

        return $header !== '' && $header === $etag;
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in for "no logged-in user" —
     * same posture as PostController::actor(), so this endpoint is gated identically to the
     * rest of the editor's post API under the local-dev anonymous bypass.
     */
    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Post> */
    private function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }
}
