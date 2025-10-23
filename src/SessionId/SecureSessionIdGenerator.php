<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

use Uzulla\EnhancedRedisSessionHandler\Exception\ConfigurationException;

class SecureSessionIdGenerator implements SessionIdGeneratorInterface
{
    private int $length;

    public function __construct(int $length = 32)
    {
        if ($length < 1) {
            throw new ConfigurationException('Session ID length must be at least 1');
        }
        $this->length = $length;
    }

    public function generate(): string
    {
        assert($this->length >= 1);
        return bin2hex(random_bytes($this->length));
    }
}
