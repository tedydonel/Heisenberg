<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

use Heisenberg\Services\BlocksPayloadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the editor's save envelope for POST/PUT /editor/posts[/{post}]:
 * post-level fields via rules(), the block tree via {@see BlocksPayloadService}
 * on the same validator so block errors surface in the normal 422 map.
 *
 * authorize() is deliberately true — the per-instance PostPolicy check runs in
 * PostController, which has the resolved model this FormRequest does not.
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
        // Update requests must carry content_version (missing = 422; a present-but-stale
        // version is a 409, decided by PostController inside its transaction).
        $isUpdate = $this->route('post') !== null;

        $statuses = array_keys((array) config('heisenberg.lifecycle.transitions', []));

        return [
            // Untitled drafts are legitimate — PostController supplies the fallback title on
            // create; only type/length are enforced here.
            'title_en' => $isUpdate ? ['sometimes', 'nullable', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', Rule::in(['en', 'fr'])],
            'excerpt_en' => ['sometimes', 'nullable', 'string'],
            'excerpt_fr' => ['sometimes', 'nullable', 'string'],

            // Lifecycle intent — never mass-assigned; PostController validates the transition
            // and the actor's authority before touching status/published_at/scheduled_at.
            'status' => ['sometimes', 'nullable', Rule::in($statuses)],
            'scheduled_at' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('autosave') && $this->input('status') === 'scheduled'),
                'sometimes', 'nullable', 'date',
            ],

            'content_version' => $isUpdate
                ? ['required', 'integer', 'min:0']
                : ['sometimes', 'integer', 'min:0'],

            // Envelope shape-checked here so a bad key 422s before the contract-aware pass.
            'schemaVersion' => ['required', 'integer'],
            'registryHash' => ['required', 'string'],
            'computedStyles' => ['sometimes', 'nullable', 'string'],
            'autosave' => ['sometimes', 'boolean'],
            // `present`, not `required`: `[]` is a legitimate emptied post, but the key must
            // still be sent so an omitted tree never silently preserves the old one.
            'blocks' => ['present', 'array'],
        ];
    }

    /**
     * Restore the request body exactly as the client sent it. The `web` group's
     * ConvertEmptyStringsToNull recursively nulls every "" in the tree, which
     * BlocksPayloadService rightly rejects on string attributes — so for JSON
     * requests, re-merge the raw decoded body the middleware never saw.
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
            // Skip the contract-aware pass when the envelope's own shape already failed.
            if ($validator->errors()->has('blocks') || $validator->errors()->has('schemaVersion') || $validator->errors()->has('registryHash')) {
                return;
            }

            // A null computedStyles (middleware-nulled "") must read as absent, not a type error.
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
