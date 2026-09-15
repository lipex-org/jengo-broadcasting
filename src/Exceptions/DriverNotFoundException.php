<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Exceptions;

class DriverNotFoundException extends BroadcastException
{
    public static function forDriver(string $driver): self
    {
        return new self("Broadcasting driver [{$driver}] is not supported or configuration is missing.");
    }
}
