<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Exception;

use InvalidArgumentException;

/**
 * Exception thrown when a session ID has invalid format.
 *
 * This exception is thrown when:
 * - Session ID is empty
 * - Session ID contains invalid characters
 * - Session ID exceeds maximum length
 */
class InvalidSessionIdException extends InvalidArgumentException
{
}
