<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Testing\Concerns;

use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\Testing\BroadcastFake;

trait BroadcastTestAssertionsTrait
{
    /**
     * Replace the bound broadcaster with an in-memory test double.
     */
    protected function fakeBroadcast(): BroadcastFake
    {
        return Broadcast::fake();
    }
}
