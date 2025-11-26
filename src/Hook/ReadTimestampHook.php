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
 *
 * HookStorageInterfaceが提供された場合はそれを経由してタイムスタンプを記録し、
 * 提供されない場合は直接RedisConnectionを使用します（後方互換性のため）。
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

    /**
     * Called before reading session data from Redis.
     *
     * This hook does not perform any action before reading.
     *
     * @param string $sessionId The session ID
     */
    public function beforeRead(string $sessionId): void
    {
    }

    /**
     * Called after reading session data from Redis.
     *
     * @param string $sessionId The session ID
     * @param string $data The session data read from Redis
     * @param Storage\HookStorageInterface|null $storage Optional HookStorage for timestamp recording
     * @return string The modified session data
     */
    public function afterRead(string $sessionId, string $data, ?Storage\HookStorageInterface $storage = null): string
    {
        $this->recordReadTimestamp($sessionId, $storage);
        return $data;
    }

    /**
     * Called when an error occurs during the read operation.
     *
     * This hook does not provide fallback data for read errors.
     *
     * @param string $sessionId The session ID
     * @param Throwable $e The exception that occurred during read
     * @return null Always returns null (no fallback data)
     */
    public function onReadError(string $sessionId, Throwable $e): ?string
    {
        return null;
    }

    /**
     * Record the read timestamp using HookStorage or direct connection.
     *
     * @param string $sessionId The session ID
     * @param Storage\HookStorageInterface|null $storage Optional HookStorage for timestamp recording
     */
    private function recordReadTimestamp(string $sessionId, ?Storage\HookStorageInterface $storage = null): void
    {
        try {
            $timestampKey = $this->timestampKeyPrefix . $sessionId;
            $timestamp = (string) time();

            if ($storage !== null) {
                $storage->set($timestampKey, $timestamp, $this->timestampTtl);
                $this->logger->debug('Recorded session read timestamp via HookStorage', [
                    'session_id' => SessionIdMasker::mask($sessionId),
                    'timestamp' => $timestamp,
                ]);
                return;
            }

            $this->connection->set($timestampKey, $timestamp, $this->timestampTtl);
            $this->logger->debug('Recorded session read timestamp via direct connection', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'timestamp' => $timestamp,
            ]);
        } catch (Throwable $ex) {
            $this->logger->warning('Failed to record session read timestamp', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'exception' => $ex,
            ]);
        }
    }
}
