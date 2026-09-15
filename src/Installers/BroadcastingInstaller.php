<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Installers;

use CodeIgniter\CLI\CLI;
use Jengo\Base\Installers\Contracts\AbstractInstaller;

class BroadcastingInstaller extends AbstractInstaller
{
    public static function name(): string
    {
        return 'broadcasting';
    }

    public static function description(): string
    {
        return 'Install real-time event broadcasting support and publish configuration';
    }

    public static function reasonForSkipping(): string
    {
        return 'Broadcasting configuration already published in app/Config/Broadcasting.php.';
    }

    public function shouldRun(): bool
    {
        return ! file_exists(APPPATH . 'Config/Broadcasting.php');
    }

    public function install(): void
    {
        $this->addRun();

        $destConfig = APPPATH . 'Config/Broadcasting.php';
        if (! file_exists($destConfig)) {
            $source = __DIR__ . '/../Config/Broadcasting.php';
            $content = file_get_contents($source);
            $content = str_replace("namespace Jengo\\Broadcasting\\Config;\n\nuse CodeIgniter\\Config\\BaseConfig;", "namespace Config;\n\nuse Jengo\\Broadcasting\\Config\\Broadcasting as BaseBroadcasting;", $content);
            $content = str_replace("class Broadcasting extends BaseConfig", "class Broadcasting extends BaseBroadcasting", $content);

            @mkdir(dirname($destConfig), 0755, true);
            file_put_contents($destConfig, $content);
            CLI::write('Published Config/Broadcasting.php successfully.', 'green');
        } else {
            CLI::write('Config/Broadcasting.php already exists, skipping.', 'yellow');
        }

        $destChannels = APPPATH . 'Config/Channels.php';
        if (! file_exists($destChannels)) {
            $channelsContent = <<<'PHP'
<?php

declare(strict_types=1);

use Jengo\Broadcasting\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('users.{id}', function ($user, int $id): bool {
    $userId = is_object($user) ? ($user->id ?? 0) : ($user['id'] ?? 0);
    return (int) $userId === (int) $id;
});
PHP;
            file_put_contents($destChannels, $channelsContent);
            CLI::write('Published Config/Channels.php successfully.', 'green');
        }
    }
}
