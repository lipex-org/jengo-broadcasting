<?php

declare(strict_types=1);

use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Support\BroadcastPendingEvent;

if (! function_exists('broadcast')) {
    /**
     * Broadcast an event or start a fluent broadcast definition.
     *
     * @param object|array<int, ChannelInterface|string>|ChannelInterface|string|null $eventOrChannels
     * @param string|null                                                             $event
     * @param array<string, mixed>                                                    $payload
     * @return BroadcastPendingEvent|void
     */
    function broadcast(mixed $eventOrChannels = null, ?string $event = null, array $payload = []): mixed
    {
        if ($eventOrChannels === null) {
            return Broadcast::on([]);
        }

        // Direct event object dispatch: broadcast(new OrderShipped(...))
        if (is_object($eventOrChannels) && ! ($eventOrChannels instanceof ChannelInterface)) {
            Broadcast::event($eventOrChannels);
            return new BroadcastPendingEvent(Broadcast::getFacadeRoot(), []);
        }

        // Ad-hoc channel dispatch: broadcast('orders', 'OrderPlaced', ['id' => 1])
        $pending = Broadcast::on($eventOrChannels);

        if ($event !== null) {
            $pending->as($event);
            if (! empty($payload)) {
                $pending->with($payload);
            }
            $pending->send();
            return null;
        }

        return $pending;
    }
}
