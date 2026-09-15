<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Exceptions\BroadcastException;

class AblyBroadcaster extends AbstractBroadcaster
{
    protected string $key;
    protected ?CURLRequest $client = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?CURLRequest $client = null)
    {
        $this->key    = (string) ($config['key'] ?? '');
        $this->client = $client;
    }

    /**
     * Set the HTTP client.
     */
    public function setClient(CURLRequest $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Broadcast to Ably channels.
     *
     * @param array<int, ChannelInterface|string> $channels
     * @param string                              $event
     * @param array<string, mixed>                $payload
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        if (empty($channels)) {
            return;
        }

        $formattedChannels = $this->formatChannels($channels);

        $client = $this->client ?? Services::curlrequest([
            'timeout' => 5.0,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->key),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);

        foreach ($formattedChannels as $channel) {
            $url = 'https://rest.ably.io/channels/' . urlencode($channel) . '/messages';

            $messageData = [
                'name' => $event,
                'data' => $payload,
            ];

            if ($this->socketId !== null) {
                $messageData['connectionKey'] = $this->socketId;
            }

            try {
                $response = $client->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode($this->key),
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => json_encode($messageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);

                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new BroadcastException(
                        "Ably broadcasting failed with code [{$response->getStatusCode()}]: {$response->getBody()}"
                    );
                }
            } catch (\Throwable $e) {
                if ($e instanceof BroadcastException) {
                    throw $e;
                }
                throw new BroadcastException("Ably HTTP broadcast failed: {$e->getMessage()}", (int) $e->getCode(), $e);
            }
        }
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
            'token' => base64_encode($this->key . ':' . time()),
        ]);
    }
}
