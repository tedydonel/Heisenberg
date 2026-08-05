<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * PUT /editor/categories/{category} (blueprint §9.5 `TaxonomyController`,
 * category half: update). See StoreCategoryRequest for the shared
 * authorize()/no-slug-uniqueness-rule rationale.
 *
 * Parent-reassignment CYCLE prevention (a category can't become its own
 * parent, nor a descendant's child) is NOT validated here — it needs the
 * specific category instance CategoryController has already resolved, so it
 * lives in CategoryController::update() instead (mirrors SavePostRequest's
 * own split: shape here, instance-specific checks in the controller).
 */
class UpdateCategoryRequest extends BaseTaxonomyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->existingCategoryRule()],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'description_fr' => ['sometimes', 'nullable', 'string'],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    private function existingCategoryRule(): Exists
    {
        return Rule::exists(config('heisenberg.tables.categories', 'heisenberg_categories'), 'id')->withoutTrashed();
    }
}
