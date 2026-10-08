<p align="center">
  <a href="https://lipex-org.github.io/jengophp.com/">
    <img src="https://raw.githubusercontent.com/lipex-org/jengophp.com/main/public/logo-full.png" width="220" alt="Jengo Logo">
  </a>
</p>

<h1 align="center">Jengo Broadcasting</h1>

<p align="center">
  <strong>Event-driven real-time publish-subscribe engine for CodeIgniter 4 with zero-daemon Server-Sent Events (SSE) and local WebSocket daemon support.</strong>
</p>

<p align="center">
  <a href="https://lipex-org.github.io/jengophp.com/packages/broadcasting"><strong>Documentation</strong></a> •
  <a href="https://github.com/lipex-org/broadcasting/blob/main/LICENSE"><strong>License</strong></a> •
  <a href="https://github.com/lipex-org/broadcasting/issues"><strong>Issues</strong></a>
</p>

---

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
