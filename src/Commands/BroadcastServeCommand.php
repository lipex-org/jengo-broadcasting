<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Jengo\Broadcasting\Server\WebSocketServer;

class BroadcastServeCommand extends BaseCommand
{
    protected $group       = 'Broadcasting';
    protected $name        = 'broadcast:serve';
    protected $description = 'Start the pure-PHP WebSocket broadcast server for real-time clients.';
    protected $usage       = 'broadcast:serve [--host=0.0.0.0] [--port=6001]';
    protected $options     = [
        '--host' => 'The network interface IP address to bind (default: 0.0.0.0)',
        '--port' => 'The port number to listen on (default: 6001)',
    ];

    public function run(array $params)
    {
        $host = $params['host'] ?? CLI::getOption('host');
        if ($host === null) {
            foreach ($params as $key => $val) {
                if (is_string($key) && str_starts_with($key, 'host=')) {
                    $host = substr($key, 5);
                    break;
                }
            }
        }
        $host = is_string($host) && $host !== '' ? $host : '0.0.0.0';

        $port = $params['port'] ?? CLI::getOption('port');
        if ($port === null) {
            foreach ($params as $key => $val) {
                if (is_string($key) && str_starts_with($key, 'port=')) {
                    $port = substr($key, 5);
                    break;
                }
            }
        }
        $port = is_numeric($port) ? (int) $port : 6001;

        CLI::write('Starting Jengo WebSocket Broadcast Server...', 'cyan');
        CLI::write("Listening on ws://{$host}:{$port}");
        CLI::write("HTTP REST endpoint on http://{$host}:{$port}/apps/{app_id}/events");
        CLI::write('Protocols supported: Pusher Protocol v7, RFC 6455 WebSockets');
        CLI::write('Press Ctrl+C to stop the server.');
        CLI::newLine();

        $server = new WebSocketServer($host, $port);
        $server->setLogger(static function (string $level, string $message): void {
            $time = date('H:i:s');
            $color = match ($level) {
                'error'   => 'red',
                'warning' => 'yellow',
                default   => 'green',
            };
            CLI::write("[{$time}] {$message}", $color);
        });

        try {
            $server->listen();
            $server->run();
        } catch (\Throwable $e) {
            CLI::error('WebSocket server failed: ' . $e->getMessage());
        }
    }
}
