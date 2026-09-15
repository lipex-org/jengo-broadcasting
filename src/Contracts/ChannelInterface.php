<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Contracts;

use Stringable;

interface ChannelInterface extends Stringable
{
    /**
     * Get the channel name.
     */
    public function getName(): string;

    /**
     * Return the wire-level channel name.
     */
    public function __toString(): string;
}
