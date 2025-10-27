<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

/**
 * Hook implementation that writes session data to a secondary Redis instance.
 *
 * This is useful for:
 * - Creating backup copies of session data
 * - Replicating sessions across data centers
 * - Migrating sessions to a new Redis instance
 */
class DoubleWriteHook implements WriteHookInterface
{
    private RedisConnection $secondaryConnection;
    private LoggerInterface $logger;
    private bool $failOnSecondaryError;
    private int $ttl;
    /** @var array<string, array<string, mixed>> */
    private array $pendingWrites = [];

    /**
     * @param RedisConnection $secondaryConnection The secondary Redis connection
     * @param int $ttl Time to live for session data in seconds
     * @param bool $failOnSecondaryError If true, throw exception when secondary write fails
     * @param LoggerInterface|null $logger Optional logger for debugging
     */
    public function __construct(
        RedisConnection $secondaryConnection,
        int $ttl = 1440,
        bool $failOnSecondaryError = false,
        ?LoggerInterface $logger = null
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }
        $this->secondaryConnection = $secondaryConnection;
        $this->ttl = $ttl;
        $this->failOnSecondaryError = $failOnSecondaryError;
        $this->logger = $logger ?? new NullLogger();
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        $this->pendingWrites[$sessionId] = $data;
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        if (!$success) {
            $this->logger->warning('Primary write failed, skipping secondary write', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        if (!isset($this->pendingWrites[$sessionId])) {
            $this->logger->warning('No pending write data found for session', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            return;
        }

        try {
            $data = $this->pendingWrites[$sessionId];
            $serializedData = serialize($data);

            $secondarySuccess = $this->secondaryConnection->set($sessionId, $serializedData, $this->ttl);

            if (!$secondarySuccess) {
                $message = 'Secondary Redis write failed';
                $this->logger->error($message, [
                    'session_id' => SessionIdMasker::mask($sessionId),
                ]);

                if ($this->failOnSecondaryError) {
                    throw new RuntimeException($message);
                }
                return;
            }

            $this->logger->debug('Secondary Redis write successful', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Exception during secondary Redis write', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'exception' => $e,
            ]);

            if ($this->failOnSecondaryError) {
                throw $e;
            }
        } finally {
            unset($this->pendingWrites[$sessionId]);
        }
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        $this->logger->error('Primary write error, secondary write skipped', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'exception' => $exception,
        ]);
        unset($this->pendingWrites[$sessionId]);
    }
}
