<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Util;

/**
 * Utility class for masking session IDs in logs to prevent sensitive data exposure.
 *
 * Session IDs are sensitive information that should not be fully exposed in logs.
 * This class provides a method to mask session IDs while keeping enough information
 * for debugging purposes.
 */
class SessionIdMasker
{
    /**
     * Number of characters to show at the beginning of the session ID.
     * The rest will be masked with asterisks.
     */
    private const VISIBLE_PREFIX_LENGTH = 8;

    /**
     * Mask a session ID for safe logging.
     *
     * Shows the first 8 characters and masks the rest with asterisks.
     * This provides enough information for debugging while protecting the full session ID.
     *
     * Examples:
     * - "abcdef1234567890" -> "abcdef12********"
     * - "short" -> "short"
     * - "" -> ""
     *
     * @param string $sessionId The session ID to mask
     * @return string The masked session ID
     */
    public static function mask(string $sessionId): string
    {
        if ($sessionId === '') {
            return '';
        }

        $length = strlen($sessionId);

        if ($length <= self::VISIBLE_PREFIX_LENGTH) {
            return $sessionId;
        }

        $visible = substr($sessionId, 0, self::VISIBLE_PREFIX_LENGTH);
        $masked = str_repeat('*', $length - self::VISIBLE_PREFIX_LENGTH);

        return $visible . $masked;
    }
}
