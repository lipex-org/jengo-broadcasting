<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Channels\PresenceChannel;
use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Security\ChannelAuthorizer;
use PHPUnit\Framework\TestCase;

class ChannelAuthorizerTest extends TestCase
{
    protected ChannelAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorizer = new ChannelAuthorizer();
    }

    public function test_authorizes_matching_wildcard_pattern(): void
    {
        $this->authorizer->channel('orders.{id}', static function ($user, int $id): bool {
            return $user['id'] === 1 && $id === 42;
        });

        $user = ['id' => 1, 'name' => 'Alice'];

        $this->assertTrue($this->authorizer->authorize('orders.42', $user));
        $this->assertTrue($this->authorizer->authorize(new PrivateChannel('orders.42'), $user));
        $this->assertTrue($this->authorizer->authorize('private-orders.42', $user));

        // Wrong ID
        $this->assertFalse($this->authorizer->authorize('orders.99', $user));

        // Unauthorized user
        $unauthorizedUser = ['id' => 2, 'name' => 'Bob'];
        $this->assertFalse($this->authorizer->authorize('orders.42', $unauthorizedUser));
    }

    public function test_authorizes_presence_channel_with_array_payload(): void
    {
        $this->authorizer->channel('chat.{room}', static function ($user, string $room): array|false {
            if ($room === 'general') {
                return [
                    'id'   => (string) $user['id'],
                    'name' => $user['name'],
                ];
            }
            return false;
        });

        $user = ['id' => 10, 'name' => 'Charlie'];

        $result = $this->authorizer->authorize(new PresenceChannel('chat.general'), $user);
        $this->assertIsArray($result);
        $this->assertSame('10', $result['id']);
        $this->assertSame('Charlie', $result['name']);

        // Forbidden room
        $this->assertFalse($this->authorizer->authorize(new PresenceChannel('chat.secret'), $user));
    }

    public function test_returns_false_when_no_user_provided_or_resolved(): void
    {
        $this->authorizer->channel('public-events', static fn() => true);

        $this->assertFalse($this->authorizer->authorize('public-events', null));
    }

    public function test_normalizes_channel_names(): void
    {
        $this->assertSame('orders.1', $this->authorizer->normalizeChannelName('private-orders.1'));
        $this->assertSame('chat.room', $this->authorizer->normalizeChannelName('presence-chat.room'));
        $this->assertSame('public-feed', $this->authorizer->normalizeChannelName('public-feed'));
    }
}
