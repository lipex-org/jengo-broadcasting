<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Contracts;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;

interface BroadcasterInterface
{
    /**
     * Broadcast the given event to the given channels with the specified payload.
     *
     * @param array<int, ChannelInterface|string> $channels
     * @param string                               $event
     * @param array<string, mixed>                 $payload
     */
    public function broadcast(array $channels, string $event, array $payload = []): void;

    /**
     * Authenticate the incoming request for a given channel.
     */
    public function auth(IncomingRequest $request): ResponseInterface;

    /**
     * Return the valid authentication response for the broadcaster protocol.
     *
     * @param IncomingRequest $request
     * @param mixed           $result Channel authorization callback result (bool or array for presence)
     */
    public function validAuthenticationResponse(IncomingRequest $request, mixed $result): mixed;
}
