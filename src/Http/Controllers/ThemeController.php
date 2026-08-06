<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Services\ThemeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Style panel backend: read + write the user theme (design tokens).
 * Validation lives in ThemeRepository — invalid payloads return 422 with
 * the per-token error list and nothing is written.
 *
 * Routed at `GET`/`PUT /editor/theme` (routes/editor.php) under the editor's own
 * config('heisenberg.middleware.editor') gate — its original home was the builder's
 * route group (`PUT`/`GET /builder/theme`), removed with the builder (2026-08-02);
 * re-mounted here 2026-08-03 as the Style/Themes panel's real backend.
 *
 * SECURITY: writes the single, global site theme and used to carry no
 * authorization at all. `update()` now requires the `admins` role tier (see
 * config('heisenberg.roles')) via the same RoleGate contract the media
 * library policy uses, wrapped in the SAME local-dev-only bypass as the
 * media library (see LocalDevRoleGate) so a plain `testbench serve` /
 * test-suite session — which has no logged-in user — keeps working.
 * `show()` (read-only) is intentionally left open; only the write endpoint
 * was the reported hole.
 */
class ThemeController
{
    public function __construct(private ThemeRepository $themes)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'theme' => $this->themes->load(),
            'css' => $this->themes->css(),
            'tokens' => $this->themes->tokens(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        if (! $roleGate->is($actor, 'admins')) {
            return response()->json(['saved' => false, 'errors' => ['You are not authorized to update the site theme.']], 403);
        }

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['saved' => false, 'errors' => ['Body must be a JSON theme object']], 422);
        }

        $result = $this->themes->save($payload);
        if (! $result['saved']) {
            return response()->json(['saved' => false, 'errors' => $result['errors']], 422);
        }

        return response()->json([
            'saved' => true,
            'theme' => $result['theme'],
            'css' => $this->themes->css($result['theme']),
            'tokens' => $this->themes->tokens($result['theme']),
        ]);
    }
}
