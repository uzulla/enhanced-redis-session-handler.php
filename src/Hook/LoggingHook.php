<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Hook implementation that logs session write operations.
 *
 * This is useful for:
 * - Debugging session issues
 * - Auditing session access
 * - Monitoring session activity
 */
class LoggingHook implements WriteHookInterface
{
    private LoggerInterface $logger;
    private string $beforeWriteLevel;
    private string $afterWriteLevel;
    private string $errorLevel;
    private bool $logData;

    /**
     * @param LoggerInterface $logger PSR-3 compatible logger
     * @param string $beforeWriteLevel Log level for beforeWrite (default: debug)
     * @param string $afterWriteLevel Log level for afterWrite (default: debug)
     * @param string $errorLevel Log level for onWriteError (default: error)
     * @param bool $logData Whether to include session data in logs (default: false for security)
     */
    public function __construct(
        LoggerInterface $logger,
        string $beforeWriteLevel = LogLevel::DEBUG,
        string $afterWriteLevel = LogLevel::DEBUG,
        string $errorLevel = LogLevel::ERROR,
        bool $logData = false
    ) {
        $this->logger = $logger;
        $this->beforeWriteLevel = $beforeWriteLevel;
        $this->afterWriteLevel = $afterWriteLevel;
        $this->errorLevel = $errorLevel;
        $this->logData = $logData;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        $context = [
            'session_id' => $sessionId,
            'data_keys' => array_keys($data),
            'data_size' => count($data),
        ];

        if ($this->logData) {
            $context['data'] = $data;
        }

        $this->logger->log(
            $this->beforeWriteLevel,
            'Session write starting',
            $context
        );

        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        $this->logger->log(
            $this->afterWriteLevel,
            $success ? 'Session write successful' : 'Session write failed',
            [
                'session_id' => $sessionId,
                'success' => $success,
            ]
        );
    }

    public function onWriteError(string $sessionId, \Throwable $exception): void
    {
        $this->logger->log(
            $this->errorLevel,
            'Session write error occurred',
            [
                'session_id' => $sessionId,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_code' => $exception->getCode(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]
        );
    }
}
