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
use Jengo\Broadcasting\Drivers\NullBroadcaster;
use PHPUnit\Framework\TestCase;

class NullBroadcasterTest extends TestCase
{
    protected NullBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broadcaster = new NullBroadcaster();
    }

    public function test_broadcast_does_nothing_safely(): void
    {
        $this->broadcaster->broadcast(['orders'], 'OrderPlaced', ['id' => 1]);
        $this->broadcaster->broadcast([], 'EmptyChannels', []);
        $this->broadcaster->broadcast([new Channel('public-chat'), new PrivateChannel('user.1')], 'ChatMessage', ['text' => 'hello']);

        $this->assertTrue(true);
    }

    public function test_socket_id_can_be_set(): void
    {
        $this->broadcaster->setSocketId('1234.5678');
        $this->broadcaster->broadcast(['orders'], 'OrderPlaced', []);

        $this->assertTrue(true);
    }

    public function test_auth_returns_authenticated_response(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
        $response = $this->broadcaster->auth($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['authenticated' => true], $body);
    }

    public function test_valid_authentication_response_returns_null_auth(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
        $response = $this->broadcaster->validAuthenticationResponse($request, true);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['auth' => 'null-auth'], $body);
    }

    public function test_valid_authentication_response_returns_403_when_unauthorized(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $responseFalse = $this->broadcaster->validAuthenticationResponse($request, false);
        $this->assertSame(403, $responseFalse->getStatusCode());
        $bodyFalse = json_decode((string) $responseFalse->getBody(), true);
        $this->assertSame('Unauthorized', $bodyFalse['error']);

        $responseNull = $this->broadcaster->validAuthenticationResponse($request, null);
        $this->assertSame(403, $responseNull->getStatusCode());
    }
}
