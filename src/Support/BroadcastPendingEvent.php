<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Support;

use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Events\GenericBroadcastEvent;
use Jengo\Broadcasting\Security\SocketHeaderResolver;

class BroadcastPendingEvent
{
    /**
     * @var array<int, ChannelInterface|string>
     */
    protected array $channels = [];

    protected string $event = 'GenericEvent';

    /**
     * @var array<string, mixed>
     */
    protected array $payload = [];

    protected ?string $socketId = null;

    public function __construct(
        protected BroadcastManager $manager,
        array|ChannelInterface|string $channels = []
    ) {
        if (! empty($channels)) {
            $this->on($channels);
        }
    }

    /**
     * Set the channels to broadcast on.
     *
     * @param array<int, ChannelInterface|string>|ChannelInterface|string $channels
     */
    public function on(array|ChannelInterface|string $channels): self
    {
        $this->channels = is_array($channels) ? $channels : [$channels];
        return $this;
    }

    /**
     * Set the event name.
     */
    public function as(string $event): self
    {
        $this->event = $event;
        return $this;
    }

    /**
     * Set the payload data.
     *
     * @param array<string, mixed> $payload
     */
    public function with(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * Exclude the current user's socket from receiving the broadcast.
     */
    public function toOthers(): self
    {
        $this->socketId = SocketHeaderResolver::resolve();
        return $this;
    }

    /**
     * Explicitly set the socket ID to exclude.
     */
    public function socket(?string $socketId): self
    {
        $this->socketId = $socketId;
        return $this;
    }

    /**
     * Dispatch the broadcasted event.
     */
    public function send(): void
    {
        $event = new GenericBroadcastEvent(
            $this->channels,
            $this->event,
            $this->payload,
            $this->socketId
        );

        $this->manager->event($event);
    }
}
