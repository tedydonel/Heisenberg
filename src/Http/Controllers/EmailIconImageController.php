<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\IconLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * POST /editor/email-icon — stores the PNG an email document needs for one icon block.
 *
 * WHY A PNG AT ALL (docs/email-system.md §4): mail clients do not render SVG, so the icon block
 * used to be excluded from the email palette outright. It ships as a raster image instead, and
 * `EmailRenderer::rewriteImages()` turns that `<img>` into the same inline `cid:` part every
 * email image already uses — so an icon arrives in the body, not as a visible attachment.
 *
 * WHY THE BROWSER RASTERIZES IT: converting SVG to PNG in PHP needs Imagick with an SVG delegate
 * (GD cannot do it at all), which is not present on every host and is far too heavy a dependency
 * to force on one. The editor already has the glyph, its colour and its pixel size on screen, so
 * it draws them to a canvas and posts the bytes here. This endpoint's job is to distrust them:
 *
 *  - the icon must be a manifest-listed "<set>/<slug>" (the same fail-closed allow-list the
 *    picker and the canvas runtime use), so the stored name can never be attacker-chosen;
 *  - the colour must be a plain hex and the size a sane pixel count;
 *  - the body must decode to a REAL PNG of the expected 2x dimensions (checked by reading the
 *    decoded bytes' own header, never by trusting the declared type), under a hard byte cap.
 *
 * The file name is derived — `email-icons/<set>-<slug>-<hex>-<size>.png` — so the same icon at
 * the same colour and size is written once and reused by every block, document and send that
 * wants it, and a rename or re-render never accumulates junk. These are generated artifacts, not
 * uploads, so they live beside the media library rather than in it: nothing generated shows up
 * in the author's Media panel.
 */
class EmailIconImageController
{
    /** A 2x PNG of a 512px icon is far under this; anything larger is not an icon. */
    private const MAX_PNG_BYTES = 512 * 1024;

    /** Matches the icon block's own sane range — below 8px there is nothing to see. */
    private const MIN_SIZE = 8;

    private const MAX_SIZE = 512;

    public const DIRECTORY = 'email-icons';

    public function __construct(private IconLibraryService $icons)
    {
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthor($request)) {
            return $denied;
        }

        $reference = trim((string) $request->input('icon', ''));
        if (! $this->icons->exists($reference)) {
            return response()->json(['errors' => ['Unknown icon reference.']], 422);
        }

        $color = strtolower(trim((string) $request->input('color', '')));
        if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            return response()->json(['errors' => ['Colour must be a #rrggbb hex value.']], 422);
        }

        $size = (int) $request->input('size', 0);
        if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
            return response()->json(['errors' => ['Size must be between 8 and 512 pixels.']], 422);
        }

        $path = self::DIRECTORY . '/' . $this->fileName($reference, $color, $size);
        $disk = (string) config('heisenberg.media.disk', 'uploads');

        // Already rendered — the whole point of the derived name. Nothing is decoded or written.
        if (Storage::disk($disk)->exists($path)) {
            return response()->json($this->payload($disk, $path, $size), 200);
        }

        $png = base64_decode((string) $request->input('png', ''), true);
        if ($png === false || $png === '' || strlen($png) > self::MAX_PNG_BYTES) {
            return response()->json(['errors' => ['PNG payload missing or too large.']], 422);
        }

        // The bytes' own header decides what this is — never the request's word for it.
        $info = @getimagesizefromstring($png);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return response()->json(['errors' => ['Payload is not a PNG image.']], 422);
        }
        if ((int) $info[0] !== $size * 2 || (int) $info[1] !== $size * 2) {
            return response()->json(['errors' => ['PNG must be exactly twice the icon size.']], 422);
        }

        Storage::disk($disk)->put($path, $png);

        return response()->json($this->payload($disk, $path, $size), 201);
    }

    /**
     * Derived, not chosen: every part is already validated (manifest-listed reference, hex
     * colour, integer size), so the result cannot escape the directory or collide across icons.
     */
    private function fileName(string $reference, string $color, int $size): string
    {
        [$set, $slug] = explode('/', $reference, 2);

        return $set . '-' . $slug . '-' . ltrim($color, '#') . '-' . $size . '.png';
    }

    /** @return array<string, mixed> */
    private function payload(string $disk, string $path, int $size): array
    {
        return [
            'url' => PublicFile::urlForPath($disk, $path),
            'width' => $size,
            'height' => $size,
        ];
    }

    private function denyUnlessAuthor(Request $request): ?JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        if (! $roleGate->is($actor, 'authors')) {
            return response()->json(['errors' => ['You are not authorized to write email icons.']], 403);
        }

        return null;
    }
}
