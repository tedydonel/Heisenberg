<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\Pattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The user's saved reusable block compositions — "patterns" — driven by the
 * toolbar's save-as-block icon and read back by the Components panel's Blocks
 * tab (toolbar-composition.md §8). Routed at `/editor/patterns` under the
 * editor's own config('heisenberg.middleware.editor') gate (routes/editor.php),
 * same opt-out posture as the other editor surfaces.
 *
 * The `blocks` column is treated as opaque at this layer: the client sends
 * the JSON it intends to insert (an array of full block models, the shape
 * duplicateBlock produces — id, name, attributes, supports, innerBlocks,
 * schemaVersion), the server validates it IS an array of objects with a
 * `name` on every entry, and writes it verbatim. The runtime's
 * normalizeModel does the fresh-id/re-default dance on insert, so this
 * controller never has to know what makes a valid model — the live runtime
 * owns that contract.
 *
 * Gated on the `authors` tier — the people who can author content at all —
 * through the same RoleGate + LocalDevRoleGate local-only bypass every other
 * editor write uses (see ThemeController's docblock for why the bypass
 * exists). This used to be ungated on the theory that a host widens
 * `middleware.editor`, but that stack defaults to bare `['web']` and every
 * sibling controller carries its own check precisely because of that: left
 * open, an anonymous visitor could write arbitrary JSON into this table and
 * delete every pattern, in any environment. Patterns are install-wide (no
 * owner column), so there is deliberately no per-owner rule on delete: anyone
 * who may author may curate the shared library.
 */
class HeisenbergPatternController
{
    /** Upper bound on one pattern's serialized `blocks` — it is stored verbatim. */
    private const MAX_BLOCKS_BYTES = 512 * 1024;

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthor($request)) {
            return $denied;
        }

        $patterns = Pattern::query()
            ->orderBy('name')
            ->get(['id', 'name', 'blocks', 'created_at', 'updated_at'])
            ->map(static fn (Pattern $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'blocks' => $p->blocks,
                'updated_at' => optional($p->updated_at)->toIso8601String(),
            ])
            ->all();

        return response()->json(['patterns' => $patterns]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthor($request)) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('heisenberg::editor.patterns.name_required')]);
        }
        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => __('heisenberg::editor.patterns.name_too_long')]);
        }

        $blocks = $this->validateBlocks($request->input('blocks'));

        if (Pattern::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => __('heisenberg::editor.patterns.name_taken')]);
        }

        $pattern = Pattern::create([
            'name' => $name,
            'blocks' => $blocks,
        ]);

        return response()->json([
            'saved' => true,
            'pattern' => [
                'id' => (int) $pattern->id,
                'name' => (string) $pattern->name,
                'blocks' => $pattern->blocks,
                'updated_at' => optional($pattern->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthor($request)) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        $pattern = $id > 0 ? Pattern::query()->find($id) : null;
        if (! $pattern) {
            throw ValidationException::withMessages(['id' => __('heisenberg::editor.patterns.not_found')]);
        }

        $pattern->delete();

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    /**
     * Reject anything that isn't a non-empty array of objects, each carrying
     * a non-empty `name`. Every real validation (contract registration,
     * attribute enums, innerBlocks shape) is the runtime's job on insert —
     * this is just the cheap refuse-bad-shapes-up-front guard.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateBlocks(mixed $blocks): array
    {
        if (! is_array($blocks) || $blocks === []) {
            throw ValidationException::withMessages(['blocks' => __('heisenberg::editor.patterns.blocks_required')]);
        }
        foreach ($blocks as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['name']) || ! is_string($entry['name']) || $entry['name'] === '') {
                throw ValidationException::withMessages(['blocks' => __('heisenberg::editor.patterns.blocks_invalid_entry', ['index' => $i + 1])]);
            }
        }

        if (strlen((string) json_encode($blocks)) > self::MAX_BLOCKS_BYTES) {
            throw ValidationException::withMessages(['blocks' => __('heisenberg::editor.patterns.blocks_too_large')]);
        }

        return array_values($blocks);
    }

    private function denyUnlessAuthor(Request $request): ?JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        if (! $roleGate->is($actor, 'authors')) {
            return response()->json(['errors' => ['You are not authorized to use saved patterns.']], 403);
        }

        return null;
    }
}
