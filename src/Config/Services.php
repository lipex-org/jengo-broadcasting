<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Config;

use CodeIgniter\Config\BaseService;
use Jengo\Broadcasting\BroadcastManager;
use Jengo\Broadcasting\Config\Broadcasting as BroadcastingConfig;
use Jengo\Broadcasting\Contracts\BroadcasterInterface;

class Services extends BaseService
{
    /**
     * Return the BroadcastManager instance.
     */
    public static function broadcasting(?BroadcastingConfig $config = null, bool $getShared = true): BroadcastManager
    {
        if ($getShared) {
            return static::getSharedInstance('broadcasting', $config);
        }

        return new BroadcastManager($config);
    }

    /**
     * Return a specific broadcaster connection instance.
     */
    public static function broadcaster(?string $connection = null, bool $getShared = true): BroadcasterInterface
    {
        return static::broadcasting(null, $getShared)->connection($connection);
    }
}
