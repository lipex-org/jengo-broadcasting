<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Events\GenericBroadcastEvent;
use Jengo\Broadcasting\Testing\BroadcastFake;
use PHPUnit\Framework\TestCase;

class BroadcastPendingEventExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Broadcast::setFacadeRoot(new BroadcastManager());
    }

    public function testBroadcastPendingEventFluentChaining(): void
    {
        $fake = Broadcast::fake();

        Broadcast::on(new PrivateChannel('orders.101'))
            ->as('OrderShipped')
            ->with(['trackingNumber' => 'TRK-9900', 'carrier' => 'DHL'])
            ->toOthers()
            ->send();

        $fake->assertBroadcasted('OrderShipped', function ($event, $channels, $payload) {
            return in_array('private-orders.101', $channels, true)
                && $payload['trackingNumber'] === 'TRK-9900'
                && $payload['carrier'] === 'DHL';
        });
    }

    public function testBroadcastPendingEventMultipleChannels(): void
    {
        $fake = Broadcast::fake();

        Broadcast::on(['chat.room.1', 'chat.room.global'])
            ->as('NewMessage')
            ->with(['message' => 'Hello everyone'])
            ->send();

        $fake->assertBroadcasted('NewMessage', function ($event, $channels) {
            return in_array('chat.room.1', $channels, true) && in_array('chat.room.global', $channels, true);
        });
    }
}
