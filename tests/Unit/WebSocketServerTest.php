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

    public function test_client_disconnect_on_close_frame_does_not_throw_undefined_array_key(): void
    {
        $port = random_int(20000, 35000);
        $server = new WebSocketServer('127.0.0.1', $port);
        $server->listen();

        $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
        $this->assertIsResource($client);

        // Accept connection
        $server->tick(50);
        $this->assertSame(1, $server->getConnectedClientCount());

        // Send handshake
        $key = base64_encode('test-key-123456');
        $handshake = "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($client, $handshake);
        $server->tick(50);

        // Read handshake response from server
        fread($client, 2048);

        // Send masked close frame (opcode 0x8)
        $mask = pack('N', 0x11223344);
        $closeCode = pack('n', 1000);
        $maskedPayload = '';
        for ($i = 0; $i < strlen($closeCode); $i++) {
            $maskedPayload .= $closeCode[$i] ^ $mask[$i % 4];
        }
        $closeFrame = chr(0x88) . chr(0x80 | strlen($closeCode)) . $mask . $maskedPayload;
        fwrite($client, $closeFrame);

        // Server processes close frame - MUST NOT throw Undefined array key
        $server->tick(50);

        $this->assertSame(0, $server->getConnectedClientCount());

        @fclose($client);
        $server->stop();
    }
}
