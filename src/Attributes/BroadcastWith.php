<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BroadcastWith
{
    /**
     * @param array<int, string> $properties
     */
    public function __construct(public array $properties = [])
    {
    }
}
