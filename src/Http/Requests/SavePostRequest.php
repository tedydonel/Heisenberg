<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

use Heisenberg\Services\BlocksPayloadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the editor's save envelope for POST/PUT /editor/posts[/{post}]:
 * the post-level fields (title/locale/excerpt/status intent/content_version)
 * via plain rules(), and the block tree via {@see BlocksPayloadService}
 * (blueprint §3.6) wired into the same validator instance so a block-level
 * failure surfaces through Laravel's normal 422 `errors` map — dot-path keyed
 * exactly like BlocksPayloadService's own `errorMap` (e.g. `blocks.0.attributes.level`).
 *
 * WHO may act on the target post is NOT decided here: `authorize()` only
 * gates on payload SHAPE, not on the specific Post instance, because doing a
 * per-instance ownership/tier check requires the configured Post model class
 * (config('heisenberg.models.post')) and the loaded (or about-to-be-created)
 * row — context PostController has once it resolves the route, but that a
 * FormRequest's authorize() (which runs before the controller method) does
 * not cleanly have without duplicating that resolution. PostController runs
 * the real PostPolicy check immediately after this request validates.
 */
class SavePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // PUT /editor/posts/{post} carries a route `post` parameter; POST
        // /editor/posts (creation) does not — used only to decide whether
        // `content_version` (the optimistic-lock value the client loaded) is
        // required. Missing it on a genuine update is a malformed request
        // (422), distinct from a PRESENT-but-stale version (409, decided by
        // PostController inside its transaction).
        $isUpdate = $this->route('post') !== null;

        $statuses = array_keys((array) config('heisenberg.lifecycle.transitions', []));

        return [
            // `title_en` is NOT NULL with no default at the DB level (see
            // create_heisenberg_posts_table), but an untitled draft is legitimate — a user who
            // starts typing content before naming the post must still be able to save. It was
            // `required` on create, which meant the very first Save from a fresh editor (empty
            // title) 422'd; and because the client only starts autosaving once a post id exists,
            // that one failure also meant autosave never activated at all. PostController
            // supplies the fallback title on create; here we only enforce the type/length.
            'title_en' => $isUpdate ? ['sometimes', 'nullable', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', Rule::in(['en', 'fr'])],
            'excerpt_en' => ['sometimes', 'nullable', 'string'],
            'excerpt_fr' => ['sometimes', 'nullable', 'string'],

            // Lifecycle intent — never mass-assigned; PostController validates
            // the transition (config('heisenberg.lifecycle.transitions')) and
            // the actor's authority (PostPolicy::transitionAllowed()) before
            // ever touching status/published_at/scheduled_at on the model.
            'status' => ['sometimes', 'nullable', Rule::in($statuses)],
            'scheduled_at' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('autosave') && $this->input('status') === 'scheduled'),
                'sometimes', 'nullable', 'date',
            ],

            'content_version' => $isUpdate
                ? ['required', 'integer', 'min:0']
                : ['sometimes', 'integer', 'min:0'],

            // The BlocksPayloadService envelope (blueprint §3.6) — shape-checked
            // here too so a missing/wrong-typed key reports as a normal 422
            // field error before the (more expensive) contract-aware pass below.
            'schemaVersion' => ['required', 'integer'],
            'registryHash' => ['required', 'string'],
            'computedStyles' => ['sometimes', 'nullable', 'string'],
            'autosave' => ['sometimes', 'boolean'],
            // `present`, not `required`: Laravel's `required` rejects an empty array, which made
            // a post unsavable the moment its last block was deleted. The key must still be sent
            // (so an omitted `blocks` never silently preserves the old tree on a full replace),
            // but `[]` is a legitimate document — an emptied post.
            'blocks' => ['present', 'array'],
        ];
    }

    /**
     * Restore the request body exactly as the client sent it.
     *
     * Laravel's stock `web` middleware group runs ConvertEmptyStringsToNull before routing, and
     * it walks the payload RECURSIVELY — so every empty string anywhere in the tree becomes null.
     * For a block editor that is destructive: a heading's untouched `anchor`, `titleAttr`,
     * `extraClasses` and `animate` attributes all default to "", arrive as null, and
     * BlocksPayloadService correctly rejects them ("expected type string"). The result was that
     * saving ANY block with an unset optional text attribute — i.e. essentially every block —
     * failed with a 422 that named attributes the user had never touched.
     *
     * A per-key patch can't fix this (it was previously done for `computedStyles` alone); the
     * transform applies to arbitrarily deep, contract-defined keys we can't enumerate. So for a
     * JSON request we re-merge the raw decoded body, which the middleware never saw. This also
     * keeps `$request->only(...)` honest for every downstream consumer, not just validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            return;
        }

        $raw = json_decode((string) $this->getContent(), true);
        if (is_array($raw)) {
            $this->merge($raw);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only run the contract-aware pass once the envelope's own shape
            // is already sound — otherwise BlocksPayloadService would report
            // confusing secondary errors (e.g. "blocks must be an array") on
            // top of the primary one rules() already caught.
            if ($validator->errors()->has('blocks') || $validator->errors()->has('schemaVersion') || $validator->errors()->has('registryHash')) {
                return;
            }

            // Laravel's stock 'web' middleware group runs ConvertEmptyStringsToNull
            // BEFORE routing ever happens, so a client that sends
            // `computedStyles: ""` (an entirely reasonable "no computed
            // styles yet" value) arrives here as `computedStyles: null` —
            // BlocksPayloadService treats an ABSENT key as fine but a
            // present-and-null one as a type error. Normalize null back to
            // "absent" here (BlocksPayloadService itself is out of scope for
            // this change) so that stock middleware transform never
            // manifests as a spurious 422.
            $payload = $this->all();
            if (array_key_exists('computedStyles', $payload) && $payload['computedStyles'] === null) {
                unset($payload['computedStyles']);
            }

            $result = app(BlocksPayloadService::class)->validatePayload($payload);
            if ($result['valid']) {
                return;
            }

            foreach ($result['errorMap'] as $path => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($path, $message);
                }
            }
        });
    }
}
