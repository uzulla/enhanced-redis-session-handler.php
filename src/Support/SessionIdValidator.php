<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Support;

use InvalidArgumentException;

/**
 * Utility class for validating session IDs.
 *
 * This class provides consistent session ID validation across the library.
 */
class SessionIdValidator
{
    /**
     * Regular expression pattern for valid session ID characters.
     * Allows alphanumeric characters, hyphens, and underscores.
     */
    public const VALID_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * Maximum allowed length for session IDs.
     */
    public const MAX_LENGTH = 256;

    /**
     * Minimum recommended length for session IDs (for security).
     */
    public const MIN_RECOMMENDED_LENGTH = 16;

    /**
     * Check if a session ID is valid without throwing an exception.
     *
     * @param string $sessionId The session ID to validate
     * @param bool $trim Whether to trim whitespace before validation (default: false)
     * @return bool True if valid, false otherwise
     */
    public static function isValid(string $sessionId, bool $trim = false): bool
    {
        if ($trim) {
            $sessionId = trim($sessionId);
        }

        if ($sessionId === '') {
            return false;
        }

        if (preg_match(self::VALID_PATTERN, $sessionId) !== 1) {
            return false;
        }

        if (strlen($sessionId) > self::MAX_LENGTH) {
            return false;
        }

        return true;
    }

    /**
     * Validate a session ID and throw an exception if invalid.
     *
     * @param string $sessionId The session ID to validate
     * @throws InvalidArgumentException If the session ID is invalid
     */
    public static function validate(string $sessionId): void
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException('Session ID cannot be empty');
        }

        if (preg_match(self::VALID_PATTERN, $sessionId) !== 1) {
            throw new InvalidArgumentException('Session ID contains invalid characters');
        }

        if (strlen($sessionId) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'Session ID exceeds maximum length of ' . self::MAX_LENGTH . ' characters'
            );
        }
    }

    /**
     * Check if a session ID is shorter than the recommended minimum length.
     *
     * @param string $sessionId The session ID to check
     * @return bool True if shorter than recommended, false otherwise
     */
    public static function isShorterThanRecommended(string $sessionId): bool
    {
        return strlen($sessionId) < self::MIN_RECOMMENDED_LENGTH;
    }

    /**
     * Sanitize a session ID by trimming whitespace.
     *
     * @param string $sessionId The session ID to sanitize
     * @return string The sanitized session ID
     */
    public static function sanitize(string $sessionId): string
    {
        return trim($sessionId);
    }
}
