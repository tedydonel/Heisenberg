<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

/**
 * Strips executable content from SVG markup before MediaLibraryService writes
 * it to the public `uploads` disk. An .svg is an XML document, not a raster
 * image — it can carry an inline `<script>`, an `on*` event-handler
 * attribute, a `<foreignObject>` embedding arbitrary HTML, or an external
 * reference, any of which runs when the file is later opened directly (its
 * own origin, its own cookies) with a real `image/svg+xml` content type.
 * Without sanitization, allowing `.svg` in `heisenberg.media.extensions` is a
 * stored-XSS hole, not a format choice.
 *
 * Deliberately NO bundled default adapter, unlike {@see VirusScanner} and
 * every other seam in this file — see `config('heisenberg.media.svg_sanitizer')`'s
 * docblock for why a permissive "null" implementation would defeat the point
 * entirely. A host that wants `.svg` uploads must bind a real sanitizer (e.g.
 * a wrapper around enshrined/svg-sanitize) here; until then,
 * MediaLibraryService refuses every `.svg`/`.svgz` upload outright, however
 * `heisenberg.media.extensions` is configured.
 */
interface SvgSanitizer
{
    /**
     * Returns SAFE SVG markup derived from `$svg` — scripts, event handlers,
     * foreignObject content and external references stripped. The result is
     * written to disk verbatim; this method is the ENTIRE defense, so an
     * implementation must fail closed (throw, or return inert/empty markup)
     * rather than return anything it isn't confident is safe.
     */
    public function sanitize(string $svg): string;
}
