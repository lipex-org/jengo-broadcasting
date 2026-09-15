<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Server;

use Jengo\Broadcasting\Exceptions\BroadcastException;

class WebSocketServer
{
    protected string $host;
    protected int $port;

    /**
     * Master listening socket stream.
     *
     * @var resource|null
     */
    protected $server = null;

    /**
     * Connected client streams.
     *
     * @var array<int, resource>
     */
    protected array $clients = [];

    /**
     * Client metadata indexed by resource ID.
     *
     * @var array<int, array{id: string, handshake: bool, channels: array<string, bool>, buffer: string, ip: string}>
     */
    protected array $clientMeta = [];

    protected bool $running = false;

    /**
     * Optional logger callable.
     *
     * @var (callable(string $level, string $message): void)|null
     */
    protected $logger = null;

    public function __construct(string $host = '0.0.0.0', int $port = 6001)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    protected function log(string $message, string $level = 'info'): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }

    /**
     * Start the listening server socket.
     */
    public function listen(): self
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $errno = 0;
        $errstr = '';

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'backlog'      => 128,
            ],
        ]);

        $server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (! is_resource($server)) {
            throw new BroadcastException("Failed to bind WebSocket server on [{$address}]: {$errstr} ({$errno})");
        }

        stream_set_blocking($server, false);
        $this->server = $server;
        $this->running = true;

        $this->log("WebSocket server listening on ws://{$this->host}:{$this->port}");

        return $this;
    }

    /**
     * Run the event loop continuously.
     *
     * @param (callable(): bool)|null $shouldContinue
     */
    public function run(?callable $shouldContinue = null): void
    {
        if ($this->server === null) {
            $this->listen();
        }

        while ($this->running && ($shouldContinue === null || $shouldContinue())) {
            $this->tick(100);
        }

        $this->stop();
    }

    /**
     * Perform a single non-blocking tick of the event loop.
     *
     * @param int $timeoutMs Select timeout in milliseconds
     */
    public function tick(int $timeoutMs = 50): void
    {
        if ($this->server === null) {
            return;
        }

        $read = array_merge([$this->server], array_values($this->clients));
        $write = null;
        $except = null;

        $sec = (int) ($timeoutMs / 1000);
        $usec = ($timeoutMs % 1000) * 1000;

        $ready = @stream_select($read, $write, $except, $sec, $usec);

        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->server) {
                $this->acceptConnection();
            } else {
                $this->readClient($socket);
            }
        }
    }

    /**
     * Stop the server and disconnect all clients.
     */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->clients as $socket) {
            $this->disconnectClient($socket, 1000, 'Server stopping');
        }

        if (is_resource($this->server)) {
            fclose($this->server);
            $this->server = null;
        }

        $this->clients = [];
        $this->clientMeta = [];
        $this->log('WebSocket server stopped');
    }

    /**
     * Accept an incoming client connection.
     */
    protected function acceptConnection(): void
    {
        $client = @stream_socket_accept($this->server, 0, $peerName);

        if (! is_resource($client)) {
            return;
        }

        stream_set_blocking($client, false);

        $socketId = (int) $client;
        $clientId = sprintf('%d.%d', random_int(100000, 999999), random_int(100000, 999999));

        $this->clients[$socketId] = $client;
        $this->clientMeta[$socketId] = [
            'id'        => $clientId,
            'handshake' => false,
            'channels'  => [],
            'buffer'    => '',
            'ip'        => $peerName ?: '127.0.0.1',
        ];

        $this->log("Client connected [{$clientId}] from {$peerName}");
    }

    /**
     * Read data from an existing client connection.
     *
     * @param resource $socket
     */
    protected function readClient($socket): void
    {
        $socketId = (int) $socket;
        $data = @fread($socket, 8192);

        if ($data === false || $data === '') {
            $this->disconnectClient($socket);
            return;
        }

        if (! isset($this->clientMeta[$socketId])) {
            return;
        }

        // 1. Handshake phase or HTTP request
        if (! $this->clientMeta[$socketId]['handshake']) {
            $this->clientMeta[$socketId]['buffer'] .= $data;

            if (str_contains($this->clientMeta[$socketId]['buffer'], "\r\n\r\n")) {
                $parts = explode("\r\n\r\n", $this->clientMeta[$socketId]['buffer'], 2);
                $rawRequest = $parts[0] . "\r\n\r\n";
                $this->clientMeta[$socketId]['buffer'] = $parts[1] ?? '';

                // Handle HTTP REST Broadcast POST request (e.g. from PusherBroadcaster)
                if (str_starts_with($rawRequest, 'POST ')) {
                    $this->handleHttpBroadcast($socket, $rawRequest . $this->clientMeta[$socketId]['buffer']);
                    return;
                }

                // Handle WebSocket upgrade handshake
                $this->handleHandshake($socket, $rawRequest);

                // Process any frames that were included in the same TCP segment
                while (isset($this->clientMeta[$socketId]) && $this->clientMeta[$socketId]['buffer'] !== '') {
                    $decoded = $this->decodeFrame($this->clientMeta[$socketId]['buffer']);
                    if ($decoded === null) {
                        break;
                    }
                    [$payload, $opcode, $consumedBytes] = $decoded;
                    $this->clientMeta[$socketId]['buffer'] = substr($this->clientMeta[$socketId]['buffer'], $consumedBytes);
                    $this->handleFrame($socket, $opcode, $payload);
                }
            }
            return;
        }

        // 2. WebSocket frame reading
        $this->clientMeta[$socketId]['buffer'] .= $data;

        while ($this->clientMeta[$socketId]['buffer'] !== '') {
            $decoded = $this->decodeFrame($this->clientMeta[$socketId]['buffer']);

            if ($decoded === null) {
                // Incomplete frame, wait for more data
                break;
            }

            [$payload, $opcode, $consumedBytes] = $decoded;
            $this->clientMeta[$socketId]['buffer'] = substr($this->clientMeta[$socketId]['buffer'], $consumedBytes);

            $this->handleFrame($socket, $opcode, $payload);
        }
    }

    /**
     * Handle incoming WebSocket upgrade request.
     *
     * @param resource $socket
     */
    protected function handleHandshake($socket, string $headers): void
    {
        $socketId = (int) $socket;

        if (! preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $headers, $matches)) {
            $this->sendRaw($socket, "HTTP/1.1 400 Bad Request\r\nSec-WebSocket-Version: 13\r\n\r\n");
            $this->disconnectClient($socket);
            return;
        }

        $key = trim($matches[1]);
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        // Negotiate subprotocol ONLY if the client specifically requested one
        $protocolHeader = '';
        if (preg_match('/Sec-WebSocket-Protocol:\s*([^\r\n]+)/i', $headers, $protoMatches)) {
            $requestedProtocols = array_map('trim', explode(',', $protoMatches[1]));
            if (in_array('pusher', $requestedProtocols, true)) {
                $protocolHeader = "Sec-WebSocket-Protocol: pusher\r\n";
            } elseif (! empty($requestedProtocols[0])) {
                $protocolHeader = "Sec-WebSocket-Protocol: {$requestedProtocols[0]}\r\n";
            }
        }

        $upgradeResponse = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: {$accept}\r\n" .
            $protocolHeader . "\r\n";

        $this->sendRaw($socket, $upgradeResponse);

        $this->clientMeta[$socketId]['handshake'] = true;
        $clientId = $this->clientMeta[$socketId]['id'];

        $this->log("WebSocket handshake completed for client [{$clientId}]");

        // Send Pusher protocol initial connection frame
        $initData = json_encode([
            'socket_id'        => $clientId,
            'activity_timeout' => 120,
        ], JSON_UNESCAPED_SLASHES);

        $this->sendFrame($socket, json_encode([
            'event' => 'pusher:connection_established',
            'data'  => $initData,
        ]));
    }

    /**
     * Handle incoming HTTP REST API broadcast request (from PusherBroadcaster).
     *
     * @param resource $socket
     */
    protected function handleHttpBroadcast($socket, string $rawRequest): void
    {
        $parts = explode("\r\n\r\n", $rawRequest, 2);
        $body = $parts[1] ?? '';

        $json = json_decode($body, true);

        if (is_array($json)) {
            $event    = (string) ($json['name'] ?? $json['event'] ?? 'GenericEvent');
            $channels = (array) ($json['channels'] ?? ($json['channel'] ? [$json['channel']] : []));
            $data     = $json['data'] ?? [];
            $socketId = isset($json['socket_id']) ? (string) $json['socket_id'] : null;

            if (is_string($data)) {
                $decodedData = json_decode($data, true);
                if (is_array($decodedData)) {
                    $data = $decodedData;
                }
            }

            $count = $this->broadcast($channels, $event, $data, $socketId);
            $this->log("HTTP broadcast received for event [{$event}] to " . count($channels) . " channels, delivered to {$count} clients");
        }

        $responseBody = '{"status":"ok"}';
        $response = "HTTP/1.1 200 OK\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: " . strlen($responseBody) . "\r\n" .
            "Connection: close\r\n\r\n" .
            $responseBody;

        $this->sendRaw($socket, $response);
        $this->disconnectClient($socket);
    }

    /**
     * Handle a decoded WebSocket frame.
     *
     * @param resource $socket
     */
    protected function handleFrame($socket, int $opcode, string $payload): void
    {
        $socketId = (int) $socket;
        $clientId = $this->clientMeta[$socketId]['id'] ?? 'unknown';

        // Ping frame (0x9)
        if ($opcode === 0x9) {
            $this->sendRaw($socket, $this->encodeFrame($payload, 0xA)); // Pong
            return;
        }

        // Close frame (0x8)
        if ($opcode === 0x8) {
            $this->disconnectClient($socket);
            return;
        }

        // Text frame (0x1)
        if ($opcode === 0x1) {
            $message = json_decode($payload, true);
            if (! is_array($message)) {
                return;
            }

            $event = (string) ($message['event'] ?? $message['action'] ?? '');

            // Pusher subscribe or generic subscribe
            if ($event === 'pusher:subscribe' || $event === 'subscribe') {
                $channel = (string) ($message['data']['channel'] ?? $message['channel'] ?? '');
                if ($channel !== '') {
                    $this->clientMeta[$socketId]['channels'][$channel] = true;
                    $this->log("Client [{$clientId}] subscribed to channel [{$channel}]");

                    // Send subscription confirmation
                    $this->sendFrame($socket, json_encode([
                        'event'   => 'pusher_internal:subscription_succeeded',
                        'channel' => $channel,
                        'data'    => '{}',
                    ]));
                }
                return;
            }

            // Pusher unsubscribe
            if ($event === 'pusher:unsubscribe' || $event === 'unsubscribe') {
                $channel = (string) ($message['data']['channel'] ?? $message['channel'] ?? '');
                unset($this->clientMeta[$socketId]['channels'][$channel]);
                $this->log("Client [{$clientId}] unsubscribed from channel [{$channel}]");
                return;
            }

            // Pusher ping
            if ($event === 'pusher:ping' || $event === 'ping') {
                $this->sendFrame($socket, json_encode(['event' => 'pusher:pong']));
                return;
            }
        }
    }

    /**
     * Broadcast an event to all connected clients subscribed to the given channels.
     *
     * @param array<int, string>   $channels
     * @param array<string, mixed> $payload
     * @return int Number of clients that received the broadcast
     */
    public function broadcast(array $channels, string $event, array $payload, ?string $excludeSocket = null): int
    {
        $delivered = 0;

        foreach ($channels as $channel) {
            $pusherMessage = json_encode([
                'event'   => $event,
                'channel' => $channel,
                'data'    => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $frame = $this->encodeFrame($pusherMessage);

            foreach ($this->clients as $socketId => $socket) {
                if (! isset($this->clientMeta[$socketId]) || ! $this->clientMeta[$socketId]['handshake']) {
                    continue;
                }

                // Skip excluded socket (toOthers)
                if ($excludeSocket !== null && $this->clientMeta[$socketId]['id'] === $excludeSocket) {
                    continue;
                }

                // Check if subscribed to channel
                if (isset($this->clientMeta[$socketId]['channels'][$channel])) {
                    $this->sendRaw($socket, $frame);
                    $delivered++;
                }
            }
        }

        return $delivered;
    }

    /**
     * Disconnect a client socket.
     *
     * @param resource $socket
     */
    public function disconnectClient($socket, int $code = 1000, string $reason = ''): void
    {
        $socketId = (int) $socket;

        if (isset($this->clients[$socketId])) {
            $clientId = $this->clientMeta[$socketId]['id'] ?? 'unknown';

            if ($this->clientMeta[$socketId]['handshake'] ?? false) {
                $closePayload = pack('n', $code) . $reason;
                @fwrite($socket, $this->encodeFrame($closePayload, 0x8));
            }

            @fclose($socket);
            unset($this->clients[$socketId], $this->clientMeta[$socketId]);
            $this->log("Client disconnected [{$clientId}]");
        }
    }

    /**
     * Encode an RFC 6455 unmasked frame (server to client).
     */
    public function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $len = strlen($payload);
        $frame = chr(0x80 | ($opcode & 0x0F)); // FIN + opcode

        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 65535) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }

        return $frame . $payload;
    }

    /**
     * Decode an incoming RFC 6455 masked frame (client to server).
     *
     * @return array{0: string, 1: int, 2: int}|null [payload, opcode, totalBytesConsumed]
     */
    public function decodeFrame(string $data): ?array
    {
        $dataLen = strlen($data);
        if ($dataLen < 2) {
            return null;
        }

        $firstByte = ord($data[0]);
        $opcode = $firstByte & 0x0F;

        $secondByte = ord($data[1]);
        $isMasked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($dataLen < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($dataLen < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        $maskLen = $isMasked ? 4 : 0;
        $totalExpected = $offset + $maskLen + $payloadLen;

        if ($dataLen < $totalExpected) {
            return null; // Need more data
        }

        $mask = '';
        if ($isMasked) {
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }

        $payload = substr($data, $offset, $payloadLen);

        if ($isMasked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        return [$payload, $opcode, $totalExpected];
    }

    /**
     * Send raw frame bytes to client.
     *
     * @param resource $socket
     */
    protected function sendRaw($socket, string $data): bool
    {
        $length = strlen($data);
        $written = @fwrite($socket, $data);
        return $written === $length;
    }

    /**
     * Send a text WebSocket frame to client.
     *
     * @param resource $socket
     */
    protected function sendFrame($socket, string $payload): bool
    {
        return $this->sendRaw($socket, $this->encodeFrame($payload));
    }

    public function getConnectedClientCount(): int
    {
        return count($this->clients);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getHost(): string
    {
        return $this->host;
    }
}
