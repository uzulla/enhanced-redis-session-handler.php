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

    public function open(string $path, string $name): bool
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

    public function read(string $id): string|false
    {
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

    public function write(string $id, string $data): bool
    {
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

    public function destroy(string $id): bool
    {
        return $this->connection->delete($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function validateId(string $id): bool
    {
        return $this->connection->exists($id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
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
