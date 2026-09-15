<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Jengo\Broadcasting\Security\SocketHeaderResolver;
use PHPUnit\Framework\TestCase;

class SocketHeaderResolverTest extends TestCase
{
    public function test_resolves_socket_from_header(): void
    {
        $request = new IncomingRequest(
            new App(),
            new URI('http://example.com/broadcasting/auth'),
            null,
            new UserAgent()
        );
        $request->setHeader('X-Socket-ID', '12345.67890');

        $this->assertSame('12345.67890', SocketHeaderResolver::resolve($request));
    }
}
