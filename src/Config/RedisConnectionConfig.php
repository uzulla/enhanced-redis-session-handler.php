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
    private int $maxRetries;

    public function __construct(
        string $host = 'localhost',
        int $port = 6379,
        float $timeout = 2.5,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'session:',
        bool $persistent = false,
        int $retryInterval = 100,
        float $readTimeout = 2.5,
        int $maxRetries = 3
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->password = $password;
        $this->database = $database;
        $this->prefix = $prefix;
        $this->persistent = $persistent;
        $this->retryInterval = $retryInterval;
        $this->readTimeout = $readTimeout;
        $this->maxRetries = $maxRetries;
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

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }
}
