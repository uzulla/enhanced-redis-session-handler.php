<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

interface ReadHookInterface
{
    public function beforeRead(string $sessionId): void;

    public function afterRead(string $sessionId, string $data): string;
}
