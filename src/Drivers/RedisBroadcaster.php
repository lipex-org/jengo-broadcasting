<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Exceptions\BroadcastException;

class RedisBroadcaster extends AbstractBroadcaster
{
    protected string $prefix;
    protected mixed $redis = null;
    protected array $config;

    /**
     * @param array<string, mixed> $config
     * @param mixed                $redisClient Optional injected Redis client
     */
    public function __construct(array $config = [], mixed $redisClient = null)
    {
        $this->config = $config;
        $this->prefix = (string) ($config['prefix'] ?? 'jengo_broadcast:');
        $this->redis  = $redisClient;
    }

    /**
     * Broadcast to Redis pub/sub.
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

        $message = json_encode([
            'event'  => $event,
            'data'   => $payload,
            'socket' => $this->socketId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $client = $this->getRedis();

        foreach ($formattedChannels as $channel) {
            $redisChannel = $this->prefix . $channel;
            if (is_object($client) && (method_exists($client, 'publish') || is_callable([$client, 'publish']) || method_exists($client, '__call'))) {
                $client->publish($redisChannel, $message);
            }
        }
    }

    /**
     * Get or create Redis client instance.
     */
    protected function getRedis(): mixed
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if (class_exists(\Redis::class)) {
            $redis = new \Redis();
            $host = (string) ($this->config['host'] ?? '127.0.0.1');
            $port = (int) ($this->config['port'] ?? 6379);
            $timeout = (float) ($this->config['timeout'] ?? 0.0);

            try {
                $redis->connect($host, $port, $timeout);
                if (! empty($this->config['password'])) {
                    $redis->auth($this->config['password']);
                }
                if (isset($this->config['database'])) {
                    $redis->select((int) $this->config['database']);
                }
                $this->redis = $redis;
                return $this->redis;
            } catch (\Throwable $e) {
                throw new BroadcastException("Unable to connect to Redis: {$e->getMessage()}", (int) $e->getCode(), $e);
            }
        }

        throw new BroadcastException('Redis extension (ext-redis) or Predis client is required for RedisBroadcaster.');
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

        $response = ['authenticated' => true];
        if ($result !== true) {
            $response['channel_data'] = $result;
        }

        return $this->jsonResponse($response);
    }
}
