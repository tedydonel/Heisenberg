<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * The output of {@see \Heisenberg\Services\EmailBatchExporter::export()}
 * (.hermes/plans/2026-08-25_190059-email-template-variables.md, Task 6) —
 * a deliberately NARROW value object: the on-disk zip path, the file /
 * recipient counts, and the resolved locale list. NOTHING ELSE.
 *
 * The locked decision is explicit: a recipient value, a runtime map, a
 * formatter exception message, or any other host data NEVER appears on this
 * DTO. The result is what the controller turns into a streamed response —
 * any payload attached here would be a leak surface for the same reasons
 * {@see EmailVariableResolutionException}'s aggregated `failures`/`keys`
 * shape never carries values.
 *
 * Fields are `readonly` so an accidental mutation post-construction is a
 * hard error; tests assert the property set is exactly these four.
 */
final class EmailBatchExportResult
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        public readonly string $path,
        public readonly int $fileCount,
        public readonly int $recipientCount,
        public readonly array $locales,
    ) {
    }
}
