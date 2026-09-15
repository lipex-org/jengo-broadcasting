<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Contracts;

/**
 * Marker interface indicating the event should be broadcast immediately
 * on the current request cycle without queueing.
 */
interface ShouldBroadcastNow extends ShouldBroadcast
{
}
