<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Exceptions;

class ChannelNotFoundException extends BroadcastException
{
    public static function forChannel(string $channel): self
    {
        return new self("Broadcasting channel [{$channel}] was not found or is undefined.");
    }
}
