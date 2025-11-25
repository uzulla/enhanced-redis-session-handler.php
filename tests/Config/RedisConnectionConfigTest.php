<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;

class RedisConnectionConfigTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $config = new RedisConnectionConfig();

        self::assertSame('localhost', $config->getHost());
        self::assertSame(6379, $config->getPort());
        self::assertSame(2.5, $config->getTimeout());
        self::assertNull($config->getPassword());
        self::assertSame(0, $config->getDatabase());
        self::assertSame('session:', $config->getPrefix());
        self::assertFalse($config->isPersistent());
        self::assertSame(100, $config->getRetryInterval());
        self::assertSame(2.5, $config->getReadTimeout());
        self::assertSame(3, $config->getMaxRetries());
    }

    public function testConstructorWithCustomValues(): void
    {
        $config = new RedisConnectionConfig(
            'redis.example.com',
            6380,
            5.0,
            'secret',
            1,
            'mysession:',
            true,
            200,
            3.0,
            5
        );

        self::assertSame('redis.example.com', $config->getHost());
        self::assertSame(6380, $config->getPort());
        self::assertSame(5.0, $config->getTimeout());
        self::assertSame('secret', $config->getPassword());
        self::assertSame(1, $config->getDatabase());
        self::assertSame('mysession:', $config->getPrefix());
        self::assertTrue($config->isPersistent());
        self::assertSame(200, $config->getRetryInterval());
        self::assertSame(3.0, $config->getReadTimeout());
        self::assertSame(5, $config->getMaxRetries());
    }

    public function testConstructorThrowsExceptionWhenHostIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Host cannot be empty');

        new RedisConnectionConfig('');
    }

    /**
     * @dataProvider invalidPortProvider
     */
    public function testConstructorThrowsExceptionWhenPortIsInvalid(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Port must be between 1 and 65535');

        new RedisConnectionConfig('localhost', $port);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidPortProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'too large' => [65536],
        ];
    }

    public function testConstructorThrowsExceptionWhenTimeoutIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be non-negative');

        new RedisConnectionConfig('localhost', 6379, -1.0);
    }

    public function testConstructorThrowsExceptionWhenReadTimeoutIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Read timeout must be non-negative');

        new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'session:', false, 100, -1.0);
    }

    public function testConstructorThrowsExceptionWhenDatabaseIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database must be non-negative');

        new RedisConnectionConfig('localhost', 6379, 2.5, null, -1);
    }

    public function testConstructorThrowsExceptionWhenMaxRetriesIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max retries must be non-negative');

        new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'session:', false, 100, 2.5, -1);
    }

    public function testConstructorThrowsExceptionWhenRetryIntervalIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry interval must be non-negative');

        new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'session:', false, -1);
    }

    /**
     * @dataProvider zeroValueProvider
     * @param mixed $expected
     */
    public function testConstructorAcceptsZeroValues(string $parameter, $expected): void
    {
        switch ($parameter) {
            case 'timeout':
                $config = new RedisConnectionConfig('localhost', 6379, 0.0);
                break;
            case 'readTimeout':
                $config = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'session:', false, 100, 0.0);
                break;
            case 'maxRetries':
                $config = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'session:', false, 100, 2.5, 0);
                break;
            case 'retryInterval':
                $config = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'session:', false, 0);
                break;
            default:
                throw new \InvalidArgumentException("Unknown parameter: {$parameter}");
        }

        switch ($parameter) {
            case 'timeout':
                $actual = $config->getTimeout();
                break;
            case 'readTimeout':
                $actual = $config->getReadTimeout();
                break;
            case 'maxRetries':
                $actual = $config->getMaxRetries();
                break;
            case 'retryInterval':
                $actual = $config->getRetryInterval();
                break;
            default:
                throw new \InvalidArgumentException("Unknown parameter: {$parameter}");
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function zeroValueProvider(): array
    {
        return [
            'timeout' => ['timeout', 0.0],
            'readTimeout' => ['readTimeout', 0.0],
            'maxRetries' => ['maxRetries', 0],
            'retryInterval' => ['retryInterval', 0],
        ];
    }

    public function testConstructorAcceptsPortBoundaryValues(): void
    {
        $configMin = new RedisConnectionConfig('localhost', 1);
        self::assertSame(1, $configMin->getPort());

        $configMax = new RedisConnectionConfig('localhost', 65535);
        self::assertSame(65535, $configMax->getPort());
    }
}
