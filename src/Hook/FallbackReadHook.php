<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

/**
 * Hook that provides fallback read functionality from secondary Redis instances.
 *
 * When the primary Redis fails to read session data, this hook attempts to
 * read from one or more fallback Redis connections in order.
 */
class FallbackReadHook implements ReadHookInterface
{
    /** @var array<RedisConnection> */
    private array $fallbackConnections;
    private LoggerInterface $logger;

    /**
     * @param array<RedisConnection> $fallbackConnections Array of fallback Redis connections
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(array $fallbackConnections, LoggerInterface $logger)
    {
        if (count($fallbackConnections) === 0) {
            throw new \InvalidArgumentException('At least one fallback connection is required');
        }

        $this->fallbackConnections = $fallbackConnections;
        $this->logger = $logger;
    }

    public function beforeRead(string $sessionId): void
    {
    }

    public function afterRead(string $sessionId, string $data): string
    {
        return $data;
    }

    public function onReadError(string $sessionId, \Throwable $e): ?string
    {
        $this->logger->warning('Primary Redis read failed, attempting fallback', [
            'session_id' => $sessionId,
            'exception' => $e,
        ]);

        foreach ($this->fallbackConnections as $index => $connection) {
            try {
                $data = $connection->get($sessionId);
                if ($data !== false) {
                    $this->logger->info('Successfully read from fallback Redis', [
                        'session_id' => $sessionId,
                        'fallback_index' => $index,
                    ]);
                    return $data;
                }
            } catch (\Throwable $fallbackError) {
                $this->logger->warning('Fallback Redis read failed', [
                    'session_id' => $sessionId,
                    'fallback_index' => $index,
                    'exception' => $fallbackError,
                ]);
            }
        }

        $this->logger->debug('All fallback Redis connections failed or returned no data', [
            'session_id' => $sessionId,
        ]);

        return null;
    }
}
