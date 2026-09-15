<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Exceptions\BroadcastException;
use Jengo\Broadcasting\Support\PresenceMember;

class PusherBroadcaster extends AbstractBroadcaster
{
    protected string $key;
    protected string $secret;
    protected string $appId;
    protected array $options;
    protected ?CURLRequest $client = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, ?CURLRequest $client = null)
    {
        $this->key     = (string) ($config['key'] ?? '');
        $this->secret  = (string) ($config['secret'] ?? '');
        $this->appId   = (string) ($config['app_id'] ?? '');
        $this->options = (array) ($config['options'] ?? []);
        $this->client  = $client;
    }

    /**
     * Set the HTTP client (useful for unit testing).
     */
    public function setClient(CURLRequest $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Broadcast event to Pusher / Soketi / Reverb.
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

        $bodyData = [
            'name'     => $event,
            'channels' => array_values($formattedChannels),
            'data'     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        if ($this->socketId !== null) {
            $bodyData['socket_id'] = $this->socketId;
        }

        $body = json_encode($bodyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $url = $this->buildSignedUrl('POST', "/apps/{$this->appId}/events", $body);

        $client = $this->client ?? Services::curlrequest([
            'timeout' => 5.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);

        try {
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body' => $body,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new BroadcastException(
                    "Pusher broadcasting failed with status code [{$response->getStatusCode()}]: {$response->getBody()}"
                );
            }
        } catch (\Throwable $e) {
            if ($e instanceof BroadcastException) {
                throw $e;
            }
            throw new BroadcastException("Pusher HTTP broadcast failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }
    }

    /**
     * Authenticate the incoming request for a private/presence channel.
     */
    public function auth(IncomingRequest $request): ResponseInterface
    {
        $channelName = (string) $request->getPost('channel_name');

        if ($channelName === '') {
            return $this->jsonResponse(['error' => 'Missing channel_name'], 400);
        }

        return $this->jsonResponse(['error' => 'Unauthorized'], 403);
    }

    /**
     * Return valid authentication JSON response for Pusher Protocol v7.
     *
     * @param IncomingRequest $request
     * @param mixed           $result Channel authorization callback result
     */
    public function validAuthenticationResponse(IncomingRequest $request, mixed $result): mixed
    {
        if ($result === false || $result === null) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 403);
        }

        $channelName = (string) $request->getPost('channel_name');
        $socketId    = (string) $request->getPost('socket_id');

        if ($channelName === '' || $socketId === '') {
            return $this->jsonResponse(['error' => 'Missing channel_name or socket_id'], 400);
        }

        // Presence channel signature
        if (str_starts_with($channelName, 'presence-')) {
            $channelData = $this->formatPresenceData($result);
            $stringToSign = "{$socketId}:{$channelName}:{$channelData}";
            $signature = hash_hmac('sha256', $stringToSign, $this->secret);

            return $this->jsonResponse([
                'auth'         => "{$this->key}:{$signature}",
                'channel_data' => $channelData,
            ]);
        }

        // Private channel signature
        $stringToSign = "{$socketId}:{$channelName}";
        $signature = hash_hmac('sha256', $stringToSign, $this->secret);

        return $this->jsonResponse([
            'auth' => "{$this->key}:{$signature}",
        ]);
    }

    /**
     * Format presence data into valid JSON string.
     */
    protected function formatPresenceData(mixed $result): string
    {
        if ($result instanceof PresenceMember) {
            return json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_array($result)) {
            if (isset($result['user_id'])) {
                return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $userId = (string) ($result['id'] ?? $result['user_id'] ?? '0');

            return json_encode([
                'user_id'   => $userId,
                'user_info' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'user_id'   => (string) $result,
            'user_info' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build signed Pusher REST API URL.
     */
    public function buildSignedUrl(string $method, string $path, string $body = ''): string
    {
        $cluster = (string) ($this->options['cluster'] ?? 'mt1');
        $useTls  = (bool) ($this->options['useTLS'] ?? true);
        $scheme  = (string) ($this->options['scheme'] ?? ($useTls ? 'https' : 'http'));
        $host    = (string) ($this->options['host'] ?? "api-{$cluster}.pusher.com");
        $port    = isset($this->options['port']) ? (int) $this->options['port'] : ($useTls ? 443 : 80);

        $params = [
            'auth_key'       => $this->key,
            'auth_timestamp' => time(),
            'auth_version'   => '1.0',
            'body_md5'       => md5($body),
        ];

        ksort($params);

        $queryString = http_build_query($params);
        $stringToSign = "{$method}\n{$path}\n{$queryString}";
        $signature = hash_hmac('sha256', $stringToSign, $this->secret);

        $portSegment = (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
            ? ''
            : ":{$port}";

        return "{$scheme}://{$host}{$portSegment}{$path}?{$queryString}&auth_signature={$signature}";
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }
}
