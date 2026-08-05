<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Services\SavedThemeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Themes tab "Save to Themes" backend — the user's named theme library
 * (SavedThemeRepository), distinct from ThemeController's single active theme.
 * Routed at `/editor/themes` (routes/editor.php). Same authorization posture as
 * ThemeController: index() (read-only) is open, the two writes require the
 * `admins` tier via the same RoleGate + LocalDevRoleGate local-only bypass —
 * see ThemeController's docblock for why the bypass exists.
 */
class SavedThemeController
{
    public function __construct(private SavedThemeRepository $themes)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['themes' => $this->themes->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAdmin($request)) !== null) {
            return $denied;
        }

        $name = (string) $request->input('name', '');
        $theme = $request->input('theme', []);
        if (! is_array($theme)) {
            return response()->json(['saved' => false, 'errors' => ['theme must be an object']], 422);
        }

        $result = $this->themes->save($name, $theme);
        if (! $result['saved']) {
            return response()->json(['saved' => false, 'errors' => $result['errors']], 422);
        }

        return response()->json(['saved' => true, 'name' => $result['name'], 'themes' => $result['themes']]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAdmin($request)) !== null) {
            return $denied;
        }

        $themes = $this->themes->delete((string) $request->input('name', ''));

        return response()->json(['saved' => true, 'themes' => $themes]);
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        if (! $roleGate->is($actor, 'admins')) {
            return response()->json(['saved' => false, 'errors' => ['You are not authorized to manage the theme library.']], 403);
        }

        return null;
    }
}
