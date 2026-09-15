<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Support;

class SseEventStream
{
    /**
     * Format a single SSE event frame.
     *
     * @param string              $event
     * @param mixed               $data
     * @param string|int|null     $id
     * @param int|null            $retry Reconnection retry delay in milliseconds
     */
    public static function formatEvent(
        string $event,
        mixed $data,
        string|int|null $id = null,
        ?int $retry = null
    ): string {
        $payload = '';

        if ($retry !== null) {
            $payload .= 'retry: ' . $retry . "\n";
        }

        if ($id !== null) {
            $payload .= 'id: ' . $id . "\n";
        }

        $payload .= 'event: ' . $event . "\n";

        $json = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload .= 'data: ' . $json . "\n\n";

        return $payload;
    }

    /**
     * Format an SSE keep-alive heartbeat comment.
     */
    public static function formatHeartbeat(string $comment = 'heartbeat'): string
    {
        return ': ' . $comment . "\n\n";
    }

    /**
     * Send headers for an SSE response.
     */
    public static function sendHeaders(): void
    {
        if (! headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable buffering on Nginx
        }
    }
}
