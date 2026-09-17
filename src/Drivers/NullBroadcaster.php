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
        if ($result === false || $result === null) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 403);
        }

        return $this->jsonResponse([
            'auth' => 'null-auth',
        ]);
    }
}
