# Jengo Broadcasting

Enterprise-grade real-time event broadcasting subsystem for CodeIgniter 4 and the Jengo Framework.

## Features

- **Multi-Driver Architecture**: Pusher Channels, Soketi, Laravel Reverb, Server-Sent Events (SSE), Redis Pub/Sub, Ably, Log, and Null drivers.
- **Pure PHP Server-Sent Events (SSE)**: Built-in, zero-daemon streaming engine delivering real-time events to modern browsers without requiring Node.js or WebSocket background processes.
- **Channel Hierarchy**: Standard public (`Channel`), private (`PrivateChannel`), and presence (`PresenceChannel`) value objects.
- **Declarative Channel Authorization**: Define channel rules via `Broadcast::channel('orders.{id}', fn($user, $id) => ...)` with wildcard parameter binding.
- **Event-Driven & Fluent Broadcasting**: Broadcast domain events implementing `ShouldBroadcast` or dispatch ad-hoc payloads via `Broadcast::on('orders')->as('OrderPlaced')->send()`.
- **Wire-Compatible with Laravel Echo & Pusher JS**: Integrates directly with frontend WebSocket client libraries.
- **Inertia.js Bridge**: Trigger automatic partial reloads on real-time event reception.
- **Testing Double (`Broadcast::fake()`)**: In-memory test double with comprehensive assertion methods.

## Installation

```bash
composer require jengo/broadcasting
php spark jengo:install broadcasting
```

## Basic Usage

### 1. Define Broadcast Events

```php
namespace App\Events;

use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;

class OrderShipped implements ShouldBroadcast
{
    public function __construct(public int $orderId, public string $carrier) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.' . $this->orderId)];
    }

    public function broadcastAs(): string
    {
        return 'OrderDispatched';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'carrier'  => $this->carrier,
        ];
    }
}
```

### 2. Dispatch Broadcasts

```php
// Via global helper
broadcast(new OrderShipped($order->id, 'DHL'));

// Direct ad-hoc broadcast
Broadcast::on('orders')
    ->as('OrderPlaced')
    ->with(['id' => 101])
    ->toOthers()
    ->send();
```

### 3. Register Channel Authorization

In `app/Config/Channels.php`:

```php
use Jengo\Broadcasting\Broadcast;

Broadcast::channel('orders.{id}', function ($user, int $id): bool {
    return (int) $user->id === (int) $id;
});
```

### 4. Testing

```php
public function test_order_broadcasts(): void
{
    $fake = Broadcast::fake();

    $this->shipOrder(42);

    $fake->assertBroadcasted(OrderShipped::class);
    $fake->assertBroadcastedTo('private-orders.42');
}
```
