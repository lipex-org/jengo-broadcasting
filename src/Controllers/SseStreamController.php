<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Broadcast;
use Jengo\Broadcasting\Drivers\SseBroadcaster;
use Jengo\Broadcasting\Support\SseEventStream;

class SseStreamController extends Controller
{
    /**
     * Stream Server-Sent Events to the client.
     */
    public function stream(): ResponseInterface
    {
        $channelsParam = (string) ($this->request->getGet('channels') ?? '');
        $channels = array_filter(array_map('trim', explode(',', $channelsParam)));

        if (empty($channels)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'No channels specified for SSE streaming.']);
        }

        $manager = Broadcast::getFacadeRoot();
        $authorizer = $manager->getAuthorizer();

        // Verify authorization for any private or presence channels
        foreach ($channels as $channel) {
            if (str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-')) {
                $result = $authorizer->authorize($channel);
                if ($result === false || $result === null) {
                    return $this->response
                        ->setStatusCode(403)
                        ->setJSON(["error" => "Unauthorized access to channel [{$channel}]."]);
                }
            }
        }

        /** @var SseBroadcaster $broadcaster */
        $broadcaster = $manager->driver('sse');
        if (! $broadcaster instanceof SseBroadcaster) {
            $broadcaster = new SseBroadcaster();
        }

        $lastEventId = $this->request->getHeaderLine('Last-Event-ID');
        if ($lastEventId === '') {
            $lastEventId = (string) ($this->request->getGet('last_event_id') ?? '');
        }
        $lastEventId = $lastEventId !== '' ? $lastEventId : null;

        // In testing environment, return non-blocking single-frame snapshot
        if (ENVIRONMENT === 'testing') {
            $events = $broadcaster->pullEvents($channels, $lastEventId);
            $output = SseEventStream::formatHeartbeat('connected');

            foreach ($events as $item) {
                $output .= SseEventStream::formatEvent(
                    $item['event'],
                    $item['payload'],
                    $item['id'],
                    $broadcaster->getRetryDelay()
                );
            }

            return $this->response
                ->setContentType('text/event-stream')
                ->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->setHeader('Connection', 'keep-alive')
                ->setHeader('X-Accel-Buffering', 'no')
                ->setBody($output);
        }

        // Production streaming loop
        SseEventStream::sendHeaders();
        echo SseEventStream::formatHeartbeat('connected');
        flush();

        $heartbeatInterval = $broadcaster->getHeartbeatInterval();
        $retryDelay = $broadcaster->getRetryDelay();
        $lastHeartbeat = time();

        $maxExecutionTime = 30; // 30 seconds max connection lifecycle to prevent hung workers
        $startTime = time();

        while (! connection_aborted() && (time() - $startTime) < $maxExecutionTime) {
            $events = $broadcaster->pullEvents($channels, $lastEventId);

            foreach ($events as $item) {
                echo SseEventStream::formatEvent(
                    $item['event'],
                    $item['payload'],
                    $item['id'],
                    $retryDelay
                );
                $lastEventId = (string) $item['id'];
                flush();
            }

            if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                echo SseEventStream::formatHeartbeat();
                flush();
                $lastHeartbeat = time();
            }

            usleep(250000); // Poll every 250ms
        }

        exit;
    }
}
