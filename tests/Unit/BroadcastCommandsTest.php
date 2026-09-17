<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\StreamFilterTrait;
use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Commands\BroadcastRoutesCommand;
use Jengo\Broadcasting\Commands\BroadcastTestCommand;
use Jengo\Broadcasting\Config\Broadcasting as BroadcastingConfig;
use PHPUnit\Framework\TestCase;

class BroadcastCommandsTest extends TestCase
{
    use StreamFilterTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStreamFilterTrait();
        $this->resetStreamFilterBuffer();

        $config = new BroadcastingConfig();
        $config->default = 'null';
        $config->connections = [
            'null' => ['driver' => 'null'],
        ];

        $manager = new BroadcastManager($config);
        Broadcast::setFacadeRoot($manager);
    }

    protected function tearDown(): void
    {
        $this->tearDownStreamFilterTrait();
        parent::tearDown();
    }

    public function test_broadcast_routes_command_with_no_channels(): void
    {
        $command = new BroadcastRoutesCommand(service('logger'), service('commands'));
        $command->run([]);

        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('No broadcast channels have been registered', $output);
    }

    public function test_broadcast_routes_command_lists_registered_channels(): void
    {
        Broadcast::channel('orders.{id}', static fn() => true, ['guard' => 'api']);
        Broadcast::channel('presence-chat.{room}', static fn() => true);

        $command = new BroadcastRoutesCommand(service('logger'), service('commands'));
        $command->run([]);

        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('Registered Broadcast Channels:', $output);
        $this->assertStringContainsString('orders.{id}', $output);
        $this->assertStringContainsString('chat.{room}', $output);
    }

    public function test_broadcast_test_command_requires_channel_and_event(): void
    {
        $command = new BroadcastTestCommand(service('logger'), service('commands'));
        $command->run([]);

        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('Both channel and event name are required', $output);
    }

    public function test_broadcast_test_command_dispatches_successfully(): void
    {
        $command = new BroadcastTestCommand(service('logger'), service('commands'));
        $command->run([
            'orders',
            'OrderCreated',
            'data={"order_id":42}',
            'driver=null',
        ]);

        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('Broadcasting [OrderCreated] to [orders]', $output);
        $this->assertStringContainsString('Broadcast dispatched successfully', $output);
        $this->assertStringContainsString('"order_id": 42', $output);
    }
}
