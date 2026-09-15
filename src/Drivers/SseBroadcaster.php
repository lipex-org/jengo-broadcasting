<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Drivers;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Jengo\Broadcasting\Contracts\ChannelInterface;

class SseBroadcaster extends AbstractBroadcaster
{
    protected CacheInterface $cache;
    protected string $cachePrefix;
    protected int $ttl;
    protected int $heartbeatInterval;
    protected int $retryDelay;

    /**
     * In-memory buffer for testing.
     *
     * @var array<int, array{id: string, channel: string, event: string, payload: array<string, mixed>, timestamp: float}>
     */
    protected static array $memoryBuffer = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?CacheInterface $cache = null)
    {
        $this->cache             = $cache ?? Services::cache();
        $this->cachePrefix       = (string) ($config['cache_prefix'] ?? 'jengo_sse_events');
        $this->ttl               = (int) ($config['ttl'] ?? 300); // 5 minutes buffer
        $this->heartbeatInterval = (int) ($config['heartbeat'] ?? 15);
        $this->retryDelay        = (int) ($config['retry'] ?? 3000);
    }

    /**
     * Clear memory buffer (for testing).
     */
    public static function clearMemoryBuffer(): void
    {
        self::$memoryBuffer = [];
    }

    /**
     * Broadcast the event to SSE channels.
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
        $eventId = (string) (microtime(true) * 10000);

        foreach ($formattedChannels as $channel) {
            $record = [
                'id'        => $eventId,
                'channel'   => $channel,
                'event'     => $event,
                'payload'   => $payload,
                'timestamp' => microtime(true),
            ];

            // 1. Store in memory buffer
            self::$memoryBuffer[] = $record;

            // 2. Store in cache circular buffer for cross-process streaming
            $cacheKey = $this->getCacheKey($channel);
            $existing = $this->cache->get($cacheKey);
            $list = is_array($existing) ? $existing : [];

            $list[] = $record;

            // Keep only latest 100 events per channel
            if (count($list) > 100) {
                $list = array_slice($list, -100);
            }

            $this->cache->save($cacheKey, $list, $this->ttl);
        }
    }

    /**
     * Pull events for the given channels that occurred after $lastEventId.
     *
     * @param array<int, string> $channels
     * @return array<int, array{id: string, channel: string, event: string, payload: array<string, mixed>, timestamp: float}>
     */
    public function pullEvents(array $channels, ?string $lastEventId = null): array
    {
        $events = [];

        // Check memory buffer first
        if (! empty(self::$memoryBuffer)) {
            foreach (self::$memoryBuffer as $item) {
                if (in_array($item['channel'], $channels, true)) {
                    if ($lastEventId === null || $item['id'] > $lastEventId) {
                        $events[] = $item;
                    }
                }
            }
        }

        // Also check cache for cross-process events
        foreach ($channels as $channel) {
            $cached = $this->cache->get($this->getCacheKey($channel));
            if (is_array($cached)) {
                foreach ($cached as $item) {
                    if ($lastEventId === null || $item['id'] > $lastEventId) {
                        // Avoid duplicates from memoryBuffer
                        $id = $item['id'];
                        $alreadyAdded = false;
                        foreach ($events as $existing) {
                            if ($existing['id'] === $id && $existing['channel'] === $channel) {
                                $alreadyAdded = true;
                                break;
                            }
                        }
                        if (! $alreadyAdded) {
                            $events[] = $item;
                        }
                    }
                }
            }
        }

        // Sort by timestamp
        usort($events, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return $events;
    }

    /**
     * Authenticate SSE request.
     */
    public function auth(IncomingRequest $request): ResponseInterface
    {
        return $this->jsonResponse(['authenticated' => true]);
    }

    /**
     * Return valid authentication response for SSE.
     */
    public function validAuthenticationResponse(IncomingRequest $request, mixed $result): mixed
    {
        if ($result === false || $result === null) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 403);
        }

        return $this->jsonResponse([
            'authenticated' => true,
            'channel_data'  => $result,
        ]);
    }

    public function getHeartbeatInterval(): int
    {
        return $this->heartbeatInterval;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    protected function getCacheKey(string $channel): string
    {
        return $this->cachePrefix . '_' . md5($channel);
    }
}
