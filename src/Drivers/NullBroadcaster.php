<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;

class NullBroadcaster extends AbstractBroadcaster
{
    /**
     * Discard broadcast event.
     *
     * @param array<int, ChannelInterface|string> $channels
     * @param string                              $event
     * @param array<string, mixed>                $payload
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        // Intentionally blank
    }

    public function auth(IncomingRequest $request): ResponseInterface
    {
        return $this->jsonResponse(['authenticated' => true]);
    }

    public function validAuthenticationResponse(IncomingRequest $request, mixed $result): mixed
    {
        return $this->jsonResponse([
            'auth' => 'null-auth',
        ]);
    }
}
