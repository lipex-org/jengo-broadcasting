<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Channels\Channel;
use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;
use Jengo\Broadcasting\Security\ChannelAuthorizer;
use Jengo\Broadcasting\Testing\BroadcastFake;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class SampleOrderShipped implements ShouldBroadcast
{
    public function __construct(public int $orderId, public string $carrier)
    {
    }

    public function broadcastOn(): Channel|array
    {
        return new PrivateChannel('orders.' . $this->orderId);
    }

    public function broadcastAs(): string
    {
        return 'OrderShipped';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'carrier'  => $this->carrier,
        ];
    }
}

class BroadcastFakeTest extends TestCase
{
    protected BroadcastFake $fake;
    protected ChannelAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorizer = new ChannelAuthorizer();
        $this->fake = new BroadcastFake($this->authorizer);
    }

    public function test_assert_nothing_broadcasted(): void
    {
        $this->fake->assertNothingBroadcasted();
    }

    public function test_records_and_asserts_broadcasted_event(): void
    {
        $event = new SampleOrderShipped(101, 'DHL');
        $this->fake->recordEvent($event, ['private-orders.101'], 'OrderShipped', ['order_id' => 101, 'carrier' => 'DHL']);

        $this->fake->assertBroadcasted('OrderShipped');
        $this->fake->assertBroadcasted(SampleOrderShipped::class);
        $this->fake->assertBroadcasted(SampleOrderShipped::class, static function ($e) {
            return $e->orderId === 101 && $e->carrier === 'DHL';
        });

        $this->fake->assertBroadcastedTimes('OrderShipped', 1);
        $this->fake->assertBroadcastedTo('private-orders.101', 'OrderShipped');
        $this->fake->assertNotBroadcasted('RefundIssued');
    }

    public function test_assert_broadcasted_throws_when_event_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->fake->assertBroadcasted('MissingEvent');
    }

    public function test_assert_channel_authorized_and_unauthorized(): void
    {
        $this->authorizer->channel('orders.{id}', static fn($user, int $id) => $id === 5);

        $user = ['id' => 1];

        $this->fake->assertChannelAuthorized('orders.5', $user);
        $this->fake->assertChannelUnauthorized('orders.99', $user);
    }
}
