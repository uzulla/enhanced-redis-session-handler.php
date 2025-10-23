<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

use Uzulla\EnhancedRedisSessionHandler\Exception\ConfigurationException;

class SecureSessionIdGenerator implements SessionIdGeneratorInterface
{
    /**
     * セッションIDの最小文字列長
     */
    public const MIN_LENGTH = 32;

    /**
     * 生成するセッションIDの文字列長（16進数文字列の長さ）
     */
    private int $length;

    public function __construct(int $length = 32)
    {
        if ($length < self::MIN_LENGTH) {
            throw new ConfigurationException(
                sprintf('Session ID length must be at least %d characters', self::MIN_LENGTH)
            );
        }
        if ($length % 2 !== 0) {
            throw new ConfigurationException('Session ID length must be an even number');
        }
        $this->length = $length;
    }

    public function generate(): string
    {
        $byteLength = (int)($this->length / 2);
        assert($byteLength >= 1);
        return bin2hex(random_bytes($byteLength));
    }
}
