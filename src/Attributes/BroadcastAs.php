<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BroadcastAs
{
    public function __construct(public string $name)
    {
    }
}
