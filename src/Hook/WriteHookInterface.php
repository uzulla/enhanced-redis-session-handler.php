<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

interface WriteHookInterface
{
    public function beforeWrite(string $sessionId, string $data): string;

    public function afterWrite(string $sessionId, bool $success): void;
}
