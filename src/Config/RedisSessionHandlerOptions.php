<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class RedisSessionHandlerOptions
{
    private SessionIdGeneratorInterface $idGenerator;
    private int $maxLifetime;
    private LoggerInterface $logger;

    public function __construct(
        ?SessionIdGeneratorInterface $idGenerator = null,
        ?int $maxLifetime = null,
        ?LoggerInterface $logger = null
    ) {
        $this->idGenerator = $idGenerator ?? new DefaultSessionIdGenerator();
        $this->maxLifetime = $maxLifetime ?? (int)ini_get('session.gc_maxlifetime');
        $this->logger = $logger ?? new NullLogger();
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
}
