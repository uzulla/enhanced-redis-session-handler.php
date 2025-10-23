<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

/**
 * Hook that performs cache warmup operations when reading session data.
 *
 * This hook can be used to preload related data or perform other warmup
 * operations when a session is accessed.
 */
class CacheWarmupHook implements ReadHookInterface
{
    private RedisConnection $connection;
    private LoggerInterface $logger;
    /** @var array<string> */
    private array $keysToWarmup;

    /**
     * @param RedisConnection $connection Redis connection for warmup operations
     * @param array<string> $keysToWarmup Array of key patterns to warmup
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(RedisConnection $connection, array $keysToWarmup, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->keysToWarmup = $keysToWarmup;
        $this->logger = $logger;
    }

    public function beforeRead(string $sessionId): void
    {
    }

    public function afterRead(string $sessionId, string $data): string
    {
        $this->performWarmup($sessionId);
        return $data;
    }

    public function onReadError(string $sessionId, \Throwable $e): ?string
    {
        return null;
    }

    private function performWarmup(string $sessionId): void
    {
        foreach ($this->keysToWarmup as $keyPattern) {
            try {
                $key = str_replace('{session_id}', $sessionId, $keyPattern);
                $exists = $this->connection->exists($key);

                if ($exists) {
                    $this->connection->get($key);
                    $this->logger->debug('Cache warmup performed', [
                        'session_id' => $sessionId,
                        'key' => $key,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Cache warmup failed', [
                    'session_id' => $sessionId,
                    'key_pattern' => $keyPattern,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
