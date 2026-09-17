<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Jengo\Broadcasting\Channels\Channel;
use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Drivers\AblyBroadcaster;
use Jengo\Broadcasting\Exceptions\BroadcastException;
use PHPUnit\Framework\TestCase;

class AblyBroadcasterTest extends TestCase
{
    public function test_broadcast_sends_authenticated_post_request(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('getStatusCode')->willReturn(201);

        $capturedUrl = null;
        $capturedOptions = null;

        $mockClient = $this->createMock(CURLRequest::class);
        $mockClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions, $mockResponse) {
                $capturedUrl = $url;
                $capturedOptions = $options;
                return $mockResponse;
            });

        $broadcaster = new AblyBroadcaster(['key' => 'appId.keyId:secretKey'], $mockClient);
        $broadcaster->setSocketId('socket.123');

        $broadcaster->broadcast(['chat-room'], 'MessageSent', ['content' => 'Hello']);

        $this->assertSame('https://rest.ably.io/channels/chat-room/messages', $capturedUrl);
        $this->assertNotNull($capturedOptions);
        $this->assertSame('Basic ' . base64_encode('appId.keyId:secretKey'), $capturedOptions['headers']['Authorization']);

        $body = json_decode($capturedOptions['body'], true);
        $this->assertSame('MessageSent', $body['name']);
        $this->assertSame(['content' => 'Hello'], $body['data']);
        $this->assertSame('socket.123', $body['connectionKey']);
    }

    public function test_broadcast_throws_when_key_is_missing(): void
    {
        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably API key is required');

        $broadcaster = new AblyBroadcaster(['key' => '']);
        $broadcaster->broadcast(['chat'], 'Event', []);
    }

    public function test_broadcast_throws_when_response_code_is_error(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('getStatusCode')->willReturn(401);
        $mockResponse->method('getBody')->willReturn('Unauthorized token');

        $mockClient = $this->createMock(CURLRequest::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $broadcaster = new AblyBroadcaster(['key' => 'dummy:key'], $mockClient);

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably broadcasting failed with code [401]');

        $broadcaster->broadcast(['channel'], 'Event', []);
    }

    public function test_broadcast_throws_when_client_fails(): void
    {
        $mockClient = $this->createMock(CURLRequest::class);
        $mockClient->method('request')->willThrowException(new \RuntimeException('Connection timed out'));

        $broadcaster = new AblyBroadcaster(['key' => 'dummy:key'], $mockClient);

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably HTTP broadcast failed: Connection timed out');

        $broadcaster->broadcast(['channel'], 'Event', []);
    }

    public function test_broadcast_early_exits_on_empty_channels(): void
    {
        $mockClient = $this->createMock(CURLRequest::class);
        $mockClient->expects($this->never())->method('request');

        $broadcaster = new AblyBroadcaster(['key' => 'dummy:key'], $mockClient);
        $broadcaster->broadcast([], 'Event', []);
    }

    public function test_set_client_chains(): void
    {
        $broadcaster = new AblyBroadcaster(['key' => 'dummy:key']);
        $mockClient = $this->createMock(CURLRequest::class);

        $returned = $broadcaster->setClient($mockClient);
        $this->assertSame($broadcaster, $returned);
    }

    public function test_auth_returns_authenticated(): void
    {
        $broadcaster = new AblyBroadcaster(['key' => 'dummy:key']);
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $response = $broadcaster->auth($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['authenticated' => true], $body);
    }

    public function test_valid_authentication_response_generates_token(): void
    {
        $broadcaster = new AblyBroadcaster(['key' => 'appId.keyId:secretKey']);
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $response = $broadcaster->validAuthenticationResponse($request, true);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('token', $body);
        $decoded = base64_decode($body['token'], true);
        $this->assertStringStartsWith('appId.keyId:secretKey:', $decoded);
    }

    public function test_valid_authentication_response_returns_403_when_unauthorized(): void
    {
        $broadcaster = new AblyBroadcaster(['key' => 'dummy:key']);
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $responseFalse = $broadcaster->validAuthenticationResponse($request, false);
        $this->assertSame(403, $responseFalse->getStatusCode());

        $responseNull = $broadcaster->validAuthenticationResponse($request, null);
        $this->assertSame(403, $responseNull->getStatusCode());
    }

    public function test_valid_authentication_response_throws_when_key_is_missing(): void
    {
        $broadcaster = new AblyBroadcaster(['key' => '']);
        $request = new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably API key is required');

        $broadcaster->validAuthenticationResponse($request, true);
    }
}
