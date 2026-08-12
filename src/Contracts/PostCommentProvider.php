<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Supplies a post's discussion thread, decoupling the "comments" post-template capability
 * from any concrete comment system. Native storage now exists — `config('heisenberg.tables.comments')`'s
 * `heisenberg_comments` table backs {@see \Heisenberg\Models\Comment}, and
 * {@see \Heisenberg\Adapters\NativeCommentProvider} is the default binding at
 * `heisenberg.post_template.comments_provider` (docs/post-template-schema.md "Comments/discussion").
 * A host may still bind {@see \Heisenberg\Adapters\NullPostCommentProvider} to disable
 * comments entirely, or its own class to integrate an external system (Disqus, a hosted
 * service, a different table) — the contract, not the storage, is what a template renders
 * against, so any implementation is a drop-in replacement.
 *
 * ## The `Item` shape
 *
 * Every comment `thread()`/`submit()` returns is this shape:
 *
 * ```
 * array{
 *   id: int|string,
 *   parent_id: int|string|null,
 *   author_name: string,
 *   body: string,
 *   created_at: mixed,          // a Carbon instance for the native provider; any host-chosen
 *                                // representation is legal for a custom adapter
 *   replies: list<Item>,        // always oldest-first, at every level of nesting
 * }
 * ```
 *
 * `replies` is only ever populated on the `Item`s `thread()` returns — `submit()`'s returned
 * `Item` always carries an empty `replies` (a just-created comment has none yet).
 */
interface PostCommentProvider
{
    /**
     * The post's thread: APPROVED top-level comments only, each with its APPROVED replies
     * nested underneath (recursively, to whatever depth exists). A reply whose own parent is
     * not approved is simply absent — it is never promoted or reparented, it just doesn't
     * render. Top-level comments are ordered by `$sortOrder` ('newest' first or 'oldest'
     * first); replies are ALWAYS oldest-first regardless of `$sortOrder`, at every nesting
     * level — `$sortOrder` only ever controls the top-level ordering.
     *
     * @return array{count: int, items: list<array{id: int|string, parent_id: int|string|null, author_name: string, body: string, created_at: mixed, replies: list<array<string, mixed>>}>}
     *   `count` is the total number of APPROVED comments on the post, top-level AND replies
     *   combined — it is not merely `count(items)`, since replies nest inside `items` rather
     *   than sitting alongside them.
     */
    public function thread(Post $post, string $sortOrder = 'newest'): array;

    /** The post's approved comment count (top-level + replies), or 0 when unknown. */
    public function count(Post $post): int;

    /**
     * Submits a new comment or reply. This is storage + minimal structural validation only
     * (parent existence/ownership/depth/status) — request-shape validation (required fields,
     * string lengths, email format, spam/rate-limit checks, auth/guest policy) is an HTTP
     * layer's job, not this contract's.
     *
     * @param array{parent_id?: int|string|null, author_id?: int|string|null, author_name: string, author_email?: string|null, body: string, auto_approve?: bool} $input
     *   `parent_id` (optional, default null) — replying to an existing comment on the SAME
     *   post. `author_id` (optional) — a host user id, when the submitter is authenticated;
     *   omit/null for a guest. `auto_approve` (optional, default false) — when true, the
     *   comment is stored `approved` regardless of the `heisenberg.comments.auto_approve`
     *   config default (a caller-side override, e.g. "a moderator's own reply always
     *   approves").
     * @return array{ok: bool, status: string, comment?: array{id: int|string, parent_id: int|string|null, author_name: string, body: string, created_at: mixed, replies: list<mixed>}, error?: string}
     *   `ok: true` always carries `status` ('pending'|'approved') and `comment` (the created
     *   Item, `replies` always `[]`). `ok: false` always carries `status` ('rejected'|'disabled')
     *   and `error` (a short machine-readable reason, e.g. 'max-depth').
     */
    public function submit(Post $post, array $input): array;
}
