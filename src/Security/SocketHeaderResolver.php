<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Security;

use CodeIgniter\HTTP\IncomingRequest;

class SocketHeaderResolver
{
    /**
     * Extract the socket ID from the given request.
     */
    public static function resolve(?IncomingRequest $request = null): ?string
    {
        $request ??= service('request');

        if (! $request instanceof IncomingRequest) {
            return null;
        }

        // Check header 'X-Socket-ID'
        $header = $request->header('X-Socket-ID') ?? $request->header('X-Socket-Id');
        if ($header !== null && $header->getValue() !== '') {
            return $header->getValue();
        }

        // Check POST parameter 'socket_id'
        $postSocket = $request->getPost('socket_id');
        if (is_string($postSocket) && $postSocket !== '') {
            return $postSocket;
        }

        // Check GET parameter 'socket_id'
        $getSocket = $request->getGet('socket_id');
        if (is_string($getSocket) && $getSocket !== '') {
            return $getSocket;
        }

        return null;
    }
}
