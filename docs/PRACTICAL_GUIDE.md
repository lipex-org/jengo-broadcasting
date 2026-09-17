# Jengo Broadcasting - Practical Development Guide

> [!CAUTION]
> **DEVELOPMENT / EXPERIMENTAL STATUS NOTICE**
> This package and its built-in pure-PHP WebSocket daemon (`WebSocketServer.php` / `php spark broadcast:serve`) are currently in **active development and experimental status**.
> **IT IS NOT PRODUCTION READY.**
> - The built-in WebSocket server is intended solely for local testing, rapid prototyping, feature verification, and experimental evaluation.
> - It has not undergone formal security audits, long-term socket descriptor leak stress testing, or multi-process clustering hardening.
> - Do not deploy or rely on this built-in WebSocket daemon in mission-critical or production workloads.

---

## Table of Contents
1. [The Broadcasting Mental Model](#1-the-broadcasting-mental-model)
2. [Local Testing Setup (Low RAM / Zero External Services)](#2-local-testing-setup)
3. [App 1: Live Order / Delivery Tracker (Public Channels)](#3-app-1-live-order--delivery-tracker-public-channels)
4. [App 2: Background Job & Export Progress Bar (Private Channels)](#4-app-2-background-job--export-progress-bar-private-channels)
5. [App 3: Collaborative Kanban / Document Updates (Excluding Sender with `toOthers()`)](#5-app-3-collaborative-kanban--document-updates-excluding-sender-with-toothers)
6. [App 4: Flash Sale Stock Counter (High-Frequency Public Channel)](#6-app-4-flash-sale-stock-counter-high-frequency-public-channel)
7. [App 5: Live Polling & Quiz Room (Presence Channels)](#7-app-5-live-polling--quiz-room-presence-channels)
8. [Production Readiness Roadmap](#8-production-readiness-roadmap)

---

## 1. The Broadcasting Mental Model

In standard web applications, data transfer is one-way: the browser requests, and PHP responds. If data changes on the server while the user is looking at a page, the browser cannot know unless it repeatedly polls the server (`setInterval(fetch, 2000)`), which wastes bandwidth, CPU cycles, and database connections.

Broadcasting inverts this paradigm into a **Publish-Subscribe (Pub/Sub)** model:

```
[ PHP Backend Action ]
        │
        ▼ (triggers event)
broadcast(new OrderPlaced($orderId))
        │
        ▼ (driver sends payload)
[ Broadcaster Engine: SSE or WebSocket ]
        │
        ▼ (pushes frame over open socket)
[ Client Browser Frontend ]
        │
        ▼ (receives event within ~10ms)
DOM updates without page reload
```

Every broadcast feature consists of three simple pieces:
1. **The Event Class**: Defines what data to send (`broadcastWith`), what channel to send it to (`broadcastOn`), and what event name to emit (`broadcastAs`).
2. **The Dispatcher**: Where your PHP code triggers the event (`broadcast(new MyEvent(...))`).
3. **The Frontend Listener**: A small JavaScript snippet (native WebSocket or EventSource) that listens for the event name and modifies HTML elements.

---

## 2. Local Testing Setup

You do not need high RAM, Docker, or Node.js to build and test these apps. A single development machine with 4GB-8GB of RAM can easily run everything.

### Starting the Services
1. **HTTP Web Server** (Terminal 1):
   ```bash
   php spark serve --port 8080
   ```
2. **WebSocket Broadcast Server** (Terminal 2):
   ```bash
   php spark broadcast:serve --port 6001
   ```
   *(Memory consumption: under 25 MB RAM)*

3. **Open Two Browser Tabs**:
   - Tab 1: Customer / Viewer View (`http://localhost:8080/...`)
   - Tab 2: Admin / Controller View (`http://localhost:8080/...`)
   - Split your screen side-by-side so you can see live interactions instantly.

---

## 3. App 1: Live Order / Delivery Tracker (Public Channels)

**Objective**: Customer views an order tracking page. When kitchen staff or drivers update status, the customer's progress bar advances in real time.

### Step 1: Create the Event Class
`app/Events/OrderStatusUpdated.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use Jengo\Broadcasting\Channels\Channel;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;

class OrderStatusUpdated implements ShouldBroadcast
{
    public function __construct(
        public string $orderId,
        public string $status,
        public string $description,
        public int $progressPercent
    ) {}

    public function broadcastOn(): Channel
    {
        // Public channel scoped to the specific order
        return new Channel('orders.' . $this->orderId);
    }

    public function broadcastAs(): string
    {
        return 'StatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'         => $this->orderId,
            'status'           => $this->status,
            'description'      => $this->description,
            'progress_percent' => $this->progressPercent,
            'updated_at'       => date('H:i:s'),
        ];
    }
}
```

### Step 2: Create the Controller
`app/Controllers/OrderTracker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Events\OrderStatusUpdated;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class OrderTracker extends Controller
{
    public function show(string $orderId = '101'): string
    {
        return view('order_tracker', ['orderId' => $orderId]);
    }

    public function updateStatus(): ResponseInterface
    {
        $orderId = (string) $this->request->getPost('order_id');
        $status  = (string) $this->request->getPost('status');

        $stages = [
            'received'  => ['Received', 'Order has been placed and received by the kitchen.', 25],
            'cooking'   => ['Cooking', 'The chef is preparing your meal.', 50],
            'delivering'=> ['Out for Delivery', 'Driver is on the way to your address.', 75],
            'delivered' => ['Delivered', 'Enjoy your meal!', 100],
        ];

        $stage = $stages[$status] ?? $stages['received'];

        $event = new OrderStatusUpdated($orderId, $stage[0], $stage[1], $stage[2]);
        broadcast($event);

        return $this->response->setJSON(['status' => 'success', 'data' => $event->broadcastWith()]);
    }
}
```

### Step 3: Create the Client View
`app/Views/order_tracker.php`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Order Tracker #<?= esc($orderId) ?></title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; }
        .bar-container { background: #eee; border-radius: 8px; overflow: hidden; height: 24px; }
        .bar-fill { background: #0066cc; height: 100%; width: 25%; transition: width 0.5s ease; }
        .card { border: 1px solid #ddd; padding: 20px; border-radius: 8px; margin-top: 20px; }
    </style>
</head>
<body>
    <h1>Order #<?= esc($orderId) ?> Tracking</h1>

    <div class="bar-container">
        <div id="progressFill" class="bar-fill"></div>
    </div>

    <div class="card">
        <h2 id="statusTitle">Order Received</h2>
        <p id="statusDesc">Order has been placed and received by the kitchen.</p>
        <small id="updateTime">Waiting for updates...</small>
    </div>

    <script>
        const ORDER_ID = "<?= esc($orderId) ?>";
        const ws = new WebSocket("ws://127.0.0.1:6001");

        ws.onopen = () => {
            ws.send(JSON.stringify({
                event: "pusher:subscribe",
                data: { channel: "orders." + ORDER_ID }
            }));
        };

        ws.onmessage = (event) => {
            const frame = JSON.parse(event.data);
            if (frame.event === "StatusUpdated") {
                const data = typeof frame.data === "string" ? JSON.parse(frame.data) : frame.data;
                document.getElementById("progressFill").style.width = data.progress_percent + "%";
                document.getElementById("statusTitle").textContent = data.status;
                document.getElementById("statusDesc").textContent = data.description;
                document.getElementById("updateTime").textContent = "Last update: " + data.updated_at;
            }
        };
    </script>
</body>
</html>
```

---

## 4. App 2: Background Job & Export Progress Bar (Private Channels)

**Objective**: A user requests a heavy report export. The server responds immediately, runs the process in the background, and streams progress updates privately to that specific user.

### Step 1: Define Channel Authorization
In `app/Config/Channels.php`:

```php
use Jengo\Broadcasting\Broadcast;

// Authorize private user channel: users.{id}
Broadcast::channel('users.{id}', function ($user, int $id): bool {
    // Only the user themselves can listen to their private channel
    $currentUserId = is_object($user) ? ($user->id ?? 0) : ($user['id'] ?? 0);
    return (int) $currentUserId === (int) $id;
});
```

### Step 2: Create the Event Class
`app/Events/ExportProgressUpdated.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;

class ExportProgressUpdated implements ShouldBroadcast
{
    public function __construct(
        public int $userId,
        public int $percent,
        public string $statusMessage,
        public ?string $downloadUrl = null
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'ExportProgress';
    }

    public function broadcastWith(): array
    {
        return [
            'percent'      => $this->percent,
            'message'      => $this->statusMessage,
            'download_url' => $this->downloadUrl,
        ];
    }
}
```

### Step 3: Trigger Updates in Chunks
In your background worker or controller:

```php
// Simulated export process
for ($p = 20; $p <= 100; $p += 20) {
    usleep(500000); // 0.5s work
    $url = ($p === 100) ? '/downloads/export_2026.csv' : null;
    $msg = ($p === 100) ? 'Export ready for download!' : "Processing records ({$p}%)...";

    broadcast(new ExportProgressUpdated($userId, $p, $msg, $url));
}
```

---

## 5. App 3: Collaborative Kanban / Document Updates (Excluding Sender with `toOthers()`)

**Objective**: Multiple users work on a shared board. When User A moves a task card, User B's screen reflects the change immediately, without User A receiving their own echo and flickering.

### Step 1: Event with Socket Exclusion
In your controller:

```php
public function moveTask(): ResponseInterface
{
    $taskId   = (string) $this->request->getPost('task_id');
    $toColumn = (string) $this->request->getPost('to_column');

    // Extract the client's socket ID from request header
    $socketId = $this->request->getHeaderLine('X-Socket-ID');

    // Broadcast to everyone on the board EXCEPT the sender
    Broadcast::on('board.engineering')
        ->as('TaskMoved')
        ->with([
            'task_id'   => $taskId,
            'to_column' => $toColumn,
            'moved_by'  => 'Carlos',
        ])
        ->toOthers($socketId)
        ->send();

    return $this->response->setJSON(['status' => 'success']);
}
```

### Step 2: Client Saves Socket ID
When the WebSocket connection establishes, capture `socket_id`:

```javascript
let currentSocketId = null;

ws.onmessage = (event) => {
    const frame = JSON.parse(event.data);

    if (frame.event === "pusher:connection_established") {
        const payload = JSON.parse(frame.data);
        currentSocketId = payload.socket_id;
    }

    if (frame.event === "TaskMoved") {
        // Only other users receive this frame!
        moveCardInDOM(frame.data.task_id, frame.data.to_column);
    }
};

// When sending AJAX request, pass the socket ID header:
fetch("/tasks/move", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
        "X-Socket-ID": currentSocketId
    },
    body: JSON.stringify({ task_id: 42, to_column: "done" })
});
```

---

## 6. App 4: Flash Sale Stock Counter (High-Frequency Public Channel)

**Objective**: Live stock ticker on an e-commerce flash sale item. All shoppers see remaining quantity decrease the instant any purchase completes.

```php
// In Checkout Controller after DB transaction:
$newStock = $productModel->decrementStock($productId, $quantity);

broadcast(new StockDecremented($productId, $newStock));
```

Client Listener:
```javascript
ws.onmessage = (event) => {
    const frame = JSON.parse(event.data);
    if (frame.event === "StockDecremented") {
        const badge = document.getElementById("stockBadge");
        badge.textContent = frame.data.remaining_stock + " remaining";

        if (frame.data.remaining_stock <= 0) {
            document.getElementById("buyBtn").disabled = true;
            document.getElementById("buyBtn").textContent = "Sold Out";
        }
    }
};
```

---

## 7. App 5: Live Polling & Quiz Room (Presence Channels)

**Objective**: Attendees join a room. The system displays who is currently online in the room and tallies poll results in real time.

### Step 1: Presence Authorization Callback
In `app/Config/Channels.php`:

```php
// Presence channel must return user data array on authorization
Broadcast::channel('poll.{pollId}', function ($user, string $pollId): array|false {
    if (! $user) {
        return false; // Reject unauthorized guest
    }

    return [
        'id'   => (string) $user->id,
        'name' => (string) $user->name,
    ];
});
```

### Step 2: Listening to Presence Events
When subscribing to a `presence-` channel, the WebSocket protocol emits presence lifecycle frames:
- `pusher:subscription_succeeded`: Provides the initial roster of connected members.
- `pusher:member_added`: Fired whenever a new user connects to the room.
- `pusher:member_removed`: Fired whenever a user disconnects or closes their tab.

---

## 8. Production Readiness Roadmap

To transition this package from its current experimental stage toward production readiness, the following engineering milestones must be completed:

1. **Security & Input Sanitization Audit**:
   - Strict length and frame size validation on raw incoming TCP bytes.
   - Protection against slowloris socket exhaustion and memory buffer ballooning.
2. **Process Lifecycle & Supervisor Integration**:
   - Hardened signals handling across multi-threaded and multi-process worker pools.
   - Native integration with Systemd, Supervisord, and Docker entrypoints.
3. **Socket Leak & Memory Stability Tests**:
   - 48-hour continuous socket cycling benchmarks (1,000+ connect/disconnect cycles per minute) to ensure zero RAM leak.
4. **Horizontal Multi-Node Clustering**:
   - Redis Pub/Sub adapter to allow multiple WebSocket servers to coordinate events across server instances.
5. **TLS/WSS Native Termination**:
   - Stream context SSL/TLS options for direct HTTPS/WSS serving without mandatory external reverse proxies.
