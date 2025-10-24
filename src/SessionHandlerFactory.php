<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class SessionHandlerFactory
{
    private SessionConfig $config;

    public function __construct(?SessionConfig $config = null)
    {
        $this->config = $config ?? new SessionConfig();
    }

    public static function create(?SessionConfig $config = null): self
    {
        return new self($config);
    }

    public static function createDefault(): self
    {
        return new self(new SessionConfig());
    }

    public function withConnectionConfig(RedisConnectionConfig $config): self
    {
        $this->config->setConnectionConfig($config);
        return $this;
    }

    public function withHost(string $host): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $host,
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withPort(int $port): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $port,
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withPassword(?string $password): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $password,
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withDatabase(int $database): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $database,
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withPrefix(string $prefix): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $prefix,
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withPersistent(bool $persistent): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $persistent,
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withTimeout(float $timeout): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $timeout,
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withReadTimeout(float $readTimeout): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $readTimeout,
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withMaxRetries(int $maxRetries): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $currentConfig->getRetryInterval(),
            $currentConfig->getReadTimeout(),
            $maxRetries
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withRetryInterval(int $retryInterval): self
    {
        $currentConfig = $this->config->getConnectionConfig();
        $newConfig = new RedisConnectionConfig(
            $currentConfig->getHost(),
            $currentConfig->getPort(),
            $currentConfig->getTimeout(),
            $currentConfig->getPassword(),
            $currentConfig->getDatabase(),
            $currentConfig->getPrefix(),
            $currentConfig->isPersistent(),
            $retryInterval,
            $currentConfig->getReadTimeout(),
            $currentConfig->getMaxRetries()
        );
        $this->config->setConnectionConfig($newConfig);
        return $this;
    }

    public function withIdGenerator(SessionIdGeneratorInterface $generator): self
    {
        $this->config->setIdGenerator($generator);
        return $this;
    }

    public function withMaxLifetime(int $maxLifetime): self
    {
        $this->config->setMaxLifetime($maxLifetime);
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->config->setLogger($logger);
        return $this;
    }

    public function withReadHook(ReadHookInterface $hook): self
    {
        $this->config->addReadHook($hook);
        return $this;
    }

    public function withWriteHook(WriteHookInterface $hook): self
    {
        $this->config->addWriteHook($hook);
        return $this;
    }

    public function withWriteFilter(WriteFilterInterface $filter): self
    {
        $this->config->addWriteFilter($filter);
        return $this;
    }

    public function build(): RedisSessionHandler
    {
        $redis = new \Redis();
        $connection = new RedisConnection(
            $redis,
            $this->config->getConnectionConfig(),
            $this->config->getLogger()
        );

        $options = new RedisSessionHandlerOptions(
            $this->config->getIdGenerator(),
            $this->config->getMaxLifetime(),
            $this->config->getLogger()
        );

        $handler = new RedisSessionHandler($connection, $options);

        foreach ($this->config->getReadHooks() as $hook) {
            $handler->addReadHook($hook);
        }

        foreach ($this->config->getWriteHooks() as $hook) {
            $handler->addWriteHook($hook);
        }

        foreach ($this->config->getWriteFilters() as $filter) {
            $handler->addWriteFilter($filter);
        }

        return $handler;
    }

    public function getConfig(): SessionConfig
    {
        return $this->config;
    }
}
