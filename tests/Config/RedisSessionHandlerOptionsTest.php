<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Config;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class RedisSessionHandlerOptionsTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $options = new RedisSessionHandlerOptions();

        self::assertInstanceOf(DefaultSessionIdGenerator::class, $options->getIdGenerator());
        self::assertInstanceOf(NullLogger::class, $options->getLogger());
        self::assertGreaterThan(0, $options->getMaxLifetime());
    }

    public function testConstructorWithCustomValues(): void
    {
        $generator = $this->createMock(SessionIdGeneratorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $options = new RedisSessionHandlerOptions($generator, 7200, $logger);

        self::assertSame($generator, $options->getIdGenerator());
        self::assertSame(7200, $options->getMaxLifetime());
        self::assertSame($logger, $options->getLogger());
    }

    public function testConstructorUsesIniValueWhenMaxLifetimeIsNull(): void
    {
        // 現在のini設定値を取得
        $currentIniValue = ini_get('session.gc_maxlifetime');
        if ($currentIniValue === false) {
            self::markTestSkipped('Cannot get session.gc_maxlifetime');
        }

        $expectedValue = (int)$currentIniValue;
        $options = new RedisSessionHandlerOptions();

        // maxLifetimeがnullの場合、ini設定値が使用される
        self::assertSame($expectedValue, $options->getMaxLifetime());
    }

    public function testConstructorUses1440WhenIniGetFailsAndMaxLifetimeIsNull(): void
    {
        // ini_getが失敗することをシミュレートするのは困難なため、
        // ini_getが有効な値を返す場合のテストのみ実施
        // 実際のコードでは ini_get が false を返した場合に 1440 が使用される
        self::markTestSkipped('Cannot reliably test ini_get failure scenario in unit tests');
    }

    public function testConstructorThrowsExceptionWhenMaxLifetimeIsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max lifetime must be positive');

        new RedisSessionHandlerOptions(null, 0);
    }

    public function testConstructorThrowsExceptionWhenMaxLifetimeIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max lifetime must be positive');

        new RedisSessionHandlerOptions(null, -1);
    }

    public function testConstructorAcceptsPositiveMaxLifetime(): void
    {
        $options = new RedisSessionHandlerOptions(null, 1);
        self::assertSame(1, $options->getMaxLifetime());

        $options = new RedisSessionHandlerOptions(null, 86400);
        self::assertSame(86400, $options->getMaxLifetime());
    }

    public function testGetIdGeneratorReturnsSetGenerator(): void
    {
        $generator = $this->createMock(SessionIdGeneratorInterface::class);
        $options = new RedisSessionHandlerOptions($generator);

        self::assertSame($generator, $options->getIdGenerator());
    }

    public function testGetLoggerReturnsSetLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $options = new RedisSessionHandlerOptions(null, null, $logger);

        self::assertSame($logger, $options->getLogger());
    }

    public function testConstructorUsesDefaultsWhenAllParametersAreNull(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, null);

        self::assertInstanceOf(DefaultSessionIdGenerator::class, $options->getIdGenerator());
        self::assertInstanceOf(NullLogger::class, $options->getLogger());
        self::assertGreaterThan(0, $options->getMaxLifetime());
    }
}
