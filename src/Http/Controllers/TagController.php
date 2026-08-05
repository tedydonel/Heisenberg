<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Http\Requests\StoreTagRequest;
use Heisenberg\Http\Requests\UpdateTagRequest;
use Heisenberg\Models\Tag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * REST CRUD for the tag taxonomy (blueprint §9.5 `TaxonomyController`, tag
 * half; §10.1 `BlogTagPolicy` — "identical" to `BlogCategoryPolicy`). Same
 * GuestActor/LocalDevRoleGate authorization idiom as CategoryController/
 * PostController — see actor() below.
 */
class TagController
{
    /** GET /editor/tags — flat, alphabetical list. */
    public function index(Request $request): JsonResponse
    {
        $class = $this->tagClass();
        Gate::forUser($this->actor($request))->authorize('viewAny', $class);

        $query = $class::query();
        $this->applySearch($query, $request);

        $tags = $query->orderBy('name_en')->get();

        return response()->json([
            'tags' => $tags->map(fn (Tag $tag) => $this->payload($tag))->all(),
        ]);
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $class = $this->tagClass();
        Gate::forUser($this->actor($request))->authorize('create', $class);

        $tag = $class::create($request->only(['name_en', 'name_fr', 'slug']));

        return response()->json(['tag' => $this->payload($tag)], 201);
    }

    public function update(UpdateTagRequest $request, string $tag): JsonResponse
    {
        $model = $this->findOrFail($tag);
        Gate::forUser($this->actor($request))->authorize('update', $model);

        $model->fill($request->only(['name_en', 'name_fr', 'slug']));
        $model->save();

        return response()->json(['tag' => $this->payload($model->fresh())]);
    }

    /** DELETE /editor/tags/{tag} — detaches from every post first (blueprint TaxonomyService::deleteTag), then soft-deletes. */
    public function destroy(Request $request, string $tag): JsonResponse
    {
        $model = $this->findOrFail($tag);
        Gate::forUser($this->actor($request))->authorize('delete', $model);

        $model->posts()->detach();
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

    /** @return array<string, mixed> */
    private function payload(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name_en' => $tag->name_en,
            'name_fr' => $tag->name_fr,
            'slug' => $tag->slug,
            'created_at' => $tag->created_at?->toIso8601String(),
            'updated_at' => $tag->updated_at?->toIso8601String(),
        ];
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Tag> */
    private function tagClass(): string
    {
        return (string) config('heisenberg.models.tag', Tag::class);
    }

    private function findOrFail(string $id): Tag
    {
        $class = $this->tagClass();

        return $class::query()->findOrFail($id);
    }
}
