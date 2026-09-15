<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Support;

class PresenceMember
{
    /**
     * @param string|int           $userId
     * @param array<string, mixed> $userInfo
     */
    public function __construct(
        public string|int $userId,
        public array $userInfo = []
    ) {
    }

    /**
     * Convert to array representation for Pusher/presence protocol.
     *
     * @return array{user_id: string, user_info: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'user_id'   => (string) $this->userId,
            'user_info' => $this->userInfo,
        ];
    }
}
