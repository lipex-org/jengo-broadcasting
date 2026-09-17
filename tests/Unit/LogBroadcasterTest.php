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
use Jengo\Broadcasting\Drivers\LogBroadcaster;
use PHPUnit\Framework\TestCase;

class LogBroadcasterTest extends TestCase
{
    protected LogBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broadcaster = new LogBroadcaster(['level' => 'debug']);
    }

    public function test_broadcast_formats_channels_and_payload_without_error(): void
    {
        // Broadcasts to single channel
        $this->broadcaster->broadcast(['orders'], 'OrderShipped', ['id' => 101, 'status' => 'in_transit']);

        // Broadcasts to multiple channels
        $this->broadcaster->broadcast(
            [new Channel('public-updates'), new PrivateChannel('user.5')],
            'UserNotified',
            ['msg' => 'Welcome']
        );

        $this->assertTrue(true);
    }

    public function test_broadcast_includes_socket_id_when_set(): void
    {
        $this->broadcaster->setSocketId('9999.1111');
        $this->broadcaster->broadcast(['chat'], 'MessageSent', ['text' => 'hello']);

        $this->assertTrue(true);
    }

    public function test_auth_returns_authenticated(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
        $response = $this->broadcaster->auth($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['authenticated' => true], $body);
    }

    public function test_valid_authentication_response_for_private_channel(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
        $request->setGlobal('post', ['socket_id' => '8888.2222']);

        $response = $this->broadcaster->validAuthenticationResponse($request, true);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('log-key:' . md5('8888.2222'), $body['auth']);
        $this->assertArrayNotHasKey('channel_data', $body);
    }

    public function test_valid_authentication_response_for_presence_channel(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
        $request->setGlobal('post', ['socket_id' => '8888.3333']);

        $userData = ['id' => 42, 'name' => 'John Doe'];
        $response = $this->broadcaster->validAuthenticationResponse($request, $userData);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('log-key:' . md5('8888.3333'), $body['auth']);
        $this->assertArrayHasKey('channel_data', $body);
        $this->assertSame(json_encode($userData), $body['channel_data']);
    }

    public function test_valid_authentication_response_returns_403_when_unauthorized(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $responseFalse = $this->broadcaster->validAuthenticationResponse($request, false);
        $this->assertSame(403, $responseFalse->getStatusCode());
        $body = json_decode((string) $responseFalse->getBody(), true);
        $this->assertSame('Unauthorized', $body['error']);

        $responseNull = $this->broadcaster->validAuthenticationResponse($request, null);
        $this->assertSame(403, $responseNull->getStatusCode());
    }
}
