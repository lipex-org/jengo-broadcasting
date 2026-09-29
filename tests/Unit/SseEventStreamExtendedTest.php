<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Support\PresenceMember;
use Jengo\Broadcasting\Support\SseEventStream;
use PHPUnit\Framework\TestCase;

class SseEventStreamExtendedTest extends TestCase
{
    public function testFormatEventWithFullParameters(): void
    {
        $event = SseEventStream::formatEvent(
            event: 'UserLoggedIn',
            data: ['userId' => 42, 'name' => 'Alice'],
            id: 'evt_12345',
            retry: 5000
        );

        $this->assertStringContainsString("retry: 5000\n", $event);
        $this->assertStringContainsString("id: evt_12345\n", $event);
        $this->assertStringContainsString("event: UserLoggedIn\n", $event);
        $this->assertStringContainsString('data: {"userId":42,"name":"Alice"}' . "\n\n", $event);
    }

    public function testFormatEventWithStringData(): void
    {
        $event = SseEventStream::formatEvent('Ping', 'pong');
        $this->assertSame("event: Ping\ndata: pong\n\n", $event);
    }

    public function testFormatHeartbeat(): void
    {
        $hbDefault = SseEventStream::formatHeartbeat();
        $this->assertSame(": heartbeat\n\n", $hbDefault);

        $hbCustom = SseEventStream::formatHeartbeat('keep-alive-sync');
        $this->assertSame(": keep-alive-sync\n\n", $hbCustom);
    }

    public function testPresenceMemberSerialization(): void
    {
        $member = new PresenceMember(99, ['name' => 'Bob', 'role' => 'admin', 'avatar' => 'https://example.com/avatar.png']);

        $array = $member->toArray();
        $this->assertSame('99', $array['user_id']);
        $this->assertSame('Bob', $array['user_info']['name']);
        $this->assertSame('admin', $array['user_info']['role']);
    }
}
