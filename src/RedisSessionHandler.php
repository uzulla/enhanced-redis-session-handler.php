<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use Psr\Log\LoggerInterface;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private RedisConnection $connection;
    private SessionIdGeneratorInterface $idGenerator;
    private LoggerInterface $logger;
    /** @var array<ReadHookInterface> */
    private array $readHooks = [];
    /** @var array<WriteHookInterface> */
    private array $writeHooks = [];
    /** @var array<WriteFilterInterface> */
    private array $writeFilters = [];
    private int $maxLifetime;

    public function __construct(RedisConnection $connection, ?RedisSessionHandlerOptions $options = null)
    {
        $this->connection = $connection;
        $options = $options ?? new RedisSessionHandlerOptions();

        $this->idGenerator = $options->getIdGenerator();
        $this->maxLifetime = $options->getMaxLifetime();
        $this->logger = $options->getLogger();
    }

    public function addReadHook(ReadHookInterface $hook): void
    {
        $this->readHooks[] = $hook;
    }

    public function addWriteHook(WriteHookInterface $hook): void
    {
        $this->writeHooks[] = $hook;
    }

    /**
     * Add a write filter that can cancel the write operation to Redis.
     * This is useful for implementing conditional writes (e.g., don't write empty sessions).
     */
    public function addWriteFilter(WriteFilterInterface $filter): void
    {
        $this->writeFilters[] = $filter;
    }

    /**
     * Initialize session.
     * Opens the connection to Redis.
     *
     * @param mixed $path Session save path (not used for Redis)
     * @param mixed $name Session name (not used for Redis)
     */
    public function open($path, $name): bool
    {
        try {
            return $this->connection->connect();
        } catch (\Exception $e) {
            $this->logger->error('Failed to open session', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Close the session.
     *
     * This method does nothing because Redis connections are managed by RedisConnection.
     * Persistent connections are kept alive, non-persistent connections are closed when needed.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read session data.
     *
     * Note: The argument is typed as mixed (not string) because PHP 7.4's SessionHandlerInterface
     * uses mixed types. We use assert() to ensure it's actually a string at runtime.
     * The return type is string|false as required by SessionHandlerInterface.
     *
     * @param mixed $id Session ID
     * @return string|false Session data as string, or false on error
     */
    #[\ReturnTypeWillChange]
    public function read($id)
    {
        assert(is_string($id));

        foreach ($this->readHooks as $hook) {
            $hook->beforeRead($id);
        }

        $data = $this->connection->get($id);

        if ($data === false) {
            return '';
        }

        foreach ($this->readHooks as $hook) {
            $data = $hook->afterRead($id, $data);
        }

        return $data;
    }

    /**
     * Write session data to Redis.
     *
     * Unserializes the session data before passing to hooks, then serializes it again before storing.
     * This makes it easier for hooks to inspect and modify the session data.
     *
     * @param mixed $id Session ID
     * @param mixed $data Serialized session data
     */
    public function write($id, $data): bool
    {
        assert(is_string($id));
        assert(is_string($data));

        try {
            /** @var array<string, mixed> $unserializedData */
            $unserializedData = [];
            if ($data !== '') {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    if (is_array($unserialized)) {
                        /** @var array<string, mixed> $unserialized */
                        $unserializedData = $unserialized;
                    }
                }
            }

            foreach ($this->writeHooks as $hook) {
                /** @var array<string, mixed> $unserializedData */
                $unserializedData = $hook->beforeWrite($id, $unserializedData);
            }

            foreach ($this->writeFilters as $filter) {
                /** @var array<string, mixed> $unserializedData */
                if (!$filter->shouldWrite($id, $unserializedData)) {
                    $this->logger->debug('Write operation cancelled by filter', [
                        'session_id' => $id,
                        'filter' => get_class($filter),
                    ]);
                    return true; // Return true because cancellation is not an error
                }
            }

            $serializedData = serialize($unserializedData);

            $ttl = $this->getTTL();
            $success = $this->connection->set($id, $serializedData, $ttl);

            foreach ($this->writeHooks as $hook) {
                $hook->afterWrite($id, $success);
            }

            return $success;
        } catch (\Throwable $e) {
            foreach ($this->writeHooks as $hook) {
                $hook->onWriteError($id, $e);
            }

            $this->logger->error('Write operation failed', [
                'session_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param mixed $id
     */
    public function destroy($id): bool
    {
        assert(is_string($id));
        return $this->connection->delete($id);
    }

    /**
     * Garbage collection.
     *
     * Returns 0 because garbage collection is handled automatically by Redis TTL. (important-comment)
     * Each session key has an expiration time set, so expired sessions are automatically (important-comment)
     * removed by Redis without needing manual garbage collection. (important-comment)
     *
     * @param mixed $max_lifetime Maximum session lifetime
     * @return int|false Number of deleted sessions (always 0 for Redis)
     */
    #[\ReturnTypeWillChange]
    public function gc($max_lifetime)
    {
        return 0;
    }

    /**
     * @param mixed $id
     */
    public function validateId($id): bool
    {
        assert(is_string($id));
        return $this->connection->exists($id);
    }

    /**
     * @param mixed $id
     * @param mixed $data
     */
    public function updateTimestamp($id, $data): bool
    {
        assert(is_string($id));
        $ttl = $this->getTTL();
        return $this->connection->expire($id, $ttl);
    }

    public function create_sid(): string
    {
        do {
            $sessionId = $this->idGenerator->generate();
        } while ($this->connection->exists($sessionId));

        return $sessionId;
    }

    /**
     * Get the TTL (Time To Live) for session keys in Redis.
     *
     * Enforces a minimum TTL of 60 seconds to prevent sessions from expiring too quickly. (important-comment)
     * This is useful when session.gc_maxlifetime is set to a very low value. (important-comment)
     */
    private function getTTL(): int
    {
        return max(60, $this->maxLifetime);
    }
}
