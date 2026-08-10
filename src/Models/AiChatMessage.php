<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of an {@see AiConversation} — role `user` or `assistant`, the text
 * as the transcript showed it, and a `meta` bag of display extras (reasoning,
 * tools used, blocks built). Named AiChatMessage because `Heisenberg\Ai\AiMessage`
 * already exists as the in-memory wire DTO — this is the persisted transcript
 * row, a different thing.
 */
class AiChatMessage extends Model
{
    public const ROLES = ['user', 'assistant'];

    protected $fillable = ['conversation_id', 'role', 'content', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function getTable(): string
    {
        return config('heisenberg.tables.ai_messages', 'heisenberg_ai_messages');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
