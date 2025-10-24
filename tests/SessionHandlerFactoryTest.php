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
    public function testConstructorWithoutConfig(): void
    {
        $factory = new SessionHandlerFactory();
        $config = $factory->getConfig();

        $this->assertInstanceOf(SessionConfig::class, $config);
    }

    public function testConstructorWithConfig(): void
    {
        $sessionConfig = new SessionConfig();
        $factory = new SessionHandlerFactory($sessionConfig);

        $this->assertSame($sessionConfig, $factory->getConfig());
    }

    public function testCreateStaticMethod(): void
    {
        $factory = SessionHandlerFactory::create();

        $this->assertInstanceOf(SessionHandlerFactory::class, $factory);
        $this->assertInstanceOf(SessionConfig::class, $factory->getConfig());
    }

    public function testCreateDefaultStaticMethod(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $this->assertInstanceOf(SessionHandlerFactory::class, $factory);
        $this->assertInstanceOf(SessionConfig::class, $factory->getConfig());
    }

    public function testWithConnectionConfig(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $connectionConfig = new RedisConnectionConfig('redis.example.com', 6380);

        $result = $factory->withConnectionConfig($connectionConfig);

        $this->assertSame($factory, $result);
        $this->assertSame($connectionConfig, $factory->getConfig()->getConnectionConfig());
    }

    public function testWithHost(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withHost('redis.example.com');

        $this->assertSame($factory, $result);
        $this->assertSame('redis.example.com', $factory->getConfig()->getConnectionConfig()->getHost());
    }

    public function testWithPort(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withPort(6380);

        $this->assertSame($factory, $result);
        $this->assertSame(6380, $factory->getConfig()->getConnectionConfig()->getPort());
    }

    public function testWithPassword(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withPassword('secret');

        $this->assertSame($factory, $result);
        $this->assertSame('secret', $factory->getConfig()->getConnectionConfig()->getPassword());
    }

    public function testWithDatabase(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withDatabase(2);

        $this->assertSame($factory, $result);
        $this->assertSame(2, $factory->getConfig()->getConnectionConfig()->getDatabase());
    }

    public function testWithPrefix(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withPrefix('mysession:');

        $this->assertSame($factory, $result);
        $this->assertSame('mysession:', $factory->getConfig()->getConnectionConfig()->getPrefix());
    }

    public function testWithPersistent(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withPersistent(true);

        $this->assertSame($factory, $result);
        $this->assertTrue($factory->getConfig()->getConnectionConfig()->isPersistent());
    }

    public function testWithTimeout(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withTimeout(5.0);

        $this->assertSame($factory, $result);
        $this->assertSame(5.0, $factory->getConfig()->getConnectionConfig()->getTimeout());
    }

    public function testWithReadTimeout(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withReadTimeout(5.0);

        $this->assertSame($factory, $result);
        $this->assertSame(5.0, $factory->getConfig()->getConnectionConfig()->getReadTimeout());
    }

    public function testWithMaxRetries(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withMaxRetries(5);

        $this->assertSame($factory, $result);
        $this->assertSame(5, $factory->getConfig()->getConnectionConfig()->getMaxRetries());
    }

    public function testWithRetryInterval(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withRetryInterval(200);

        $this->assertSame($factory, $result);
        $this->assertSame(200, $factory->getConfig()->getConnectionConfig()->getRetryInterval());
    }

    public function testWithIdGenerator(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $generator = new DefaultSessionIdGenerator();

        $result = $factory->withIdGenerator($generator);

        $this->assertSame($factory, $result);
        $this->assertSame($generator, $factory->getConfig()->getIdGenerator());
    }

    public function testWithMaxLifetime(): void
    {
        $factory = SessionHandlerFactory::createDefault();

        $result = $factory->withMaxLifetime(7200);

        $this->assertSame($factory, $result);
        $this->assertSame(7200, $factory->getConfig()->getMaxLifetime());
    }

    public function testWithLogger(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $logger = new NullLogger();

        $result = $factory->withLogger($logger);

        $this->assertSame($factory, $result);
        $this->assertSame($logger, $factory->getConfig()->getLogger());
    }

    public function testWithReadHook(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $hook = $this->createMock(ReadHookInterface::class);

        $result = $factory->withReadHook($hook);

        $this->assertSame($factory, $result);
        $this->assertCount(1, $factory->getConfig()->getReadHooks());
        $this->assertSame($hook, $factory->getConfig()->getReadHooks()[0]);
    }

    public function testWithWriteHook(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $hook = $this->createMock(WriteHookInterface::class);

        $result = $factory->withWriteHook($hook);

        $this->assertSame($factory, $result);
        $this->assertCount(1, $factory->getConfig()->getWriteHooks());
        $this->assertSame($hook, $factory->getConfig()->getWriteHooks()[0]);
    }

    public function testWithWriteFilter(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $filter = $this->createMock(WriteFilterInterface::class);

        $result = $factory->withWriteFilter($filter);

        $this->assertSame($factory, $result);
        $this->assertCount(1, $factory->getConfig()->getWriteFilters());
        $this->assertSame($filter, $factory->getConfig()->getWriteFilters()[0]);
    }

    public function testFluentInterface(): void
    {
        $logger = new NullLogger();
        $generator = new DefaultSessionIdGenerator();
        $readHook = $this->createMock(ReadHookInterface::class);
        $writeHook = $this->createMock(WriteHookInterface::class);
        $writeFilter = $this->createMock(WriteFilterInterface::class);

        $factory = SessionHandlerFactory::createDefault()
            ->withHost('redis.example.com')
            ->withPort(6380)
            ->withPassword('secret')
            ->withDatabase(2)
            ->withPrefix('mysession:')
            ->withPersistent(true)
            ->withTimeout(5.0)
            ->withReadTimeout(5.0)
            ->withMaxRetries(5)
            ->withRetryInterval(200)
            ->withIdGenerator($generator)
            ->withMaxLifetime(7200)
            ->withLogger($logger)
            ->withReadHook($readHook)
            ->withWriteHook($writeHook)
            ->withWriteFilter($writeFilter);

        $config = $factory->getConfig();
        $this->assertSame('redis.example.com', $config->getConnectionConfig()->getHost());
        $this->assertSame(6380, $config->getConnectionConfig()->getPort());
        $this->assertSame('secret', $config->getConnectionConfig()->getPassword());
        $this->assertSame(2, $config->getConnectionConfig()->getDatabase());
        $this->assertSame('mysession:', $config->getConnectionConfig()->getPrefix());
        $this->assertTrue($config->getConnectionConfig()->isPersistent());
        $this->assertSame(5.0, $config->getConnectionConfig()->getTimeout());
        $this->assertSame(5.0, $config->getConnectionConfig()->getReadTimeout());
        $this->assertSame(5, $config->getConnectionConfig()->getMaxRetries());
        $this->assertSame(200, $config->getConnectionConfig()->getRetryInterval());
        $this->assertSame($generator, $config->getIdGenerator());
        $this->assertSame(7200, $config->getMaxLifetime());
        $this->assertSame($logger, $config->getLogger());
        $this->assertCount(1, $config->getReadHooks());
        $this->assertCount(1, $config->getWriteHooks());
        $this->assertCount(1, $config->getWriteFilters());
    }

    public function testBuildCreatesRedisSessionHandler(): void
    {
        $factory = SessionHandlerFactory::createDefault();
        $handler = $factory->build();

        $this->assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testBuildWithCustomConfiguration(): void
    {
        $logger = new NullLogger();
        $generator = new DefaultSessionIdGenerator();

        $factory = SessionHandlerFactory::createDefault()
            ->withHost('localhost')
            ->withPort(6379)
            ->withMaxLifetime(3600)
            ->withIdGenerator($generator)
            ->withLogger($logger);

        $handler = $factory->build();

        $this->assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testBuildWithHooks(): void
    {
        $readHook = $this->createMock(ReadHookInterface::class);
        $writeHook = $this->createMock(WriteHookInterface::class);
        $writeFilter = $this->createMock(WriteFilterInterface::class);

        $factory = SessionHandlerFactory::createDefault()
            ->withReadHook($readHook)
            ->withWriteHook($writeHook)
            ->withWriteFilter($writeFilter);

        $handler = $factory->build();

        $this->assertInstanceOf(RedisSessionHandler::class, $handler);
    }
}
