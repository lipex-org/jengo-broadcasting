<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Events;

use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Contracts\ShouldBroadcastNow;

class GenericBroadcastEvent implements ShouldBroadcastNow
{
    /**
     * @param array<int, ChannelInterface|string>|ChannelInterface|string $channels
     * @param string                                                      $event
     * @param array<string, mixed>                                        $payload
     * @param string|null                                                 $socketId Socket ID to exclude from broadcast
     */
    public function __construct(
        public array|ChannelInterface|string $channels,
        public string $event,
        public array $payload = [],
        public ?string $socketId = null
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array|ChannelInterface|string
    {
        return $this->channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return $this->event;
    }

    /**
     * Get the data that should be broadcasted with the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
