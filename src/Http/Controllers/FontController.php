<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Services\FontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Font picker backend: search the vendored public-font catalog. */
class FontController
{
    public function __construct(private FontCatalogService $fonts)
    {
    }

    public function search(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $limit = min(80, max(1, (int) $request->query('limit', 40)));
        $offset = max(0, (int) $request->query('offset', 0));

        $fonts = $this->fonts->search($query, $limit, $offset);

        return response()->json([
            'total' => $this->fonts->count(),
            'fonts' => $fonts,
            'has_more' => count($fonts) === $limit,
        ]);
    }
}
