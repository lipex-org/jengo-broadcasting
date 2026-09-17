# Jengo Broadcasting

Real-time event broadcasting subsystem for CodeIgniter 4 and the Jengo Framework.

Documentation: https://lipex-org.github.io/jengophp.com/packages/broadcasting

> [!WARNING]
> **Active Development / Experimental**: This package and its built-in development WebSocket daemon (`php spark broadcast:serve`) are in active development and are **not production-ready**.

## Installation

```bash
composer require jengo/broadcasting
php spark jengo:install broadcasting
```

## Quick Start

```php
use Jengo\Broadcasting\Broadcast;
use App\Events\OrderPlaced;

// 1. Dispatch an event implementing ShouldBroadcast
broadcast(new OrderPlaced($order->id, $order->total));

// 2. Direct ad-hoc fluent broadcast
Broadcast::on('orders')
    ->as('OrderPlaced')
    ->with(['id' => 101, 'total' => 450.00])
    ->toOthers()
    ->send();

// 3. Testing double
Broadcast::fake();
// ... run application code ...
Broadcast::assertBroadcasted(OrderPlaced::class);
```

## Local Development Server

Run the built-in WebSocket daemon for local testing:

```bash
php spark broadcast:serve
```

## Documentation

For full documentation on multi-driver setup (SSE, Soketi, Pusher, Redis, Ably), channel authorization, practical application patterns, and testing fakes, visit https://lipex-org.github.io/jengophp.com/packages/broadcasting.

## License

Released under the MIT License.
