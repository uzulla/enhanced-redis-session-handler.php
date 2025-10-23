<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Config;

class RedisConnectionConfig
{
    private string $host;
    private int $port;
    private float $timeout;
    private ?string $password;
    private int $database;
    private string $prefix;
    private bool $persistent;
    private int $retryInterval;
    private float $readTimeout;

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $instance = new self();

        $host = $config['host'] ?? 'localhost';
        assert(is_string($host));
        $instance->host = $host;

        $port = $config['port'] ?? 6379;
        assert(is_int($port));
        $instance->port = $port;

        $timeout = $config['timeout'] ?? 2.5;
        assert(is_float($timeout) || is_int($timeout));
        $instance->timeout = (float)$timeout;

        $password = $config['password'] ?? null;
        assert($password === null || is_string($password));
        $instance->password = $password;

        $database = $config['database'] ?? 0;
        assert(is_int($database));
        $instance->database = $database;

        $prefix = $config['prefix'] ?? 'session:';
        assert(is_string($prefix));
        $instance->prefix = $prefix;

        $persistent = $config['persistent'] ?? false;
        assert(is_bool($persistent));
        $instance->persistent = $persistent;

        $retryInterval = $config['retry_interval'] ?? 100;
        assert(is_int($retryInterval));
        $instance->retryInterval = $retryInterval;

        $readTimeout = $config['read_timeout'] ?? 2.5;
        assert(is_float($readTimeout) || is_int($readTimeout));
        $instance->readTimeout = (float)$readTimeout;

        return $instance;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getDatabase(): int
    {
        return $this->database;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function getRetryInterval(): int
    {
        return $this->retryInterval;
    }

    public function getReadTimeout(): float
    {
        return $this->readTimeout;
    }
}
