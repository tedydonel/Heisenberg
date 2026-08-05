<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

/**
 * POST /editor/tags (blueprint §9.5 `TaxonomyController`, tag half: store).
 * See StoreCategoryRequest for the shared authorize()/no-slug-uniqueness-rule
 * rationale — it applies identically here.
 */
class StoreTagRequest extends BaseTaxonomyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
