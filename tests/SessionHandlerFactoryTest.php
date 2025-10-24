<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;

class SessionHandlerFactoryTest extends TestCase
{
    private function createDefaultConfig(): SessionConfig
    {
        return new SessionConfig(
            new RedisConnectionConfig(),
            new DefaultSessionIdGenerator(),
            3600,
            new NullLogger()
        );
    }

    public function testConstructorRequiresConfig(): void
    {
        $config = $this->createDefaultConfig();
        $factory = new SessionHandlerFactory($config);

        self::assertSame($config, $factory->getConfig());
    }

    public function testGetConfig(): void
    {
        $config = $this->createDefaultConfig();
        $factory = new SessionHandlerFactory($config);

        self::assertSame($config, $factory->getConfig());
    }

    public function testBuildCreatesRedisSessionHandler(): void
    {
        $config = $this->createDefaultConfig();
        $factory = new SessionHandlerFactory($config);
        $handler = $factory->build();

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testBuildWithCustomConfiguration(): void
    {
        $connectionConfig = new RedisConnectionConfig(
            'redis.example.com',
            6380,
            2.5,
            'secret',
            1,
            'mysession:',
            true,
            100,
            2.5,
            3
        );
        $idGenerator = new DefaultSessionIdGenerator();
        $logger = new NullLogger();

        $config = new SessionConfig($connectionConfig, $idGenerator, 7200, $logger);
        $factory = new SessionHandlerFactory($config);
        $handler = $factory->build();

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
        self::assertSame($config, $factory->getConfig());
    }

    public function testBuildWithHooks(): void
    {
        $config = $this->createDefaultConfig();
        $readHook = $this->createMock(ReadHookInterface::class);
        $writeHook = $this->createMock(WriteHookInterface::class);
        $writeFilter = $this->createMock(WriteFilterInterface::class);

        $config->addReadHook($readHook);
        $config->addWriteHook($writeHook);
        $config->addWriteFilter($writeFilter);

        $factory = new SessionHandlerFactory($config);
        $handler = $factory->build();

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }
}
