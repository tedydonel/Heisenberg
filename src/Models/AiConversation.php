<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread in the editor's AI panel. Owned by an author, optionally
 * attached to a post, titled after its first user prompt. The turns live in
 * {@see AiChatMessage}; deleting the conversation cascades to them at the
 * database layer.
 */
class AiConversation extends Model
{
    protected $fillable = ['post_id', 'author_id', 'title'];

    public function getTable(): string
    {
        return config('heisenberg.tables.ai_conversations', 'heisenberg_ai_conversations');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.models.post', Post::class), 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.user_model', \App\Models\User::class), 'author_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'conversation_id');
    }
}
