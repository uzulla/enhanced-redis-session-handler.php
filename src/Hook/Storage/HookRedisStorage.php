<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

/**
 * Redis-backed implementation of HookStorageInterface with depth tracking.
 *
 * This class wraps RedisConnection and tracks execution depth to prevent
 * infinite recursion when hooks perform Redis operations that themselves
 * trigger hooks.
 *
 * Architecture:
 * - Delegates actual Redis operations to RedisConnection
 * - Uses HookContext to track execution depth
 * - Logs warnings when depth limits are approached or exceeded
 * - Falls back to direct execution when depth limit is exceeded (graceful degradation)
 *
 * Design decisions:
 * - Graceful degradation rather than failure when depth exceeded
 * - Warning-level logging for depth issues (not errors, to avoid alert fatigue)
 * - Minimal performance overhead from depth checking
 * - Compatible with PSR-12 and PHPStan strict rules
 *
 * Example usage:
 * ```php
 * $context = new HookContext(3);
 * $storage = new HookRedisStorage($redisConnection, $context, $logger);
 *
 * // This will track depth and warn if limits exceeded
 * $storage->set('key', 'value', 3600);
 * ```
 */
class HookRedisStorage implements HookStorageInterface
{
    /**
     * The underlying Redis connection.
     *
     * @var RedisConnection
     */
    private RedisConnection $connection;

    /**
     * Context for tracking execution depth.
     *
     * @var HookContext
     */
    private HookContext $context;

    /**
     * Logger for monitoring and debugging.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Create a new HookRedisStorage instance.
     *
     * @param RedisConnection $connection The Redis connection to wrap
     * @param HookContext $context Context for depth tracking
     * @param LoggerInterface|null $logger Optional logger (uses NullLogger if not provided)
     */
    public function __construct(
        RedisConnection $connection,
        HookContext $context,
        ?LoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->context = $context;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get a value from Redis by key with depth tracking.
     *
     * @param string $key The storage key
     * @return string|false Returns the value as string if found, false if not found or on error
     */
    public function get(string $key)
    {
        $this->context->incrementDepth();

        try {
            if ($this->context->isDepthExceeded()) {
                $this->logger->warning('Hook storage depth limit exceeded for GET operation', [
                    'current_depth' => $this->context->getDepth(),
                    'max_depth' => $this->context->getMaxDepth(),
                    'operation' => 'get',
                ]);
            }

            // Even if depth is exceeded, we still execute (graceful degradation)
            return $this->connection->get($key);
        } finally {
            $this->context->decrementDepth();
        }
    }

    /**
     * Set a value in Redis with a time-to-live and depth tracking.
     *
     * @param string $key The storage key
     * @param string $value The value to store
     * @param int $ttl Time-to-live in seconds (must be positive)
     * @return bool True if the operation succeeded, false otherwise
     */
    public function set(string $key, string $value, int $ttl): bool
    {
        $this->context->incrementDepth();

        try {
            if ($this->context->isDepthExceeded()) {
                $this->logger->warning('Hook storage depth limit exceeded for SET operation', [
                    'current_depth' => $this->context->getDepth(),
                    'max_depth' => $this->context->getMaxDepth(),
                    'operation' => 'set',
                ]);
            }

            // Even if depth is exceeded, we still execute (graceful degradation)
            return $this->connection->set($key, $value, $ttl);
        } finally {
            $this->context->decrementDepth();
        }
    }

    /**
     * Delete a value from Redis by key with depth tracking.
     *
     * @param string $key The storage key
     * @return bool True if the key was deleted, false if the key didn't exist or on error
     */
    public function delete(string $key): bool
    {
        $this->context->incrementDepth();

        try {
            if ($this->context->isDepthExceeded()) {
                $this->logger->warning('Hook storage depth limit exceeded for DELETE operation', [
                    'current_depth' => $this->context->getDepth(),
                    'max_depth' => $this->context->getMaxDepth(),
                    'operation' => 'delete',
                ]);
            }

            // Even if depth is exceeded, we still execute (graceful degradation)
            return $this->connection->delete($key);
        } finally {
            $this->context->decrementDepth();
        }
    }

    /**
     * Get the current execution depth.
     *
     * This method is primarily for testing and debugging purposes.
     *
     * @return int Current execution depth
     */
    public function getDepth(): int
    {
        return $this->context->getDepth();
    }

    /**
     * Get the underlying HookContext instance.
     *
     * This method is primarily for testing purposes.
     *
     * @return HookContext The context instance
     */
    public function getContext(): HookContext
    {
        return $this->context;
    }
}
