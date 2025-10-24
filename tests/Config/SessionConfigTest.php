<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Config;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConfigurationException;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class SessionConfigTest extends TestCase
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

    public function testConstructor(): void
    {
        $connectionConfig = new RedisConnectionConfig();
        $idGenerator = new DefaultSessionIdGenerator();
        $logger = new NullLogger();
        $maxLifetime = 3600;

        $config = new SessionConfig($connectionConfig, $idGenerator, $maxLifetime, $logger);

        self::assertSame($connectionConfig, $config->getConnectionConfig());
        self::assertSame($idGenerator, $config->getIdGenerator());
        self::assertSame($maxLifetime, $config->getMaxLifetime());
        self::assertSame($logger, $config->getLogger());
        self::assertEmpty($config->getReadHooks());
        self::assertEmpty($config->getWriteHooks());
        self::assertEmpty($config->getWriteFilters());
    }


    public function testSetConnectionConfig(): void
    {
        $config = $this->createDefaultConfig();
        $newConnectionConfig = new RedisConnectionConfig('redis.example.com', 6380);

        $result = $config->setConnectionConfig($newConnectionConfig);

        self::assertSame($config, $result);
        self::assertSame($newConnectionConfig, $config->getConnectionConfig());
    }

    public function testSetIdGenerator(): void
    {
        $config = $this->createDefaultConfig();
        $newGenerator = new DefaultSessionIdGenerator();

        $result = $config->setIdGenerator($newGenerator);

        self::assertSame($config, $result);
        self::assertSame($newGenerator, $config->getIdGenerator());
    }

    public function testSetMaxLifetime(): void
    {
        $config = $this->createDefaultConfig();

        $result = $config->setMaxLifetime(7200);

        self::assertSame($config, $result);
        self::assertSame(7200, $config->getMaxLifetime());
    }

    public function testSetMaxLifetimeThrowsExceptionForZero(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('maxLifetime must be greater than 0');

        $config = $this->createDefaultConfig();
        $config->setMaxLifetime(0);
    }

    public function testSetMaxLifetimeThrowsExceptionForNegative(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('maxLifetime must be greater than 0');

        $config = $this->createDefaultConfig();
        $config->setMaxLifetime(-1);
    }

    public function testConstructorThrowsExceptionForInvalidMaxLifetime(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('maxLifetime must be greater than 0');

        new SessionConfig(
            new RedisConnectionConfig(),
            new DefaultSessionIdGenerator(),
            0,
            new NullLogger()
        );
    }

    public function testSetLogger(): void
    {
        $config = $this->createDefaultConfig();
        $logger = new NullLogger();

        $result = $config->setLogger($logger);

        self::assertSame($config, $result);
        self::assertSame($logger, $config->getLogger());
    }

    public function testAddReadHook(): void
    {
        $config = $this->createDefaultConfig();
        $hook = $this->createMock(ReadHookInterface::class);

        $result = $config->addReadHook($hook);

        self::assertSame($config, $result);
        self::assertCount(1, $config->getReadHooks());
        self::assertSame($hook, $config->getReadHooks()[0]);
    }

    public function testAddMultipleReadHooks(): void
    {
        $config = $this->createDefaultConfig();
        $hook1 = $this->createMock(ReadHookInterface::class);
        $hook2 = $this->createMock(ReadHookInterface::class);

        $config->addReadHook($hook1);
        $config->addReadHook($hook2);

        $hooks = $config->getReadHooks();
        self::assertCount(2, $hooks);
        self::assertSame($hook1, $hooks[0]);
        self::assertSame($hook2, $hooks[1]);
    }

    public function testAddWriteHook(): void
    {
        $config = $this->createDefaultConfig();
        $hook = $this->createMock(WriteHookInterface::class);

        $result = $config->addWriteHook($hook);

        self::assertSame($config, $result);
        self::assertCount(1, $config->getWriteHooks());
        self::assertSame($hook, $config->getWriteHooks()[0]);
    }

    public function testAddMultipleWriteHooks(): void
    {
        $config = $this->createDefaultConfig();
        $hook1 = $this->createMock(WriteHookInterface::class);
        $hook2 = $this->createMock(WriteHookInterface::class);

        $config->addWriteHook($hook1);
        $config->addWriteHook($hook2);

        $hooks = $config->getWriteHooks();
        self::assertCount(2, $hooks);
        self::assertSame($hook1, $hooks[0]);
        self::assertSame($hook2, $hooks[1]);
    }

    public function testAddWriteFilter(): void
    {
        $config = $this->createDefaultConfig();
        $filter = $this->createMock(WriteFilterInterface::class);

        $result = $config->addWriteFilter($filter);

        self::assertSame($config, $result);
        self::assertCount(1, $config->getWriteFilters());
        self::assertSame($filter, $config->getWriteFilters()[0]);
    }

    public function testAddMultipleWriteFilters(): void
    {
        $config = $this->createDefaultConfig();
        $filter1 = $this->createMock(WriteFilterInterface::class);
        $filter2 = $this->createMock(WriteFilterInterface::class);

        $config->addWriteFilter($filter1);
        $config->addWriteFilter($filter2);

        $filters = $config->getWriteFilters();
        self::assertCount(2, $filters);
        self::assertSame($filter1, $filters[0]);
        self::assertSame($filter2, $filters[1]);
    }

    public function testFluentInterface(): void
    {
        $config = $this->createDefaultConfig();
        $connectionConfig = new RedisConnectionConfig();
        $idGenerator = new DefaultSessionIdGenerator();
        $logger = new NullLogger();
        $readHook = $this->createMock(ReadHookInterface::class);
        $writeHook = $this->createMock(WriteHookInterface::class);
        $writeFilter = $this->createMock(WriteFilterInterface::class);

        $result = $config
            ->setConnectionConfig($connectionConfig)
            ->setIdGenerator($idGenerator)
            ->setMaxLifetime(3600)
            ->setLogger($logger)
            ->addReadHook($readHook)
            ->addWriteHook($writeHook)
            ->addWriteFilter($writeFilter);

        self::assertSame($config, $result);
        self::assertSame($connectionConfig, $config->getConnectionConfig());
        self::assertSame($idGenerator, $config->getIdGenerator());
        self::assertSame(3600, $config->getMaxLifetime());
        self::assertSame($logger, $config->getLogger());
        self::assertCount(1, $config->getReadHooks());
        self::assertCount(1, $config->getWriteHooks());
        self::assertCount(1, $config->getWriteFilters());
    }
}
