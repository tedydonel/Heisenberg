<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\AiChatMessage;
use Heisenberg\Models\AiConversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chat history for the AI panel — list, reopen, append to and delete the
 * current author's conversations. Routed under `/editor/ai/conversations`
 * (routes/ai.php), same `authors` tier as using the assistant itself.
 *
 * Persistence is client-driven: the panel POSTs each finished turn here rather
 * than the stream endpoint writing mid-flight, so a turn is stored exactly as
 * the transcript showed it (post-reasoning-filter, with its display meta), and
 * an aborted stream stores what survived, not a broken half-frame.
 *
 * Every query is scoped to the requesting author (`owned()`): one author can
 * neither list nor delete another's threads. A guest actor (local dev) owns
 * the rows with a null author_id.
 */
class AiConversationController
{
    /** List the author's conversations, optionally for one post, newest activity first. */
    public function index(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $query = $this->owned($request)->withCount('messages')->latest('updated_at')->limit(100);

        if ($request->filled('post_id')) {
            // Unattached threads (started before the post's first save, so
            // post_id is null) stay listed — filtering them out made history
            // look empty the moment a draft got its id.
            $query->where(function (Builder $q) use ($request): void {
                $q->where('post_id', (int) $request->query('post_id'))->orWhereNull('post_id');
            });
        }

        return response()->json([
            'conversations' => $query->get()->map(static fn (AiConversation $c): array => [
                'id'            => $c->id,
                'title'         => (string) ($c->title ?? ''),
                'post_id'       => $c->post_id,
                'message_count' => (int) $c->messages_count,
                'updated_at'    => $c->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** One conversation with its full transcript, for reopening. */
    public function show(Request $request, int $conversation): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $found = $this->owned($request)->find($conversation);
        if ($found === null) {
            return response()->json(['error' => __('heisenberg::editor.ai.history_not_found')], 404);
        }

        return response()->json([
            'id'       => $found->id,
            'title'    => (string) ($found->title ?? ''),
            'post_id'  => $found->post_id,
            'messages' => $found->messages()->orderBy('id')->get()->map(static fn (AiChatMessage $m): array => [
                'role'       => $m->role,
                'content'    => $m->content,
                'meta'       => $m->meta ?? [],
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Start a new conversation. The first appended user turn will title it. */
    public function store(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $conversation = AiConversation::create([
            'post_id'   => $request->filled('post_id') ? (int) $request->input('post_id') : null,
            'author_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['id' => $conversation->id], 201);
    }

    /** Append one finished turn. */
    public function storeMessage(Request $request, int $conversation): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $found = $this->owned($request)->find($conversation);
        if ($found === null) {
            return response()->json(['error' => __('heisenberg::editor.ai.history_not_found')], 404);
        }

        $role = (string) $request->input('role', '');
        $content = (string) $request->input('content', '');
        if (! in_array($role, AiChatMessage::ROLES, true) || trim($content) === '') {
            return response()->json(['error' => 'role must be user|assistant and content non-empty'], 422);
        }

        $meta = $request->input('meta');
        $message = $found->messages()->create([
            'role'    => $role,
            'content' => $content,
            'meta'    => is_array($meta) ? $meta : null,
        ]);

        // First user turn names the thread; touch() keeps "newest activity
        // first" honest even when only messages changed.
        if (($found->title === null || $found->title === '') && $role === 'user') {
            $found->title = Str::limit(trim($content), 80);
        }
        // A chat begun before the post's first save adopts the id once known.
        if ($found->post_id === null && $request->filled('post_id')) {
            $found->post_id = (int) $request->input('post_id');
        }
        $found->save();
        $found->touch();

        return response()->json(['ok' => true, 'id' => $message->id], 201);
    }

    /** Bulk delete (single delete is a one-item bulk). Confirmation is the UI's job. */
    public function destroy(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if ($ids === []) {
            return response()->json(['deleted' => 0]);
        }

        // Ownership scoping makes this safe: ids belonging to someone else
        // simply don't match, silently — no oracle for other people's threads.
        $deleted = $this->owned($request)->whereIn('id', $ids)->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /** All queries start here — an author only ever sees their own threads. */
    private function owned(Request $request): Builder
    {
        $userId = $request->user()?->getAuthIdentifier();

        return $userId === null
            ? AiConversation::query()->whereNull('author_id')
            : AiConversation::query()->where('author_id', $userId);
    }

    private function denyUnlessAuthor(Request $request): ?JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        return $roleGate->is($actor, 'authors')
            ? null
            : response()->json(['error' => __('heisenberg::editor.ai.not_allowed')], 403);
    }
}
