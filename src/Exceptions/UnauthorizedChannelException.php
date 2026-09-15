<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Exceptions;

class UnauthorizedChannelException extends BroadcastException
{
    public static function forChannel(string $channel): self
    {
        return new self("Unauthorized access to broadcasting channel [{$channel}].");
    }
}
