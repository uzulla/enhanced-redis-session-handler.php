<?php

namespace Uzulla\EnhancedRedisSessionHandler;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private RedisConnection $connection;
    private SessionIdGeneratorInterface $idGenerator;
    /** @var array<ReadHookInterface> */
    private array $readHooks = [];
    /** @var array<WriteHookInterface> */
    private array $writeHooks = [];
    private int $maxLifetime;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(RedisConnection $connection, array $options = [])
    {
        $this->connection = $connection;
        $idGenerator = $options['id_generator'] ?? null;
        $this->idGenerator = $idGenerator instanceof SessionIdGeneratorInterface
            ? $idGenerator
            : new DefaultSessionIdGenerator();

        $maxLifetime = $options['max_lifetime'] ?? null;
        $this->maxLifetime = is_int($maxLifetime)
            ? $maxLifetime
            : (int)ini_get('session.gc_maxlifetime');
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
     * @param mixed $path
     * @param mixed $name
     */
    public function open($path, $name): bool
    {
        try {
            return $this->connection->connect();
        } catch (\Exception $e) {
            error_log('[ERROR] Failed to open session: ' . $e->getMessage());
            return false;
        }
    }

    public function close(): bool
    {
        return true;
    }

    /**
     * @param mixed $id
     * @return string|false
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
     * @param mixed $id
     * @param mixed $data
     */
    public function write($id, $data): bool
    {
        assert(is_string($id));
        assert(is_string($data));

        foreach ($this->writeHooks as $hook) {
            $data = $hook->beforeWrite($id, $data);
        }

        $ttl = $this->getTTL();
        $success = $this->connection->set($id, $data, $ttl);

        foreach ($this->writeHooks as $hook) {
            $hook->afterWrite($id, $success);
        }

        return $success;
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
     * @param mixed $max_lifetime
     * @return int|false
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

    private function getTTL(): int
    {
        return max(60, $this->maxLifetime);
    }
}
