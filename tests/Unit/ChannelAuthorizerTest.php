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

    public function test_authorizes_with_zero_integer_wildcard(): void
    {
        $this->authorizer->channel('rooms.{id}', static function ($user, int $id): bool {
            return $id === 0;
        });

        $user = ['id' => 1];

        // Should successfully cast '0' to int 0 without TypeError
        $this->assertTrue($this->authorizer->authorize('rooms.0', $user));
        $this->assertFalse($this->authorizer->authorize('rooms.1', $user));
    }

    public function test_preserves_padded_numeric_strings_and_floats(): void
    {
        $this->authorizer->channel('items.{code}', static function ($user, string $code): bool {
            return $code === '007';
        });

        $user = ['id' => 1];
        $this->assertTrue($this->authorizer->authorize('items.007', $user));
    }

    public function test_authorizes_multiple_wildcards(): void
    {
        $this->authorizer->channel('users.{userId}.orders.{orderId}', static function ($user, int $userId, int $orderId): bool {
            return $user['id'] === $userId && $orderId === 999;
        });

        $user = ['id' => 5];
        $this->assertTrue($this->authorizer->authorize('users.5.orders.999', $user));
        $this->assertFalse($this->authorizer->authorize('users.5.orders.123', $user));
        $this->assertFalse($this->authorizer->authorize('users.4.orders.999', $user));
    }

    public function test_user_resolver_callback(): void
    {
        $this->authorizer->setUserResolver(static fn() => ['id' => 77, 'role' => 'admin']);

        $this->authorizer->channel('admin.dashboard', static function ($user): bool {
            return ($user['role'] ?? null) === 'admin';
        });

        // Passing null user should resolve user from custom resolver
        $this->assertTrue($this->authorizer->authorize('admin.dashboard', null));
    }

    public function test_has_channel_and_get_channels_with_options(): void
    {
        $this->assertFalse($this->authorizer->hasChannel('orders.{id}'));

        $this->authorizer->channel('orders.{id}', static fn() => true, ['guard' => 'api']);

        $this->assertTrue($this->authorizer->hasChannel('orders.{id}'));
        $this->assertTrue($this->authorizer->hasChannel('private-orders.{id}'));

        $channels = $this->authorizer->getChannels();
        $this->assertArrayHasKey('orders.{id}', $channels);
        $this->assertSame('api', $channels['orders.{id}']['options']['guard']);
    }

    public function test_unmatched_channel_returns_false(): void
    {
        $user = ['id' => 1];
        $this->assertFalse($this->authorizer->authorize('non.existent.channel', $user));
    }
}
