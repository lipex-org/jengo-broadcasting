<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Security\ChannelAuthorizer;
use PHPUnit\Framework\TestCase;

class ChannelAuthorizerExtendedTest extends TestCase
{
    public function testParameterizedChannelAuthorizationSuccess(): void
    {
        $authorizer = new ChannelAuthorizer();
        $authorizer->setUserResolver(fn() => (object) ['id' => 42, 'name' => 'John']);

        $authorizer->channel('users.{id}.orders.{orderId}', function ($user, $id, $orderId) {
            return (int) $user->id === (int) $id && $orderId === 'ORD-999';
        });

        $authorized = $authorizer->authorize('private-users.42.orders.ORD-999');
        $this->assertTrue($authorized);

        $unauthorized = $authorizer->authorize('private-users.42.orders.ORD-OTHER');
        $this->assertFalse($unauthorized);

        // Leading zeroes in channel parameters are preserved as strings
        $authorizer->channel('devices.{serial}', function ($user, $serial) {
            return $serial === '007';
        });
        $this->assertTrue($authorizer->authorize('devices.007'));
    }

    public function testPresenceChannelAuthorizationReturnsUserInfo(): void
    {
        $authorizer = new ChannelAuthorizer();
        $authorizer->setUserResolver(fn() => (object) ['id' => 10, 'name' => 'Alice']);

        $authorizer->channel('chat.{roomId}', function ($user, $roomId) {
            if ($roomId === 'general') {
                return ['id' => $user->id, 'name' => $user->name];
            }
            return false;
        });

        $authPayload = $authorizer->authorize('presence-chat.general');
        $this->assertIsArray($authPayload);
        $this->assertSame(10, $authPayload['id']);
        $this->assertSame('Alice', $authPayload['name']);

        $failedPayload = $authorizer->authorize('presence-chat.secret');
        $this->assertFalse($failedPayload);
    }
}
