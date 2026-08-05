<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

/**
 * PUT /editor/tags/{tag} (blueprint §9.5 `TaxonomyController`, tag half:
 * update). See StoreCategoryRequest for the shared rationale.
 */
class UpdateTagRequest extends BaseTaxonomyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
