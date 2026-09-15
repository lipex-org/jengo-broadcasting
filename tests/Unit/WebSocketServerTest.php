<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Server\WebSocketServer;
use PHPUnit\Framework\TestCase;

class WebSocketServerTest extends TestCase
{
    protected WebSocketServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new WebSocketServer('127.0.0.1', 19876);
    }

    public function test_encode_and_decode_text_frame(): void
    {
        $message = 'Hello Jengo Broadcasting WebSocket!';
        $encoded = $this->server->encodeFrame($message);

        // First byte: 0x81 (FIN + text)
        $this->assertSame(0x81, ord($encoded[0]));
        $this->assertSame(strlen($message), ord($encoded[1]) & 0x7F);

        // Decode unmasked frame
        $decoded = $this->server->decodeFrame($encoded);
        $this->assertNotNull($decoded);
        $this->assertSame($message, $decoded[0]);
        $this->assertSame(0x1, $decoded[1]); // opcode text
    }

    public function test_decode_masked_client_frame(): void
    {
        $payload = 'Client payload';
        $mask = pack('N', 0x12345678);

        $masked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        $frame = chr(0x81) . chr(0x80 | strlen($payload)) . $mask . $masked;

        $decoded = $this->server->decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded[0]);
    }

    public function test_server_starts_and_stops_cleanly(): void
    {
        $port = random_int(20000, 35000);
        $server = new WebSocketServer('127.0.0.1', $port);

        $server->listen();
        $this->assertSame($port, $server->getPort());

        // Run single tick without clients
        $server->tick(10);

        $server->stop();
        $this->assertSame(0, $server->getConnectedClientCount());
    }
}
