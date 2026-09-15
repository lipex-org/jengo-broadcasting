<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\Drivers\SseBroadcaster;

class BroadcastSseCommand extends BaseCommand
{
    protected $group       = 'Broadcasting';
    protected $name        = 'broadcast:sse';
    protected $description = 'Monitor Server-Sent Events (SSE) stream traffic and poll recent buffer events.';
    protected $usage       = 'broadcast:sse [--channels=name1,name2]';
    protected $options     = [
        '--channels' => 'Comma-separated channels to poll and monitor (defaults to all channels)',
    ];

    public function run(array $params)
    {
        $channelsRaw = CLI::getOption('channels');
        $channels = is_string($channelsRaw) && $channelsRaw !== ''
            ? array_map('trim', explode(',', $channelsRaw))
            : ['orders', 'notifications', 'chat'];

        CLI::write('Monitoring Server-Sent Events (SSE) buffer for channels: ' . implode(', ', $channels), 'cyan');
        CLI::write('Press Ctrl+C to exit.');

        $manager = Broadcast::getFacadeRoot();
        /** @var SseBroadcaster $broadcaster */
        $broadcaster = $manager->driver('sse');

        if (! $broadcaster instanceof SseBroadcaster) {
            $broadcaster = new SseBroadcaster();
        }

        $lastId = null;

        while (true) {
            $events = $broadcaster->pullEvents($channels, $lastId);

            foreach ($events as $event) {
                $time = date('H:i:s', (int) $event['timestamp']);
                $payload = json_encode($event['payload'], JSON_UNESCAPED_SLASHES);
                CLI::write("[{$time}] Channel: {$event['channel']} | Event: {$event['event']} | Payload: {$payload}", 'green');
                $lastId = (string) $event['id'];
            }

            usleep(500000); // 500ms
        }
    }
}
