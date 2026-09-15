<?php

declare(strict_types=1);

namespace Jengo\Broadcasting;

use CodeIgniter\Router\RouteCollection;
use Config\Services;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;
use Jengo\Broadcasting\Contracts\ChannelInterface;
use Jengo\Broadcasting\Support\BroadcastPendingEvent;
use Jengo\Broadcasting\Testing\BroadcastFake;

/**
 * @method static BroadcasterInterface connection(?string $name = null)
 * @method static BroadcasterInterface driver(?string $name = null)
 * @method static BroadcastManager channel(string $pattern, callable $callback, array $options = [])
 * @method static BroadcastPendingEvent on(array|ChannelInterface|string $channels)
 * @method static void event(object $event)
 * @method static BroadcastFake fake()
 * @method static void broadcast(array $channels, string $event, array $payload = [])
 */
class Broadcast
{
    /**
     * Cached manager instance for testing/runtime.
     */
    protected static ?BroadcastManager $instance = null;

    /**
     * Get the underlying BroadcastManager instance.
     */
    public static function getFacadeRoot(): BroadcastManager
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        /** @var BroadcastManager $manager */
        $manager = Services::broadcasting();
        return $manager;
    }

    /**
     * Set the BroadcastManager instance (used for testing or resetting).
     */
    public static function setFacadeRoot(?BroadcastManager $manager): void
    {
        self::$instance = $manager;
    }

    /**
     * Swap broadcaster with an in-memory fake test double.
     */
    public static function fake(): BroadcastFake
    {
        return static::getFacadeRoot()->fake();
    }

    /**
     * Begin a fluent broadcast definition.
     *
     * @param array<int, ChannelInterface|string>|ChannelInterface|string $channels
     */
    public static function on(array|ChannelInterface|string $channels): BroadcastPendingEvent
    {
        return static::getFacadeRoot()->on($channels);
    }

    /**
     * Register a channel authorization callback.
     *
     * @param string               $pattern
     * @param callable             $callback
     * @param array<string, mixed> $options
     */
    public static function channel(string $pattern, callable $callback, array $options = []): BroadcastManager
    {
        return static::getFacadeRoot()->channel($pattern, $callback, $options);
    }

    /**
     * Broadcast an event object.
     */
    public static function event(object $event): void
    {
        static::getFacadeRoot()->event($event);
    }

    /**
     * Register broadcasting authentication and streaming routes.
     *
     * @param array<string, mixed> $options
     */
    public static function routes(array $options = []): void
    {
        /** @var RouteCollection $routes */
        $routes = Services::routes();

        $prefix = (string) ($options['prefix'] ?? 'broadcasting');

        $routes->group($prefix, static function ($routes) {
            $routes->post('auth', '\Jengo\Broadcasting\Controllers\BroadcastAuthController::authenticate');
            $routes->get('sse', '\Jengo\Broadcasting\Controllers\SseStreamController::stream');
        });
    }

    /**
     * Handle dynamic static calls to the broadcaster manager.
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return static::getFacadeRoot()->{$method}(...$arguments);
    }
}
