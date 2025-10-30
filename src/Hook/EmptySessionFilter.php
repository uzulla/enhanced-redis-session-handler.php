<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

/**
 * Filter that prevents writing empty session data to Redis.
 *
 * This filter implements the PreventEmptySessionCookie feature by detecting
 * when session data is empty and preventing the write operation.
 * This helps avoid sending unnecessary Set-Cookie headers for empty sessions.
 *
 * An empty session is defined as one where the session data array is empty.
 *
 * All logging is performed at DEBUG level as this is intended for debugging purposes only.
 */
class EmptySessionFilter implements WriteFilterInterface
{
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger PSR-3 compatible logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Determine whether the session data should be written to Redis.
     *
     * Returns false if the session data is empty (empty array),
     * preventing the write operation and avoiding unnecessary cookie transmission.
     *
     * @param string $sessionId The session ID
     * @param array<string, mixed> $data The unserialized session data
     * @return bool True to allow the write, false to cancel it
     */
    public function shouldWrite(string $sessionId, array $data): bool
    {
        $isEmpty = count($data) === 0;

        if ($isEmpty) {
            $this->logger->debug(
                'Empty session detected, write operation cancelled',
                [
                    'session_id' => SessionIdMasker::mask($sessionId),
                    'data_empty' => true,
                ]
            );
            return false;
        }

        $this->logger->debug(
            'Session has data, write operation allowed',
            [
                'session_id' => SessionIdMasker::mask($sessionId),
                'data_empty' => false,
                'data_keys' => array_keys($data),
            ]
        );
        return true;
    }
}
