<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Attributes\BroadcastAs;
use Jengo\Broadcasting\Attributes\BroadcastOn;
use Jengo\Broadcasting\Attributes\BroadcastWith;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Config\Broadcasting as BroadcastingConfig;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;
use PHPUnit\Framework\TestCase;

// Test event with attributes
#[BroadcastOn(['orders', 'admin'])]
#[BroadcastAs('order.placed.event')]
#[BroadcastWith(['orderId', 'amount'])]
class AttributeDecoratedEvent
{
    public int $orderId = 123;
    public float $amount = 99.95;
    public string $internalSecret = 'do-not-broadcast';
    public string $uninitializedProp;
}

// Test event with string channel attribute
#[BroadcastOn('single-channel')]
class StringChannelAttributeEvent
{
    public string $message = 'hello';
}

// Test event where methods override attributes
#[BroadcastOn('attr-channel')]
#[BroadcastAs('attr.name')]
#[BroadcastWith(['attrProp'])]
class MethodPrecedenceEvent implements ShouldBroadcast
{
    public string $methodProp = 'from-method';
    public string $attrProp = 'from-attr';

    public function broadcastOn(): array
    {
        return ['method-channel'];
    }

    public function broadcastAs(): string
    {
        return 'method.name';
    }

    public function broadcastWith(): array
    {
        return ['custom' => $this->methodProp];
    }
}

// Test event with broadcastWhen hook
#[BroadcastOn('conditional-channel')]
class ConditionalEvent
{
    public function __construct(public bool $shouldBroadcast) {}

    public function broadcastWhen(): bool
    {
        return $this->shouldBroadcast;
    }
}

// Test event with socket ID property
#[BroadcastOn('socket-channel')]
class SocketExclusionEvent
{
    public ?string $socketId = 'socket-abc-123';
    public string $content = 'data';
}

// Test event defaulting to short name and all public properties
#[BroadcastOn('default-channel')]
class DefaultReflectionEvent
{
    public string $title = 'Default Title';
    public int $count = 42;
    protected string $hidden = 'hidden';
    private string $private = 'private';
}

class EventAttributesTest extends TestCase
{
    protected BroadcastManager $manager;
    protected array $dispatched = [];
    protected ?string $capturedSocketId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->capturedSocketId = null;

        $config = new BroadcastingConfig();
        $config->default = 'mock';

        $this->manager = new BroadcastManager($config);

        $dispatched = &$this->dispatched;
        $capturedSocketId = &$this->capturedSocketId;

        $mockBroadcaster = new class($dispatched, $capturedSocketId) implements BroadcasterInterface {
            public function __construct(public array &$dispatched, public mixed &$capturedSocketId) {}
            public function broadcast(array $channels, string $event, array $payload = []): void
            {
                $this->dispatched[] = [
                    'channels' => $channels,
                    'event'    => $event,
                    'payload'  => $payload,
                ];
            }
            public function setSocketId(?string $socketId): static
            {
                $this->capturedSocketId = $socketId;
                return $this;
            }
            public function auth(\CodeIgniter\HTTP\IncomingRequest $request): \CodeIgniter\HTTP\ResponseInterface
            {
                throw new \BadMethodCallException();
            }
            public function validAuthenticationResponse(\CodeIgniter\HTTP\IncomingRequest $request, mixed $result): mixed
            {
                throw new \BadMethodCallException();
            }
        };

        $this->manager->extend('mock', static fn() => $mockBroadcaster);
    }

    public function test_attribute_decorated_event_resolves_correctly(): void
    {
        $event = new AttributeDecoratedEvent();
        $this->manager->event($event);

        $this->assertCount(1, $this->dispatched);
        $record = $this->dispatched[0];

        $this->assertSame(['orders', 'admin'], $record['channels']);
        $this->assertSame('order.placed.event', $record['event']);
        $this->assertSame([
            'orderId' => 123,
            'amount'  => 99.95,
        ], $record['payload']);
        $this->assertArrayNotHasKey('internalSecret', $record['payload']);
        $this->assertArrayNotHasKey('uninitializedProp', $record['payload']);
    }

    public function test_string_channel_attribute(): void
    {
        $event = new StringChannelAttributeEvent();
        $this->manager->event($event);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame(['single-channel'], $this->dispatched[0]['channels']);
        $this->assertSame('StringChannelAttributeEvent', $this->dispatched[0]['event']);
        $this->assertSame(['message' => 'hello'], $this->dispatched[0]['payload']);
    }

    public function test_methods_take_precedence_over_attributes(): void
    {
        $event = new MethodPrecedenceEvent();
        $this->manager->event($event);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame(['method-channel'], $this->dispatched[0]['channels']);
        $this->assertSame('method.name', $this->dispatched[0]['event']);
        $this->assertSame(['custom' => 'from-method'], $this->dispatched[0]['payload']);
    }

    public function test_broadcast_when_cancels_broadcast_when_false(): void
    {
        $eventFalse = new ConditionalEvent(false);
        $this->manager->event($eventFalse);
        $this->assertCount(0, $this->dispatched);

        $eventTrue = new ConditionalEvent(true);
        $this->manager->event($eventTrue);
        $this->assertCount(1, $this->dispatched);
    }

    public function test_socket_id_property_is_passed_to_broadcaster(): void
    {
        $event = new SocketExclusionEvent();
        $this->manager->event($event);

        $this->assertSame('socket-abc-123', $this->capturedSocketId);
        $this->assertCount(1, $this->dispatched);
    }

    public function test_default_event_reflection(): void
    {
        $event = new DefaultReflectionEvent();
        $this->manager->event($event);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame(['default-channel'], $this->dispatched[0]['channels']);
        $this->assertSame('DefaultReflectionEvent', $this->dispatched[0]['event']);
        $this->assertSame([
            'title' => 'Default Title',
            'count' => 42,
        ], $this->dispatched[0]['payload']);
    }
}
