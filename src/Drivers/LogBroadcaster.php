<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;

class LogBroadcaster extends AbstractBroadcaster
{
    protected string $level;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->level = (string) ($config['level'] ?? 'info');
    }

    /**
     * Log the broadcast event.
     *
     * @param array<int, ChannelInterface|string> $channels
     * @param string                              $event
     * @param array<string, mixed>                $payload
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $formattedChannels = implode(', ', $this->formatChannels($channels));
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $socket = $this->socketId !== null ? " [socket: {$this->socketId}]" : '';

        log_message($this->level, "Broadcasting [{$event}] on channels [{$formattedChannels}]{$socket} with payload: {$data}");
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
            'auth'         => 'log-key:' . md5((string) $request->getPost('socket_id')),
            'channel_data' => is_array($result) ? json_encode($result) : (string) $result,
        ]);
    }
}
