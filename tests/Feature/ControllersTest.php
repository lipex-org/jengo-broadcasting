<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Controllers\BroadcastAuthController;
use Jengo\Broadcasting\Controllers\SseStreamController;
use PHPUnit\Framework\TestCase;

class ControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Broadcast::setFacadeRoot(new BroadcastManager());
    }

    public function test_auth_controller_authorizes_private_channel(): void
    {
        Broadcast::channel('orders.{id}', static fn($user, int $id) => $id === 10);
        Broadcast::getFacadeRoot()->getAuthorizer()->setUserResolver(static fn() => ['id' => 1]);

        $controller = new BroadcastAuthController();

        $request = new IncomingRequest(new App(), new URI('http://example.com/broadcasting/auth'), null, new UserAgent());
        $request->setGlobal('post', [
            'channel_name' => 'private-orders.10',
            'socket_id'    => '123.456',
        ]);

        $controller->initController($request, new Response(new App()), service('logger'));

        $response = $controller->authenticate();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('auth', $data);
    }

    public function test_auth_controller_rejects_unauthorized_channel(): void
    {
        Broadcast::channel('orders.{id}', static fn($user, int $id) => false);
        Broadcast::getFacadeRoot()->getAuthorizer()->setUserResolver(static fn() => ['id' => 1]);

        $controller = new BroadcastAuthController();

        $request = new IncomingRequest(new App(), new URI('http://example.com/broadcasting/auth'), null, new UserAgent());
        $request->setGlobal('post', [
            'channel_name' => 'private-orders.10',
            'socket_id'    => '123.456',
        ]);

        $controller->initController($request, new Response(new App()), service('logger'));

        $response = $controller->authenticate();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_sse_stream_controller_in_test_environment(): void
    {
        $controller = new SseStreamController();

        $request = new IncomingRequest(new App(), new URI('http://example.com/broadcasting/sse?channels=public-feed'), null, new UserAgent());
        $request->setGlobal('get', ['channels' => 'public-feed']);

        $controller->initController($request, new Response(new App()), service('logger'));

        $response = $controller->stream();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(': connected', (string) $response->getBody());
    }
}
