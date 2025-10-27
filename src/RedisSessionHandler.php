<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface, LoggerAwareInterface
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
    private SessionSerializerInterface $serializer;

    public function __construct(
        RedisConnection $connection,
        SessionSerializerInterface $serializer,
        ?RedisSessionHandlerOptions $options = null
    ) {
        $this->connection = $connection;
        $this->serializer = $serializer;
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
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Initialize session.
     * Opens the connection to Redis and validates that the injected serializer matches
     * the session.serialize_handler INI setting.
     *
     * @param mixed $path Session save path (not used for Redis)
     * @param mixed $name Session name (not used for Redis)
     * @throws Exception\ConfigurationException if serializer doesn't match session.serialize_handler
     */
    public function open($path, $name): bool
    {
        try {
            $serializeHandler = ini_get('session.serialize_handler');
            if ($serializeHandler === false || $serializeHandler === '') {
                $serializeHandler = 'php'; // PHP default
            }

            $serializerName = $this->serializer->getName();
            if ($serializerName !== $serializeHandler) {
                throw new Exception\ConfigurationException(
                    sprintf(
                        'Serializer mismatch: injected serializer is "%s" but session.serialize_handler is "%s". ' .
                        'Please ensure the serializer matches the INI setting.',
                        $serializerName,
                        $serializeHandler
                    )
                );
            }

            $this->logger->debug('Session serializer validated', [
                'serialize_handler' => $serializeHandler,
                'serializer' => $serializerName,
            ]);

            return $this->connection->connect();
        } catch (Throwable $e) {
            $this->logger->error('Failed to open session', [
                'exception' => $e,
            ]);

            if ($e instanceof Exception\ConfigurationException) {
                throw $e;
            }

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
     * The data returned from Redis is already in the format expected by PHP's session extension,
     * so we return it as-is without any deserialization. The session extension will handle
     * deserialization based on session.serialize_handler.
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

        try {
            $data = $this->connection->get($id);

            if ($data === false) {
                $this->logger->debug('Session not found in Redis', [
                    'session_id' => SessionIdMasker::mask($id),
                ]);
                return '';
            }

            foreach ($this->readHooks as $hook) {
                $data = $hook->afterRead($id, $data);
            }

            return $data;
        } catch (Throwable $e) {
            $this->logger->error('Error during session read', [
                'session_id' => SessionIdMasker::mask($id),
                'exception' => $e,
            ]);

            foreach ($this->readHooks as $hook) {
                $fallbackData = $hook->onReadError($id, $e);
                if ($fallbackData !== null) {
                    $this->logger->info('Using fallback data from hook', [
                        'session_id' => SessionIdMasker::mask($id),
                        'hook' => get_class($hook),
                    ]);
                    return $fallbackData;
                }
            }

            return '';
        }
    }

    /**
     * Write session data to Redis.
     *
     * Deserializes the session data using the appropriate serializer (based on session.serialize_handler)
     * before passing to hooks, then serializes it again before storing. This makes it easier for hooks
     * to inspect and modify the session data.
     *
     * @param mixed $id Session ID
     * @param mixed $data Serialized session data (format depends on session.serialize_handler)
     */
    public function write($id, $data): bool
    {
        assert(is_string($id));
        assert(is_string($data));

        try {
            /** @var array<string, mixed> $unserializedData */
            $unserializedData = [];
            if ($data !== '') {
                try {
                    $unserializedData = $this->serializer->decode($data);
                } catch (Exception\SessionDataException $e) {
                    $this->logger->warning('Failed to deserialize session data', [
                        'session_id' => SessionIdMasker::mask($id),
                        'exception' => $e,
                    ]);
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
                        'session_id' => SessionIdMasker::mask($id),
                        'filter' => get_class($filter),
                    ]);
                    return true; // Return true because cancellation is not an error
                }
            }

            $serializedData = $this->serializer->encode($unserializedData);

            $ttl = $this->getTTL();
            $success = $this->connection->set($id, $serializedData, $ttl);

            foreach ($this->writeHooks as $hook) {
                $hook->afterWrite($id, $success);
            }

            return $success;
        } catch (Throwable $e) {
            foreach ($this->writeHooks as $hook) {
                $hook->onWriteError($id, $e);
            }

            $this->logger->error('Write operation failed', [
                'session_id' => SessionIdMasker::mask($id),
                'exception' => $e,
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
        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $sessionId = $this->idGenerator->generate();
            if (!$this->connection->exists($sessionId)) {
                if ($attempt > 1) {
                    $this->logger->warning('Session ID collision occurred', [
                        'attempts' => $attempt,
                    ]);
                }
                return $sessionId;
            }
        }

        // ループを抜けた = 全ての試行で衝突が発生した
        $this->logger->critical('Failed to generate unique session ID after maximum attempts', [
            'attempts' => $maxAttempts,
        ]);
        throw new Exception\OperationException('Failed to generate unique session ID');
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
