<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Http\Requests\StoreCategoryRequest;
use Heisenberg\Http\Requests\UpdateCategoryRequest;
use Heisenberg\Models\Category;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * REST CRUD for the category taxonomy (blueprint §9.5 `TaxonomyController`,
 * category half; §10.1 `BlogCategoryPolicy`) — an adopter-facing JSON API.
 * Every action authorizes via {@see \Heisenberg\Policies\CategoryPolicy}
 * (registered against Category::class in HeisenbergServiceProvider), using
 * the SAME GuestActor/LocalDevRoleGate idiom PostController uses (see
 * actor() below) since these routes sit behind
 * config('heisenberg.middleware.editor'), unauthenticated by default.
 *
 * Thin, like MediaLibraryController: no service layer (none exists for
 * taxonomy in this package — src/Services/** is another agent's concurrent
 * work), so straightforward CRUD lives directly against the Eloquent model,
 * mirroring how PostController itself works directly against Post.
 */
class CategoryController
{
    /** GET /editor/categories — flat list, ordered like blueprint's TaxonomyService::listCategories (order, name_en). */
    public function index(Request $request): JsonResponse
    {
        $class = $this->categoryClass();
        Gate::forUser($this->actor($request))->authorize('viewAny', $class);

        $query = $class::query();
        $this->applySearch($query, $request);

        $categories = $query->orderBy('order')->orderBy('name_en')->get();

        return response()->json([
            'categories' => $categories->map(fn (Category $category) => $this->payload($category))->all(),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $class = $this->categoryClass();
        Gate::forUser($this->actor($request))->authorize('create', $class);

        $category = $class::create($request->only([
            'parent_id', 'name_en', 'name_fr', 'slug', 'description_en', 'description_fr', 'order',
        ]));

        return response()->json(['category' => $this->payload($category)], 201);
    }

    public function update(UpdateCategoryRequest $request, string $category): JsonResponse
    {
        $model = $this->findOrFail($category);
        Gate::forUser($this->actor($request))->authorize('update', $model);

        if ($request->filled('parent_id')) {
            $error = $this->parentAssignmentError($model, (int) $request->input('parent_id'));
            if ($error !== null) {
                return response()->json(['message' => $error], 422);
            }
        }

        $model->fill($request->only([
            'parent_id', 'name_en', 'name_fr', 'slug', 'description_en', 'description_fr', 'order',
        ]));
        $model->save();

        return response()->json(['category' => $this->payload($model->fresh())]);
    }

    /** DELETE /editor/categories/{category} — promotes children to root first (blueprint TaxonomyService::deleteCategory), then soft-deletes. */
    public function destroy(Request $request, string $category): JsonResponse
    {
        $model = $this->findOrFail($category);
        Gate::forUser($this->actor($request))->authorize('delete', $model);

        $model->children()->update(['parent_id' => null]);
        $model->delete();

        return response()->json(['deleted' => true]);
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search): void {
            $q->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_fr', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");
        });
    }

    /**
     * Cycle-prevention pair (blueprint TaxonomyService::updateCategory): a
     * category cannot be its own parent, nor have one of its own descendants
     * assigned as its parent.
     */
    private function parentAssignmentError(Category $category, int $parentId): ?string
    {
        if ($parentId === (int) $category->getKey()) {
            return 'A category cannot be its own parent.';
        }

        if (in_array($parentId, $category->getDescendantIds(), true)) {
            return 'Cannot assign a descendant as the parent.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name_en' => $category->name_en,
            'name_fr' => $category->name_fr,
            'slug' => $category->slug,
            'description_en' => $category->description_en,
            'description_fr' => $category->description_fr,
            'order' => $category->order,
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same
     * "no logged-in user at all" convention PostController uses, since these
     * routes sit behind config('heisenberg.middleware.editor') (default
     * ['web']), i.e. unauthenticated by default. CategoryPolicy wraps its
     * RoleGate in LocalDevRoleGate for exactly this reason.
     */
    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Category> */
    private function categoryClass(): string
    {
        return (string) config('heisenberg.models.category', Category::class);
    }

    private function findOrFail(string $id): Category
    {
        $class = $this->categoryClass();

        return $class::query()->findOrFail($id);
    }
}
