<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Security\SocketHeaderResolver;

abstract class AbstractBroadcaster implements BroadcasterInterface
{
    /**
     * The socket ID to exclude from receiving the broadcast.
     */
    protected ?string $socketId = null;

    /**
     * Format the channel names into wire-safe strings.
     *
     * @param array<int, ChannelInterface|string> $channels
     * @return array<int, string>
     */
    protected function formatChannels(array $channels): array
    {
        return array_map(static fn($channel) => (string) $channel, $channels);
    }

    /**
     * Exclude the current socket ID from the broadcast.
     */
    public function toOthers(): static
    {
        $this->socketId = SocketHeaderResolver::resolve();
        return $this;
    }

    /**
     * Set the socket ID to exclude from the broadcast.
     */
    public function setSocketId(?string $socketId): static
    {
        $this->socketId = $socketId;
        return $this;
    }

    /**
     * Get the excluded socket ID.
     */
    public function getSocketId(): ?string
    {
        return $this->socketId;
    }

    /**
     * Return a standardized JSON response for the broadcaster auth endpoint.
     */
    protected function jsonResponse(mixed $data, int $status = 200): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = Services::response();

        return $response
            ->setStatusCode($status)
            ->setJSON($data);
    }
}
