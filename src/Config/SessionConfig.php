<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConfigurationException;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class SessionConfig
{
    private RedisConnectionConfig $connectionConfig;
    private SessionSerializerInterface $serializer;
    private SessionIdGeneratorInterface $idGenerator;
    private int $maxLifetime;
    private LoggerInterface $logger;
    /** @var array<ReadHookInterface> */
    private array $readHooks = [];
    /** @var array<WriteHookInterface> */
    private array $writeHooks = [];
    /** @var array<WriteFilterInterface> */
    private array $writeFilters = [];

    public function __construct(
        RedisConnectionConfig $connectionConfig,
        SessionSerializerInterface $serializer,
        SessionIdGeneratorInterface $idGenerator,
        int $maxLifetime,
        LoggerInterface $logger
    ) {
        $this->connectionConfig = $connectionConfig;
        $this->serializer = $serializer;
        $this->idGenerator = $idGenerator;
        $this->maxLifetime = $maxLifetime;
        $this->logger = $logger;

        $this->validate();
    }

    public function getConnectionConfig(): RedisConnectionConfig
    {
        return $this->connectionConfig;
    }

    public function getSerializer(): SessionSerializerInterface
    {
        return $this->serializer;
    }

    public function getIdGenerator(): SessionIdGeneratorInterface
    {
        return $this->idGenerator;
    }

    public function getMaxLifetime(): int
    {
        return $this->maxLifetime;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return array<ReadHookInterface>
     */
    public function getReadHooks(): array
    {
        return $this->readHooks;
    }

    /**
     * @return array<WriteHookInterface>
     */
    public function getWriteHooks(): array
    {
        return $this->writeHooks;
    }

    /**
     * @return array<WriteFilterInterface>
     */
    public function getWriteFilters(): array
    {
        return $this->writeFilters;
    }

    public function addReadHook(ReadHookInterface $hook): self
    {
        $this->readHooks[] = $hook;
        return $this;
    }

    public function addWriteHook(WriteHookInterface $hook): self
    {
        $this->writeHooks[] = $hook;
        return $this;
    }

    public function addWriteFilter(WriteFilterInterface $filter): self
    {
        $this->writeFilters[] = $filter;
        return $this;
    }

    public function setConnectionConfig(RedisConnectionConfig $config): self
    {
        $this->connectionConfig = $config;
        return $this;
    }

    public function setSerializer(SessionSerializerInterface $serializer): self
    {
        $this->serializer = $serializer;
        return $this;
    }

    public function setIdGenerator(SessionIdGeneratorInterface $generator): self
    {
        $this->idGenerator = $generator;
        return $this;
    }

    public function setMaxLifetime(int $maxLifetime): self
    {
        if ($maxLifetime <= 0) {
            throw new ConfigurationException('maxLifetime must be greater than 0');
        }
        $this->maxLifetime = $maxLifetime;
        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    private function validate(): void
    {
        if ($this->maxLifetime <= 0) {
            throw new ConfigurationException('maxLifetime must be greater than 0');
        }
    }
}
