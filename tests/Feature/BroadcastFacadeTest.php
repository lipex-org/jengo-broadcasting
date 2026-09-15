<?php

declare(strict_types=1);

namespace Tests\Feature;

use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Channels\PrivateChannel;
use Jengo\Broadcasting\Contracts\ShouldBroadcast;
use PHPUnit\Framework\TestCase;

class InvoiceGenerated implements ShouldBroadcast
{
    public function __construct(public string $invoiceNumber, public float $amount)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('invoices.' . $this->invoiceNumber)];
    }

    public function broadcastAs(): string
    {
        return 'InvoiceCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'invoice' => $this->invoiceNumber,
            'amount'  => $this->amount,
        ];
    }
}

class BroadcastFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset facade root
        Broadcast::setFacadeRoot(new BroadcastManager());
    }

    public function test_fake_interception_and_assertions(): void
    {
        $fake = Broadcast::fake();

        Broadcast::on('system')
            ->as('MaintenanceAlert')
            ->with(['time' => '22:00 UTC'])
            ->send();

        $fake->assertBroadcasted('MaintenanceAlert');
        $fake->assertBroadcastedTo('system', 'MaintenanceAlert');
        $fake->assertNotBroadcasted('NonExistent');
    }

    public function test_event_object_dispatch_with_fake(): void
    {
        $fake = Broadcast::fake();

        broadcast(new InvoiceGenerated('INV-2026', 1500.50));

        $fake->assertBroadcasted(InvoiceGenerated::class);
        $fake->assertBroadcasted(InvoiceGenerated::class, static function ($event) {
            return $event->invoiceNumber === 'INV-2026' && $event->amount === 1500.50;
        });
        $fake->assertBroadcastedTo('private-invoices.INV-2026');
    }

    public function test_channel_registration_via_facade(): void
    {
        Broadcast::channel('rooms.{id}', static fn($user, int $id) => $id === 7);

        $fake = Broadcast::fake();
        $fake->assertChannelAuthorized('rooms.7', ['id' => 1]);
        $fake->assertChannelUnauthorized('rooms.8', ['id' => 1]);
    }
}
