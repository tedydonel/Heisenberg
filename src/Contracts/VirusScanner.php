<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

/**
 * Scans an uploaded file's TEMPORARY path (never the final public path) before
 * MediaLibraryService writes anything to the `uploads` disk. Decouples the media
 * library from any concrete scanner (ClamAV/clamd in production) so a host can
 * swap providers without touching the upload pipeline. (Blueprint §5.1)
 */
interface VirusScanner
{
    /**
     * Scan the file at the given (temporary) path.
     *
     * @return string one of 'clean', 'infected', 'suspicious', 'unavailable'.
     *                Any non-'clean' result blocks the upload; MediaLibraryService
     *                decides how 'unavailable' is handled (fail closed by default,
     *                fail open only when config('heisenberg.media.scan.fail_open')
     *                is true).
     */
    public function scan(string $path): string;
}
