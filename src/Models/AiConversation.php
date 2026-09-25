<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use App\Models\User;
use Heisenberg\Services\AiConversationMemory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread in the editor's AI panel. Owned by an author, optionally
 * attached to a post, titled after its first user prompt. The turns live in
 * {@see AiChatMessage}; deleting the conversation cascades to them at the
 * database layer.
 *
 * @property int $id
 * @property int|null $post_id
 * @property int|string|null $author_id
 * @property string|null $title
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
        return $this->belongsTo(config('heisenberg.user_model', User::class), 'author_id');
    }

    /** @return HasMany<AiChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'conversation_id');
    }

    /**
     * The conversations one author owns — the ONE ownership rule, shared by the history endpoints
     * and the assistant's memory ({@see AiConversationMemory}), so an author
     * can never list, reopen or be reminded of another's threads. A guest actor (local dev) owns
     * the rows with a null author_id.
     *
     * @param Builder<AiConversation> $query
     * @return Builder<AiConversation>
     */
    public function scopeOwnedBy(Builder $query, int|string|null $authorId): Builder
    {
        return $authorId === null
            ? $query->whereNull('author_id')
            : $query->where('author_id', $authorId);
    }
}
