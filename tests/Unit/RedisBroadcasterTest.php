<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Jengo\Broadcasting\Channels\Channel;
use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Drivers\RedisBroadcaster;
use Jengo\Broadcasting\Exceptions\BroadcastException;
use PHPUnit\Framework\TestCase;

class RedisBroadcasterTest extends TestCase
{
    public function test_broadcast_publishes_to_channels_with_prefix(): void
    {
        $published = [];
        $mockClient = new class($published) {
            public function __construct(public array &$published) {}
            public function publish(string $channel, string $message): int
            {
                $this->published[] = ['channel' => $channel, 'message' => json_decode($message, true)];
                return 1;
            }
        };

        $broadcaster = new RedisBroadcaster([
            'prefix' => 'test_app:',
        ], $mockClient);

        $broadcaster->setSocketId('socket.456');
        $broadcaster->broadcast(
            [new Channel('chat'), new PrivateChannel('user.10')],
            'NewMessage',
            ['text' => 'Hello Redis']
        );

        $this->assertCount(2, $published);

        $this->assertSame('test_app:chat', $published[0]['channel']);
        $this->assertSame('NewMessage', $published[0]['message']['event']);
        $this->assertSame(['text' => 'Hello Redis'], $published[0]['message']['data']);
        $this->assertSame('socket.456', $published[0]['message']['socket']);

        $this->assertSame('test_app:private-user.10', $published[1]['channel']);
        $this->assertSame('NewMessage', $published[1]['message']['event']);
    }

    public function test_broadcast_supports_magic_call_client(): void
    {
        $published = [];
        $predisMock = new class($published) {
            public function __construct(public array &$published) {}
            public function __call(string $name, array $arguments)
            {
                if ($name === 'publish') {
                    $this->published[] = ['channel' => $arguments[0], 'message' => $arguments[1]];
                    return 1;
                }
                return null;
            }
        };

        $broadcaster = new RedisBroadcaster(['prefix' => 'app:'], $predisMock);
        $broadcaster->broadcast(['feed'], 'PostCreated', ['id' => 7]);

        $this->assertCount(1, $published);
        $this->assertSame('app:feed', $published[0]['channel']);
    }

    public function test_broadcast_early_exits_on_empty_channels(): void
    {
        $callCount = 0;
        $mockClient = new class($callCount) {
            public function __construct(public int &$callCount) {}
            public function publish(): int
            {
                $this->callCount++;
                return 1;
            }
        };

        $broadcaster = new RedisBroadcaster([], $mockClient);
        $broadcaster->broadcast([], 'TestEvent', ['data' => 'test']);

        $this->assertSame(0, $callCount);
    }

    public function test_throws_exception_when_connecting_to_invalid_redis_host(): void
    {
        if (! class_exists(\Redis::class)) {
            $this->expectException(BroadcastException::class);
            $broadcaster = new RedisBroadcaster(['host' => '127.0.0.1', 'port' => 63999, 'timeout' => 0.1]);
            $broadcaster->broadcast(['orders'], 'TestEvent', []);
            return;
        }

        $this->expectException(BroadcastException::class);
        $broadcaster = new RedisBroadcaster(['host' => '127.0.0.1', 'port' => 63999, 'timeout' => 0.1]);
        $broadcaster->broadcast(['orders'], 'TestEvent', []);
    }

    public function test_auth_returns_authenticated_response(): void
    {
        $broadcaster = new RedisBroadcaster();
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $response = $broadcaster->auth($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['authenticated' => true], $body);
    }

    public function test_valid_authentication_response_for_private_channel(): void
    {
        $broadcaster = new RedisBroadcaster();
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $response = $broadcaster->validAuthenticationResponse($request, true);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['authenticated' => true], $body);
        $this->assertArrayNotHasKey('channel_data', $body);
    }

    public function test_valid_authentication_response_for_presence_channel(): void
    {
        $broadcaster = new RedisBroadcaster();
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $userData = ['id' => 12, 'name' => 'Alice'];
        $response = $broadcaster->validAuthenticationResponse($request, $userData);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['authenticated' => true, 'channel_data' => $userData], $body);
    }

    public function test_valid_authentication_response_returns_403_when_unauthorized(): void
    {
        $broadcaster = new RedisBroadcaster();
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $responseFalse = $broadcaster->validAuthenticationResponse($request, false);
        $this->assertSame(403, $responseFalse->getStatusCode());
        $body = json_decode((string) $responseFalse->getBody(), true);
        $this->assertSame('Unauthorized', $body['error']);

        $responseNull = $broadcaster->validAuthenticationResponse($request, null);
        $this->assertSame(403, $responseNull->getStatusCode());
    }
}
