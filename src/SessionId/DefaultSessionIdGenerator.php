<?php

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

class DefaultSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
