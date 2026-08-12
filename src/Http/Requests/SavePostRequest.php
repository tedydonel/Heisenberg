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

        // Autosave never transitions (PostController skips it outright), so scheduled_at's
        // extra rules only apply to a real, explicit `status: scheduled` request.
        $schedulingNow = ! $this->boolean('autosave') && $this->input('status') === 'scheduled';

        return [
            // Untitled drafts are legitimate — PostController supplies the fallback title on
            // create; only type/length are enforced here.
            'title_en' => $isUpdate ? ['sometimes', 'nullable', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', Rule::in(['en', 'fr'])],
            'excerpt_en' => ['sometimes', 'nullable', 'string'],
            'excerpt_fr' => ['sometimes', 'nullable', 'string'],

            // docs/email-system.md §3/§7-E3 — shape-checked here like every other field; whether
            // it's actually APPLIED (create only, ignored on update — a document never changes
            // type) is PostController::save()'s call, not this request's.
            'type' => ['sometimes', 'nullable', Rule::in(['post', 'email'])],

            // Lifecycle intent — never mass-assigned; PostController validates the transition
            // and the actor's authority before touching status/published_at/scheduled_at.
            'status' => ['sometimes', 'nullable', Rule::in($statuses)],
            'scheduled_at' => [
                Rule::requiredIf($schedulingNow),
                'sometimes', 'nullable', 'date',
                // A schedule in the past isn't a schedule — only enforced on the transition
                // that actually sets it, so an unrelated content save carrying the post's
                // already-past scheduled_at back unchanged is never rejected.
                ...($schedulingNow ? ['after:now'] : []),
            ],
            // The displayed post date — deliberately unlike scheduled_at, both past AND future
            // are legal here (backdating, or presetting a still-draft post's eventual date, is
            // the point). Shape only; PostController::applyPublishedAt() gates WHO may set it
            // (the same `editors` tier a publish transition requires) and never mass-assigns it.
            'published_at' => ['sometimes', 'nullable', 'date'],

            'content_version' => $isUpdate
                ? ['required', 'integer', 'min:0']
                : ['sometimes', 'integer', 'min:0'],

            // SEO/Social panel (docs/seo-system.md §3, Wave S2a) — non-autosave only
            // (PostController::save() gates the same way slug/published_at already do), mapped
            // to the POST ROW'S OWN locale columns by PostController::applySeo(). `schema_data`
            // is deliberately NOT accepted here — the panel has no control for it (it's a richer
            // structured-data surface reserved for the AI/MCP tools, docs/seo-system.md §6), and
            // accepting an arbitrary array from this endpoint would let it bypass whatever shape
            // validation that surface eventually applies.
            'seo' => ['sometimes', 'array'],
            'seo.meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.meta_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.og_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.og_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.focus_keyphrase' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.og_image' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Full URL or empty — DECISION (docs/seo-system.md §3): a relative path isn't
            // accepted. `<link rel="canonical">` is specified against an absolute URL (Google's
            // own guidance), and SeoAnalyzer's canonicalCheck()/the sitemap's hreflang alternates
            // both treat "set" as "a real absolute reference" — a bare `/my-post` would need
            // silent host-prepending somewhere downstream to actually work, which is exactly the
            // kind of implicit behavior this package avoids elsewhere (see url_template's own
            // explicit host convention, docs/seo-system.md §5). Laravel's `url` rule itself
            // rejects '', so the empty case is carved out by hand rather than relying on it.
            'seo.canonical_url' => ['sometimes', 'nullable', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    $fail('The canonical URL must be a full URL (https://…) or left empty.');
                }
            }],
            'seo.robots_index' => ['sometimes', 'boolean'],
            'seo.robots_follow' => ['sometimes', 'boolean'],
            'seo.in_sitemap' => ['sometimes', 'boolean'],
            'seo.schema_type' => ['sometimes', 'nullable', 'string', 'max:255'],

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
