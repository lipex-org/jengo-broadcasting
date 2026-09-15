<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Drivers\SseBroadcaster;
use Jengo\Broadcasting\Support\SseEventStream;
use PHPUnit\Framework\TestCase;

class SseBroadcasterTest extends TestCase
{
    protected SseBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();
        SseBroadcaster::clearMemoryBuffer();
        $this->broadcaster = new SseBroadcaster([
            'cache_prefix' => 'sse_test_' . uniqid(),
            'heartbeat'    => 10,
            'retry'        => 2000,
        ]);
    }

    public function test_broadcasts_and_pulls_events(): void
    {
        $this->broadcaster->broadcast(['orders'], 'OrderPlaced', ['id' => 101, 'amount' => 500]);
        $this->broadcaster->broadcast(['orders', 'notifications'], 'OrderApproved', ['id' => 101]);

        $events = $this->broadcaster->pullEvents(['orders']);
        $this->assertCount(2, $events);
        $this->assertSame('OrderPlaced', $events[0]['event']);
        $this->assertSame(101, $events[0]['payload']['id']);

        $lastId = $events[0]['id'];
        $newEvents = $this->broadcaster->pullEvents(['orders'], $lastId);
        $this->assertCount(1, $newEvents);
        $this->assertSame('OrderApproved', $newEvents[0]['event']);
    }

    public function test_formats_sse_event_frame(): void
    {
        $frame = SseEventStream::formatEvent('ItemAdded', ['name' => 'Widget'], '123', 3000);

        $this->assertStringContainsString("retry: 3000\n", $frame);
        $this->assertStringContainsString("id: 123\n", $frame);
        $this->assertStringContainsString("event: ItemAdded\n", $frame);
        $this->assertStringContainsString('data: {"name":"Widget"}', $frame);
    }

    public function test_formats_heartbeat(): void
    {
        $heartbeat = SseEventStream::formatHeartbeat();
        $this->assertSame(": heartbeat\n\n", $heartbeat);
    }
}
