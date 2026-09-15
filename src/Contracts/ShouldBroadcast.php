<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Contracts;

interface ShouldBroadcast
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return ChannelInterface|string|array<int, ChannelInterface|string>
     */
    public function broadcastOn(): ChannelInterface|string|array;
}
