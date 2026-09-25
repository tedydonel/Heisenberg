<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Models\AiChatMessage;
use Heisenberg\Models\AiConversation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The assistant's memory of its EARLIER conversations with one author.
 *
 * Each chat used to see only its own history, so asking "do you remember our last conversation?"
 * got an honest "no" — although every thread is stored ({@see AiConversation}). This builds a
 * compact digest of the author's other recent threads for the current turn: the ones about the
 * post being edited first, then the rest, newest first, each with its date, title, opening prompt
 * and last few turns. It is scoped with the one ownership rule the history endpoints use
 * ({@see AiConversation::scopeOwnedBy()}), so an author is never reminded of another's chats, and
 * it is built server-side — the client cannot inject or fake it.
 */
class AiConversationMemory
{
    /** How many earlier conversations the digest covers. */
    private const CONVERSATIONS = 6;

    /** Turns quoted from the end of each conversation, besides its opening prompt. */
    private const TAIL_TURNS = 4;

    private const TURN_CHARS = 220;

    /** Hard ceiling for the whole digest, so memory can never crowd out the document itself. */
    private const MAX_CHARS = 6000;

    /**
     * @return string '' when the author has no other conversations — or when they cannot be read
     */
    public function digest(int|string|null $authorId, int|string|null $currentConversationId = null, int|string|null $postId = null): string
    {
        // Memory is an extra, never a precondition: a host whose conversation tables are missing
        // or unreachable still gets a working assistant, just one without recall.
        try {
            return $this->build($authorId, $currentConversationId, $postId);
        } catch (QueryException) {
            return '';
        }
    }

    private function build(int|string|null $authorId, int|string|null $currentConversationId, int|string|null $postId): string
    {
        $conversations = AiConversation::query()
            ->ownedBy($authorId)
            ->when($currentConversationId !== null && $currentConversationId !== '', fn ($q) => $q->where('id', '!=', (int) $currentConversationId))
            ->whereHas('messages')
            ->when($postId !== null && $postId !== '', fn ($q) => $q->orderByRaw('CASE WHEN post_id = ? THEN 0 ELSE 1 END', [(int) $postId]))
            ->latest('updated_at')
            ->limit(self::CONVERSATIONS)
            ->get();

        $sections = [];
        $used = 0;
        foreach ($conversations as $conversation) {
            $section = $this->section($conversation, $postId);
            if ($section === '') {
                continue;
            }
            if ($used + strlen($section) > self::MAX_CHARS) {
                break;
            }
            $sections[] = $section;
            $used += strlen($section);
        }

        return implode("\n\n", $sections);
    }

    private function section(AiConversation $conversation, int|string|null $postId): string
    {
        $messages = $conversation->messages()->orderBy('id')->get(['role', 'content'])->all();
        if ($messages === []) {
            return '';
        }

        // The opening prompt says what the thread was about; the tail says where it ended up.
        $quoted = count($messages) <= self::TAIL_TURNS + 1
            ? $messages
            : [$messages[0], ...array_slice($messages, -self::TAIL_TURNS)];

        $where = match (true) {
            $conversation->post_id === null => 'no post',
            $postId !== null && (int) $conversation->post_id === (int) $postId => 'this post',
            default => "post {$conversation->post_id}",
        };
        $date = $conversation->updated_at?->toDateString() ?? '';
        $title = Str::limit(trim((string) $conversation->title), 70);

        $lines = ["- {$date} ({$where}): {$title}"];
        foreach ($quoted as $index => $message) {
            if ($index === 1 && count($messages) > count($quoted)) {
                $lines[] = '    …';
            }
            $lines[] = '    ' . ($message->role === 'user' ? 'Author' : 'You') . ': ' . $this->excerpt($message);
        }

        return implode("\n", $lines);
    }

    private function excerpt(AiChatMessage $message): string
    {
        $text = (string) preg_replace('/\s+/', ' ', strip_tags((string) $message->content));

        return Str::limit(trim($text), self::TURN_CHARS);
    }
}
