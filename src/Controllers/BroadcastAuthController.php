<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Jengo\Broadcasting\Broadcast;

class BroadcastAuthController extends Controller
{
    /**
     * Authenticate the incoming channel subscription request.
     */
    public function authenticate(): ResponseInterface
    {
        $this->loadChannelRoutes();

        $manager = Broadcast::getFacadeRoot();
        $authorizer = $manager->getAuthorizer();

        $channelName = (string) ($this->request->getPost('channel_name') ?? '');
        $socketId    = (string) ($this->request->getPost('socket_id') ?? '');

        if ($channelName === '') {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Missing channel_name parameter.']);
        }

        // Public channels do not require authentication
        if (! str_starts_with($channelName, 'private-') && ! str_starts_with($channelName, 'presence-')) {
            return $this->response->setJSON(['authenticated' => true]);
        }

        $result = $authorizer->authorize($channelName);

        if ($result === false || $result === null) {
            return $this->response
                ->setStatusCode(403)
                ->setJSON(['error' => 'Unauthorized channel access.']);
        }

        $broadcaster = $manager->connection();

        $response = $broadcaster->validAuthenticationResponse($this->request, $result);

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        return $this->response->setJSON($response);
    }

    /**
     * Automatically include application channel definition files if they exist.
     */
    protected function loadChannelRoutes(): void
    {
        $potentialPaths = [
            APPPATH . 'Config/Channels.php',
            ROOTPATH . 'routes/channels.php',
        ];

        foreach ($potentialPaths as $path) {
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
}
