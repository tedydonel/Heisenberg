<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\AuditSink;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default audit sink: a no-op, so an app without an audit system still transitions
 * posts. Ship a SpatieAuditSink (M4) when `spatie/laravel-activitylog` is present.
 * (Blueprint §8.5, §13.4)
 */
class NullAuditSink implements AuditSink
{
    public function record(?Authenticatable $actor, object $subject, array $properties, string $message): void
    {
    }
}
