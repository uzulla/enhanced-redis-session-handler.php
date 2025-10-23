<?php

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

class SecureSessionIdGenerator implements SessionIdGeneratorInterface
{
    private int $length;

    public function __construct(int $length = 32)
    {
        if ($length < 1) {
            $length = 32;
        }
        $this->length = $length;
    }

    public function generate(): string
    {
        assert($this->length >= 1);
        return bin2hex(random_bytes($this->length));
    }
}
