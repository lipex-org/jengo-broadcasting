<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Channels;

use Jengo\Broadcasting\Contracts\ChannelInterface;

class Channel implements ChannelInterface
{
    /**
     * Create a new Channel instance.
     */
    public function __construct(protected string $name)
    {
    }

    /**
     * Get the channel name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Return the wire-level channel name.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
