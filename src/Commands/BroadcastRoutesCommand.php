<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Jengo\Broadcasting\Broadcast;

class BroadcastRoutesCommand extends BaseCommand
{
    protected $group       = 'Broadcasting';
    protected $name        = 'broadcast:routes';
    protected $description = 'List all registered broadcasting channel authorization routes and patterns.';
    protected $usage       = 'broadcast:routes';

    public function run(array $params)
    {
        $this->loadChannelDefinitions();

        $manager = Broadcast::getFacadeRoot();
        $channels = $manager->getAuthorizer()->getChannels();

        if (empty($channels)) {
            CLI::write('No broadcast channels have been registered.', 'yellow');
            CLI::write('Define channels in app/Config/Channels.php using Broadcast::channel().');
            return;
        }

        CLI::write('Registered Broadcast Channels:', 'cyan');

        $rows = [];
        foreach ($channels as $pattern => $entry) {
            $rows[] = [
                'Pattern' => $pattern,
                'Regex'   => $entry['regex'],
                'Guard'   => $entry['options']['guard'] ?? 'default',
            ];
        }

        CLI::table($rows, ['Pattern', 'Regex Matcher', 'Guard']);
    }

    protected function loadChannelDefinitions(): void
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
