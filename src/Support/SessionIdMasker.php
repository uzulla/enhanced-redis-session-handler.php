<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Support;

/**
 * Utility class for masking session IDs in logs to prevent session hijacking.
 */
class SessionIdMasker
{
    /**
     * Mask session ID for secure logging.
     * Shows only the last 4 characters to allow correlation while preventing hijacking.
     *
     * @param string $sessionId The session ID to mask
     * @return string The masked session ID
     */
    public static function mask(string $sessionId): string
    {
        if (strlen($sessionId) <= 4) {
            return '...' . $sessionId;
        }
        return '...' . substr($sessionId, -4);
    }
}
