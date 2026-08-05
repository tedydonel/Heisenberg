<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Records an audit entry for a status transition, decoupling Heisenberg from
 * Spatie activitylog's global `activity()` helper. The default adapter is a
 * no-op so an app without an audit system still transitions posts.
 * (Blueprint §8.5, §13.4)
 */
interface AuditSink
{
    /**
     * @param array<string, mixed> $properties
     */
    public function record(?Authenticatable $actor, object $subject, array $properties, string $message): void;
}
