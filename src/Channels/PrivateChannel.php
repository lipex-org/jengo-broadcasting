<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Channels;

class PrivateChannel extends Channel
{
    /**
     * Create a new PrivateChannel instance.
     */
    public function __construct(string $name)
    {
        // Strip 'private-' if already passed
        $cleanName = str_starts_with($name, 'private-')
            ? substr($name, 8)
            : $name;

        parent::__construct($cleanName);
    }

    /**
     * Return the wire-level channel name with 'private-' prefix.
     */
    public function __toString(): string
    {
        return 'private-' . $this->name;
    }
}
