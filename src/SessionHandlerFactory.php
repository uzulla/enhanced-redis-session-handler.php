<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;

class SessionHandlerFactory
{
    private SessionConfig $config;

    public function __construct(SessionConfig $config)
    {
        $this->config = $config;
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
