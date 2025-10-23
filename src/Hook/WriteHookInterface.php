<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

interface WriteHookInterface
{
    /**
     * Called before writing session data to Redis.
     *
     * @param string $sessionId The session ID
     * @param array<string, mixed> $data The unserialized session data
     * @return array<string, mixed> The modified session data
     */
    public function beforeWrite(string $sessionId, array $data): array;

    /**
     * Called after writing session data to Redis.
     *
     * @param string $sessionId The session ID
     * @param bool $success Whether the write operation was successful
     */
    public function afterWrite(string $sessionId, bool $success): void;

    /**
     * Called when an error occurs during the write operation.
     *
     * @param string $sessionId The session ID
     * @param \Throwable $exception The exception that occurred
     */
    public function onWriteError(string $sessionId, \Throwable $exception): void;
}
