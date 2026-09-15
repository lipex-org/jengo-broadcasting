<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Config;

use CodeIgniter\Config\BaseConfig;

class Broadcasting extends BaseConfig
{
    /**
     * Default broadcasting connection.
     * Supported: 'pusher', 'soketi', 'sse', 'redis', 'ably', 'log', 'null'
     */
    public string $default = 'pusher';

    /**
     * Connection definitions.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $connections = [
        'pusher' => [
            'driver'  => 'pusher',
            'key'     => '',
            'secret'  => '',
            'app_id'  => '',
            'options' => [
                'cluster' => 'mt1',
                'useTLS'  => true,
                'host'    => 'api.pusherapp.com',
                'port'    => 443,
                'scheme'  => 'https',
            ],
        ],

        'soketi' => [
            'driver'  => 'pusher',
            'key'     => 'app-key',
            'secret'  => 'app-secret',
            'app_id'  => 'app-id',
            'options' => [
                'host'    => '127.0.0.1',
                'port'    => 6001,
                'scheme'  => 'http',
                'useTLS'  => false,
            ],
        ],

        'sse' => [
            'driver'       => 'sse',
            'heartbeat'    => 15,
            'cache_prefix' => 'jengo_sse_events',
            'ttl'          => 300,
            'retry'        => 3000,
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => 'default',
            'prefix'     => 'jengo_broadcast:',
            'host'       => '127.0.0.1',
            'port'       => 6379,
            'password'   => null,
            'database'   => 0,
            'timeout'    => 0.0,
        ],

        'ably' => [
            'driver' => 'ably',
            'key'    => '',
        ],

        'log' => [
            'driver' => 'log',
            'level'  => 'info',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ];

    /**
     * Global channel prefix applied to all public, private, and presence channel names.
     */
    public string $prefix = '';
}
