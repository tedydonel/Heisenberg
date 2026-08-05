<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * POST /editor/categories (blueprint §9.5 `TaxonomyController`, category
 * half: store). authorize() only gates payload SHAPE — same rationale as
 * SavePostRequest: the actual ability check needs the configured Category
 * model class (config('heisenberg.models.category')), which
 * CategoryController resolves; CategoryController runs
 * CategoryPolicy::create() immediately after this request validates.
 *
 * No `slug` uniqueness validation rule here, deliberately: uniqueness is
 * handled entirely by Category::uniqueSlug() (a numeric-suffix fallback, not
 * a rejection) plus the DB-level unique index — the same division of labor
 * SavePostRequest/Post::uniqueSlug() already use for posts.
 */
class StoreCategoryRequest extends BaseTaxonomyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->existingCategoryRule()],
            'name_en' => ['required', 'string', 'max:255'],
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
