<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Jengo\Broadcasting\Drivers\PusherBroadcaster;
use PHPUnit\Framework\TestCase;

class PusherBroadcasterTest extends TestCase
{
    protected PusherBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broadcaster = new PusherBroadcaster([
            'key'     => 'test-key',
            'secret'  => 'test-secret',
            'app_id'  => '123456',
            'options' => [
                'cluster' => 'mt1',
                'useTLS'  => true,
            ],
        ]);
    }

    public function test_build_signed_url(): void
    {
        $url = $this->broadcaster->buildSignedUrl('POST', '/apps/123456/events', '{"test":true}');

        $this->assertStringContainsString('https://api-mt1.pusher.com/apps/123456/events', $url);
        $this->assertStringContainsString('auth_key=test-key', $url);
        $this->assertStringContainsString('auth_signature=', $url);
    }

    public function test_valid_authentication_response_for_private_channel(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://example.com/broadcasting/auth'), null, new UserAgent());
        $request->setGlobal('post', [
            'channel_name' => 'private-orders.42',
            'socket_id'    => '987.654',
        ]);

        $response = $this->broadcaster->validAuthenticationResponse($request, true);
        $body = json_decode((string) $response->getBody(), true);

        $expectedSig = hash_hmac('sha256', '987.654:private-orders.42', 'test-secret');
        $this->assertSame("test-key:{$expectedSig}", $body['auth']);
    }

    public function test_valid_authentication_response_for_presence_channel(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://example.com/broadcasting/auth'), null, new UserAgent());
        $request->setGlobal('post', [
            'channel_name' => 'presence-chat.room',
            'socket_id'    => '111.222',
        ]);

        $userData = ['id' => '42', 'name' => 'John Doe'];
        $response = $this->broadcaster->validAuthenticationResponse($request, $userData);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('auth', $body);
        $this->assertArrayHasKey('channel_data', $body);

        $channelData = json_decode($body['channel_data'], true);
        $this->assertSame('42', $channelData['user_id']);
        $this->assertSame('John Doe', $channelData['user_info']['name']);
    }
}
