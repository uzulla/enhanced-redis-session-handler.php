<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Throwable;

interface ReadHookInterface
{
    /**
     * Called before reading session data from Redis.
     *
     * @param string $sessionId The session ID
     */
    public function beforeRead(string $sessionId): void;

    /**
     * Called after reading session data from Redis.
     *
     * @param string $sessionId The session ID
     * @param string $data The session data read from Redis
     * @return string The modified session data
     */
    public function afterRead(string $sessionId, string $data): string;

    /**
     * Called when an error occurs during read operation.
     *
     * @param string $sessionId The session ID
     * @param Throwable $e The exception that occurred
     * @return string|null Return string data to use as fallback, or null to propagate the error
     */
    public function onReadError(string $sessionId, Throwable $e): ?string;
}
