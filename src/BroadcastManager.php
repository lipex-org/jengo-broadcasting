<?php

declare(strict_types=1);

namespace Jengo\Broadcasting;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Attributes\BroadcastAs;
use Jengo\Broadcasting\Attributes\BroadcastOn;
use Jengo\Broadcasting\Attributes\BroadcastWith;
use Jengo\Broadcasting\Config\Broadcasting as BroadcastingConfig;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;
use Jengo\Broadcasting\Drivers\AblyBroadcaster;
use Jengo\Broadcasting\Drivers\LogBroadcaster;
use Jengo\Broadcasting\Drivers\NullBroadcaster;
use Jengo\Broadcasting\Drivers\PusherBroadcaster;
use Jengo\Broadcasting\Drivers\RedisBroadcaster;
use Jengo\Broadcasting\Drivers\SseBroadcaster;
use Jengo\Broadcasting\Exceptions\DriverNotFoundException;
use Jengo\Broadcasting\Security\ChannelAuthorizer;
use Jengo\Broadcasting\Security\SocketHeaderResolver;
use Jengo\Broadcasting\Support\BroadcastPendingEvent;
use Jengo\Broadcasting\Testing\BroadcastFake;
use ReflectionClass;
use ReflectionProperty;

class BroadcastManager
{
    /**
     * Active broadcaster driver instances.
     *
     * @var array<string, BroadcasterInterface>
     */
    protected array $drivers = [];

    /**
     * Custom driver creator callbacks.
     *
     * @var array<string, callable(array<string, mixed>): BroadcasterInterface>
     */
    protected array $customCreators = [];

    protected ChannelAuthorizer $authorizer;

    protected BroadcastingConfig $config;

    public function __construct(?BroadcastingConfig $config = null, ?ChannelAuthorizer $authorizer = null)
    {
        $this->config     = $config ?? config('Broadcasting') ?? new BroadcastingConfig();
        $this->authorizer = $authorizer ?? new ChannelAuthorizer();
    }

    /**
     * Get a broadcaster connection instance.
     */
    public function connection(?string $name = null): BroadcasterInterface
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * Alias to connection().
     */
    public function driver(?string $name = null): BroadcasterInterface
    {
        return $this->connection($name);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->default;
    }

    /**
     * Set the default driver name.
     */
    public function setDefaultDriver(string $name): self
    {
        $this->config->default = $name;
        return $this;
    }

    /**
     * Register a custom driver creator Closure.
     */
    public function extend(string $driver, callable $callback): self
    {
        $this->customCreators[$driver] = $callback;
        return $this;
    }

    /**
     * Get the channel authorizer instance.
     */
    public function getAuthorizer(): ChannelAuthorizer
    {
        return $this->authorizer;
    }

    /**
     * Register a channel authorization callback.
     *
     * @param string               $pattern
     * @param callable             $callback
     * @param array<string, mixed> $options
     */
    public function channel(string $pattern, callable $callback, array $options = []): self
    {
        $this->authorizer->channel($pattern, $callback, $options);
        return $this;
    }

    /**
     * Begin a fluent broadcast definition.
     *
     * @param array<int, ChannelInterface|string>|ChannelInterface|string $channels
     */
    public function on(array|ChannelInterface|string $channels): BroadcastPendingEvent
    {
        return new BroadcastPendingEvent($this, $channels);
    }

    /**
     * Dispatch an event broadcast.
     */
    public function event(object $event): void
    {
        // 1. Check broadcastWhen() hook
        if (method_exists($event, 'broadcastWhen') && ! $event->broadcastWhen()) {
            return;
        }

        // 2. Resolve channels
        $channels = $this->resolveChannels($event);
        if (empty($channels)) {
            return;
        }

        // 3. Resolve event name
        $eventName = $this->resolveEventName($event);

        // 4. Resolve payload
        $payload = $this->resolvePayload($event);

        // 5. Exclude socket if configured
        $driver = $this->connection();

        if (property_exists($event, 'socketId') && is_string($event->socketId)) {
            if (method_exists($driver, 'setSocketId')) {
                $driver->setSocketId($event->socketId);
            }
        }

        // If in test fake mode, record event instance
        if ($driver instanceof BroadcastFake && $event instanceof ShouldBroadcast) {
            $driver->recordEvent($event, $channels, $eventName, $payload);
            return;
        }

        // Dispatch broadcast
        $driver->broadcast($channels, $eventName, $payload);
    }

    /**
     * Swap the current default broadcaster with an in-memory test double.
     */
    public function fake(): BroadcastFake
    {
        $fake = new BroadcastFake($this->authorizer);
        $this->swap($fake);

        return $fake;
    }

    /**
     * Swap the default connection with a specific broadcaster.
     */
    public function swap(BroadcasterInterface $broadcaster): self
    {
        $default = $this->getDefaultDriver();
        $this->drivers[$default] = $broadcaster;

        return $this;
    }

    /**
     * Resolve the given broadcaster instance by name.
     */
    protected function resolve(string $name): BroadcasterInterface
    {
        $config = $this->getConnectionConfig($name);

        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($config);
        }

        $driver = (string) ($config['driver'] ?? $name);

        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($config);
        }

        return match ($driver) {
            'pusher' => $this->createPusherDriver($config),
            'sse'    => $this->createSseDriver($config),
            'redis'  => $this->createRedisDriver($config),
            'ably'   => $this->createAblyDriver($config),
            'log'    => $this->createLogDriver($config),
            'null'   => $this->createNullDriver($config),
            default  => throw DriverNotFoundException::forDriver($name),
        };
    }

    /**
     * Get connection configuration array.
     *
     * @return array<string, mixed>
     */
    protected function getConnectionConfig(string $name): array
    {
        return $this->config->connections[$name] ?? [];
    }

    protected function createPusherDriver(array $config): PusherBroadcaster
    {
        return new PusherBroadcaster($config);
    }

    protected function createSseDriver(array $config): SseBroadcaster
    {
        return new SseBroadcaster($config);
    }

    protected function createRedisDriver(array $config): RedisBroadcaster
    {
        return new RedisBroadcaster($config);
    }

    protected function createAblyDriver(array $config): AblyBroadcaster
    {
        return new AblyBroadcaster($config);
    }

    protected function createLogDriver(array $config): LogBroadcaster
    {
        return new LogBroadcaster($config);
    }

    protected function createNullDriver(array $config): NullBroadcaster
    {
        return new NullBroadcaster();
    }

    /**
     * Resolve channels from event method or attribute.
     *
     * @return array<int, ChannelInterface|string>
     */
    protected function resolveChannels(object $event): array
    {
        if (method_exists($event, 'broadcastOn')) {
            $channels = $event->broadcastOn();
            return is_array($channels) ? $channels : [$channels];
        }

        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(BroadcastOn::class);
        if (! empty($attributes)) {
            /** @var BroadcastOn $attr */
            $attr = $attributes[0]->newInstance();
            return is_array($attr->channels) ? $attr->channels : [$attr->channels];
        }

        return [];
    }

    /**
     * Resolve the event name for broadcasting.
     */
    protected function resolveEventName(object $event): string
    {
        if (method_exists($event, 'broadcastAs')) {
            return (string) $event->broadcastAs();
        }

        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(BroadcastAs::class);
        if (! empty($attributes)) {
            /** @var BroadcastAs $attr */
            $attr = $attributes[0]->newInstance();
            return $attr->name;
        }

        return $reflection->getShortName();
    }

    /**
     * Resolve the payload to broadcast.
     *
     * @return array<string, mixed>
     */
    protected function resolvePayload(object $event): array
    {
        if (method_exists($event, 'broadcastWith')) {
            return (array) $event->broadcastWith();
        }

        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(BroadcastWith::class);

        $selectedProps = ! empty($attributes)
            ? $attributes[0]->newInstance()->properties
            : null;

        $payload = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isInitialized($event)) {
                continue;
            }
            $propName = $property->getName();
            if ($selectedProps === null || in_array($propName, $selectedProps, true)) {
                $payload[$propName] = $property->getValue($event);
            }
        }

        return $payload;
    }

    /**
     * Forward calls to default connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
