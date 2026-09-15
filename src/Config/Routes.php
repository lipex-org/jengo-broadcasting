<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Config;

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('broadcasting', static function ($routes) {
    $routes->post('auth', '\Jengo\Broadcasting\Controllers\BroadcastAuthController::authenticate');
    $routes->get('sse', '\Jengo\Broadcasting\Controllers\SseStreamController::stream');
});
