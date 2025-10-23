<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

/**
 * Interface for filters that can cancel write operations to Redis.
 *
 * This is different from WriteHookInterface which transforms data.
 * WriteFilterInterface can prevent the write operation entirely based on conditions.
 */
interface WriteFilterInterface
{
    /**
     * Determine whether the session data should be written to Redis.
     *
     * @param string $sessionId The session ID
     * @param string $data The session data to be written
     * @return bool True to allow the write, false to cancel it
     */
    public function shouldWrite(string $sessionId, string $data): bool;
}
