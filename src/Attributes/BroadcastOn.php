<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BroadcastOn
{
    /**
     * @param string|array<int, string> $channels
     */
    public function __construct(public string|array $channels)
    {
    }
}
