<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Channels;

class PresenceChannel extends Channel
{
    /**
     * Create a new PresenceChannel instance.
     */
    public function __construct(string $name)
    {
        // Strip 'presence-' if already passed
        $cleanName = str_starts_with($name, 'presence-')
            ? substr($name, 9)
            : $name;

        parent::__construct($cleanName);
    }

    /**
     * Return the wire-level channel name with 'presence-' prefix.
     */
    public function __toString(): string
    {
        return 'presence-' . $this->name;
    }
}
