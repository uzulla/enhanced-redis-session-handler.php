<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

/**
 * Example hook that tracks session read timestamps.
 *
 * This is a sample implementation that demonstrates how to use ReadHookInterface.
 * It stores the last read timestamp for each session in a separate Redis key.
 */
class ReadTimestampHook implements ReadHookInterface
{
    private RedisConnection $connection;
    private LoggerInterface $logger;
    private string $timestampKeyPrefix;
    private int $timestampTtl;

    /**
     * @param RedisConnection $connection Redis connection for storing timestamps
     * @param LoggerInterface $logger Logger instance
     * @param string $timestampKeyPrefix Prefix for timestamp keys (default: 'session:read_at:')
     * @param int $timestampTtl TTL for timestamp keys in seconds (default: 86400 = 24 hours)
     */
    public function __construct(
        RedisConnection $connection,
        LoggerInterface $logger,
        string $timestampKeyPrefix = 'session:read_at:',
        int $timestampTtl = 86400
    ) {
        if ($timestampKeyPrefix === '') {
            throw new InvalidArgumentException('Timestamp key prefix cannot be empty');
        }
        if ($timestampTtl <= 0) {
            throw new InvalidArgumentException('Timestamp TTL must be positive');
        }

        $this->connection = $connection;
        $this->logger = $logger;
        $this->timestampKeyPrefix = $timestampKeyPrefix;
        $this->timestampTtl = $timestampTtl;
    }

    public function beforeRead(string $sessionId): void
    {
    }

    public function afterRead(string $sessionId, string $data): string
    {
        $this->recordReadTimestamp($sessionId);
        return $data;
    }

    public function onReadError(string $sessionId, Throwable $e): ?string
    {
        return null;
    }

    private function recordReadTimestamp(string $sessionId): void
    {
        try {
            $timestampKey = $this->timestampKeyPrefix . $sessionId;
            $timestamp = (string) time();
            $this->connection->set($timestampKey, $timestamp, $this->timestampTtl);

            $this->logger->debug('Recorded session read timestamp', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'timestamp' => $timestamp,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to record session read timestamp', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'exception' => $e,
            ]);
        }
    }
}
