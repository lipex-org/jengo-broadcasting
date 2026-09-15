<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Channels\Channel;
use Jengo\Broadcasting\Channels\PresenceChannel;
use Jengo\Broadcasting\Channels\PrivateChannel;
use PHPUnit\Framework\TestCase;

class ChannelTest extends TestCase
{
    public function test_public_channel_name_and_string_representation(): void
    {
        $channel = new Channel('orders');

        $this->assertSame('orders', $channel->getName());
        $this->assertSame('orders', (string) $channel);
    }

    public function test_private_channel_prefixes_with_private(): void
    {
        $channel = new PrivateChannel('orders.101');

        $this->assertSame('orders.101', $channel->getName());
        $this->assertSame('private-orders.101', (string) $channel);

        // If 'private-' is passed explicitly, it does not double prefix
        $alreadyPrefixed = new PrivateChannel('private-users.42');
        $this->assertSame('users.42', $alreadyPrefixed->getName());
        $this->assertSame('private-users.42', (string) $alreadyPrefixed);
    }

    public function test_presence_channel_prefixes_with_presence(): void
    {
        $channel = new PresenceChannel('chat.room.5');

        $this->assertSame('chat.room.5', $channel->getName());
        $this->assertSame('presence-chat.room.5', (string) $channel);

        // If 'presence-' is passed explicitly, it does not double prefix
        $alreadyPrefixed = new PresenceChannel('presence-room.lobby');
        $this->assertSame('room.lobby', $alreadyPrefixed->getName());
        $this->assertSame('presence-room.lobby', (string) $alreadyPrefixed);
    }
}
