<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Testing;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;
use Jengo\Broadcasting\Drivers\AbstractBroadcaster;
use Jengo\Broadcasting\Security\ChannelAuthorizer;
use PHPUnit\Framework\Assert;

class BroadcastFake extends AbstractBroadcaster
{
    /**
     * Recorded broadcast events.
     *
     * @var array<int, array{event: string, channels: array<int, string>, payload: array<string, mixed>, socketId: ?string, instance: mixed}>
     */
    protected array $broadcasts = [];

    protected ?ChannelAuthorizer $authorizer = null;

    public function __construct(?ChannelAuthorizer $authorizer = null)
    {
        $this->authorizer = $authorizer;
    }

    public function setAuthorizer(ChannelAuthorizer $authorizer): self
    {
        $this->authorizer = $authorizer;
        return $this;
    }

    /**
     * Record a broadcast event.
     *
     * @param array<int, ChannelInterface|string> $channels
     * @param string                              $event
     * @param array<string, mixed>                $payload
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $this->broadcasts[] = [
            'event'    => $event,
            'channels' => $this->formatChannels($channels),
            'payload'  => $payload,
            'socketId' => $this->socketId,
            'instance' => null,
        ];
    }

    /**
     * Record a rich event object broadcast.
     *
     * @param array<int, ChannelInterface|string> $channels
     */
    public function recordEvent(ShouldBroadcast $eventInstance, array $channels, string $event, array $payload): void
    {
        $this->broadcasts[] = [
            'event'    => $event,
            'channels' => $this->formatChannels($channels),
            'payload'  => $payload,
            'socketId' => $this->socketId,
            'instance' => $eventInstance,
        ];
    }

    /**
     * Get all recorded broadcasts.
     *
     * @return array<int, array{event: string, channels: array<int, string>, payload: array<string, mixed>, socketId: ?string, instance: mixed}>
     */
    public function getBroadcasts(): array
    {
        return $this->broadcasts;
    }

    /**
     * Assert that an event was broadcast.
     *
     * @param string|callable $eventOrCallback Class name, event name, or filter callback
     * @param callable|null   $callback
     */
    public function assertBroadcasted(string|callable $eventOrCallback, ?callable $callback = null): self
    {
        if (is_callable($eventOrCallback)) {
            $callback = $eventOrCallback;
            $eventOrCallback = null;
        }

        $matching = $this->filterBroadcasts($eventOrCallback, $callback);

        Assert::assertNotEmpty(
            $matching,
            $eventOrCallback !== null
                ? "The expected [{$eventOrCallback}] event was not broadcast."
                : 'The expected event matching the callback was not broadcast.'
        );

        return $this;
    }

    /**
     * Assert that an event was broadcast a specific number of times.
     */
    public function assertBroadcastedTimes(string $event, int $times): self
    {
        $matching = $this->filterBroadcasts($event);

        Assert::assertCount(
            $times,
            $matching,
            "The expected [{$event}] event was broadcast " . count($matching) . " times instead of {$times} times."
        );

        return $this;
    }

    /**
     * Assert that an event was broadcast to a specific channel.
     *
     * @param string|ChannelInterface $channel
     */
    public function assertBroadcastedTo(string|ChannelInterface $channel, ?string $event = null): self
    {
        $channelName = (string) $channel;

        $matching = array_filter($this->broadcasts, static function ($record) use ($channelName, $event) {
            $channelMatches = in_array($channelName, $record['channels'], true);
            if (! $channelMatches) {
                return false;
            }
            if ($event !== null) {
                return $record['event'] === $event || ($record['instance'] !== null && $record['instance'] instanceof $event);
            }
            return true;
        });

        Assert::assertNotEmpty(
            $matching,
            $event !== null
                ? "The expected [{$event}] event was not broadcast to channel [{$channelName}]."
                : "No events were broadcast to channel [{$channelName}]."
        );

        return $this;
    }

    /**
     * Assert that an event was not broadcast.
     *
     * @param string|callable $eventOrCallback
     */
    public function assertNotBroadcasted(string|callable $eventOrCallback, ?callable $callback = null): self
    {
        if (is_callable($eventOrCallback)) {
            $callback = $eventOrCallback;
            $eventOrCallback = null;
        }

        $matching = $this->filterBroadcasts($eventOrCallback, $callback);

        Assert::assertEmpty(
            $matching,
            $eventOrCallback !== null
                ? "The unexpected [{$eventOrCallback}] event was broadcast."
                : 'An unexpected event matching the callback was broadcast.'
        );

        return $this;
    }

    /**
     * Assert that no events were broadcast.
     */
    public function assertNothingBroadcasted(): self
    {
        Assert::assertEmpty(
            $this->broadcasts,
            'Expected no events to be broadcast, but ' . count($this->broadcasts) . ' events were recorded.'
        );

        return $this;
    }

    /**
     * Assert that the user is authorized to access the channel.
     */
    public function assertChannelAuthorized(string|ChannelInterface $channel, mixed $user = null): self
    {
        Assert::assertNotNull($this->authorizer, 'Channel authorizer is not configured on the test double.');

        $result = $this->authorizer->authorize($channel, $user);

        Assert::assertTrue(
            $result !== false && $result !== null,
            "Failed asserting that user is authorized for channel [{$channel}]."
        );

        return $this;
    }

    /**
     * Assert that the user is NOT authorized to access the channel.
     */
    public function assertChannelUnauthorized(string|ChannelInterface $channel, mixed $user = null): self
    {
        Assert::assertNotNull($this->authorizer, 'Channel authorizer is not configured on the test double.');

        $result = $this->authorizer->authorize($channel, $user);

        Assert::assertTrue(
            $result === false || $result === null,
            "Failed asserting that user is unauthorized for channel [{$channel}]."
        );

        return $this;
    }

    /**
     * Filter recorded broadcasts.
     */
    protected function filterBroadcasts(?string $event = null, ?callable $callback = null): array
    {
        return array_filter($this->broadcasts, static function ($record) use ($event, $callback) {
            if ($event !== null) {
                $nameMatches = $record['event'] === $event
                    || ($record['instance'] !== null && $record['instance'] instanceof $event)
                    || (is_object($record['instance']) && get_class($record['instance']) === $event);

                if (! $nameMatches) {
                    return false;
                }
            }

            if ($callback !== null) {
                return $record['instance'] !== null
                    ? $callback($record['instance'], $record['channels'], $record['payload'])
                    : $callback($record['event'], $record['channels'], $record['payload']);
            }

            return true;
        });
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
            'auth'         => 'fake-key:fake-sig',
            'channel_data' => $result,
        ]);
    }
}
