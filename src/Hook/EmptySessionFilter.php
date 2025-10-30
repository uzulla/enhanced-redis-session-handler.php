<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Uzulla\EnhancedRedisSessionHandler\Util\SessionIdMasker;

/**
 * Filter that prevents writing empty session data to Redis.
 *
 * This filter implements the PreventEmptySessionCookie feature by detecting
 * when session data is empty and preventing the write operation.
 * This helps avoid sending unnecessary Set-Cookie headers for empty sessions.
 *
 * An empty session is defined as one where the session data array is empty.
 */
class EmptySessionFilter implements WriteFilterInterface
{
    private LoggerInterface $logger;
    private string $logLevel;

    /**
     * @param LoggerInterface $logger PSR-3 compatible logger
     * @param string $logLevel Log level for filter decisions (default: debug)
     */
    public function __construct(
        LoggerInterface $logger,
        string $logLevel = LogLevel::DEBUG
    ) {
        $this->logger = $logger;
        $this->logLevel = $logLevel;
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
            $this->logger->log(
                $this->logLevel,
                'Empty session detected, write operation cancelled',
                [
                    'session_id' => SessionIdMasker::mask($sessionId),
                    'data_empty' => true,
                ]
            );
            return false;
        }

        $this->logger->log(
            $this->logLevel,
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
