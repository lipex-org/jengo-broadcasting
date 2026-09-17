<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Config\Broadcasting as BroadcastingConfig;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;
use Jengo\Broadcasting\Drivers\AblyBroadcaster;
use Jengo\Broadcasting\Drivers\LogBroadcaster;
use Jengo\Broadcasting\Drivers\NullBroadcaster;
use Jengo\Broadcasting\Drivers\PusherBroadcaster;
use Jengo\Broadcasting\Drivers\RedisBroadcaster;
use Jengo\Broadcasting\Drivers\SseBroadcaster;
use Jengo\Broadcasting\Exceptions\DriverNotFoundException;
use Jengo\Broadcasting\Testing\BroadcastFake;
use PHPUnit\Framework\TestCase;

class BroadcastManagerDriversTest extends TestCase
{
    protected BroadcastManager $manager;
    protected BroadcastingConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new BroadcastingConfig();
        $this->config->default = 'null';
        $this->config->connections = [
            'null'   => ['driver' => 'null'],
            'log'    => ['driver' => 'log', 'level' => 'debug'],
            'sse'    => ['driver' => 'sse'],
            'pusher' => [
                'driver'  => 'pusher',
                'key'     => 'test-key',
                'secret'  => 'test-secret',
                'app_id'  => '12345',
                'options' => ['cluster' => 'mt1'],
            ],
            'redis'  => ['driver' => 'redis', 'prefix' => 'test:'],
            'ably'   => ['driver' => 'ably', 'key' => 'appId.keyId:secret'],
        ];

        $this->manager = new BroadcastManager($this->config);
    }

    public function test_resolves_all_builtin_drivers(): void
    {
        $this->assertInstanceOf(NullBroadcaster::class, $this->manager->connection('null'));
        $this->assertInstanceOf(LogBroadcaster::class, $this->manager->connection('log'));
        $this->assertInstanceOf(SseBroadcaster::class, $this->manager->connection('sse'));
        $this->assertInstanceOf(PusherBroadcaster::class, $this->manager->connection('pusher'));
        $this->assertInstanceOf(RedisBroadcaster::class, $this->manager->connection('redis'));
        $this->assertInstanceOf(AblyBroadcaster::class, $this->manager->connection('ably'));
    }

    public function test_driver_method_is_alias_to_connection(): void
    {
        $this->assertSame(
            $this->manager->connection('null'),
            $this->manager->driver('null')
        );
    }

    public function test_changes_default_driver(): void
    {
        $this->assertSame('null', $this->manager->getDefaultDriver());
        $this->assertInstanceOf(NullBroadcaster::class, $this->manager->connection());

        $this->manager->setDefaultDriver('log');
        $this->assertSame('log', $this->manager->getDefaultDriver());
        $this->assertInstanceOf(LogBroadcaster::class, $this->manager->connection());
    }

    public function test_registers_custom_driver_via_extend(): void
    {
        $customCalled = false;
        $customBroadcaster = new NullBroadcaster();

        $this->manager->extend('custom_ws', function (array $config) use (&$customCalled, $customBroadcaster) {
            $customCalled = true;
            return $customBroadcaster;
        });

        $broadcaster = $this->manager->connection('custom_ws');
        $this->assertTrue($customCalled);
        $this->assertSame($customBroadcaster, $broadcaster);
    }

    public function test_throws_driver_not_found_exception_for_invalid_driver(): void
    {
        $this->expectException(DriverNotFoundException::class);
        $this->manager->connection('non_existent_driver');
    }

    public function test_swap_replaces_default_connection(): void
    {
        $mock = $this->createMock(BroadcasterInterface::class);
        $this->manager->swap($mock);

        $this->assertSame($mock, $this->manager->connection());
    }

    public function test_fluent_broadcast_pending_event_on_manager(): void
    {
        $captured = [];
        $mock = new class($captured) implements BroadcasterInterface {
            public function __construct(public array &$captured) {}
            public function broadcast(array $channels, string $event, array $payload = []): void
            {
                $this->captured = ['channels' => $channels, 'event' => $event, 'payload' => $payload];
            }
            public function setSocketId(?string $socketId): static { return $this; }
            public function auth(\CodeIgniter\HTTP\IncomingRequest $request): \CodeIgniter\HTTP\ResponseInterface { throw new \BadMethodCallException(); }
            public function validAuthenticationResponse(\CodeIgniter\HTTP\IncomingRequest $request, mixed $result): mixed { throw new \BadMethodCallException(); }
        };

        $this->manager->swap($mock);

        $this->manager->on(['orders', 'reports'])
            ->as('OrderExported')
            ->with(['download_url' => 'https://example.com/file.csv'])
            ->send();

        $this->assertNotEmpty($captured);
        $this->assertSame(['orders', 'reports'], $captured['channels']);
        $this->assertSame('OrderExported', $captured['event']);
        $this->assertSame(['download_url' => 'https://example.com/file.csv'], $captured['payload']);
    }

    public function test_broadcast_fake_swaps_and_records(): void
    {
        $fake = $this->manager->fake();
        $this->assertInstanceOf(BroadcastFake::class, $fake);
        $this->assertSame($fake, $this->manager->connection());
    }
}
