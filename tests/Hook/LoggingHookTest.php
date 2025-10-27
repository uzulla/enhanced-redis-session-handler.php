<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;

class LoggingHookTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testConstructorWithDefaultValues(): void
    {
        $hook = new LoggingHook($this->logger);

        self::assertInstanceOf(LoggingHook::class, $hook);
    }

    public function testConstructorWithCustomValues(): void
    {
        $hook = new LoggingHook(
            $this->logger,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::CRITICAL,
            true
        );

        self::assertInstanceOf(LoggingHook::class, $hook);
    }

    public function testConstructorThrowsExceptionWhenBeforeWriteLevelIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log level for beforeWrite');

        new LoggingHook($this->logger, 'invalid_level');
    }

    public function testConstructorThrowsExceptionWhenAfterWriteLevelIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log level for afterWrite');

        new LoggingHook($this->logger, LogLevel::DEBUG, 'invalid_level');
    }

    public function testConstructorThrowsExceptionWhenErrorLevelIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log level for error');

        new LoggingHook($this->logger, LogLevel::DEBUG, LogLevel::DEBUG, 'invalid_level');
    }

    /**
     * @dataProvider validLogLevelsProvider
     */
    public function testConstructorAcceptsAllValidPsr3LogLevels(string $level): void
    {
        $hook = new LoggingHook($this->logger, $level, $level, $level);

        self::assertInstanceOf(LoggingHook::class, $hook);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validLogLevelsProvider(): array
    {
        return [
            'debug' => [LogLevel::DEBUG],
            'info' => [LogLevel::INFO],
            'notice' => [LogLevel::NOTICE],
            'warning' => [LogLevel::WARNING],
            'error' => [LogLevel::ERROR],
            'critical' => [LogLevel::CRITICAL],
            'alert' => [LogLevel::ALERT],
            'emergency' => [LogLevel::EMERGENCY],
        ];
    }

    public function testBeforeWriteLogsWithoutData(): void
    {
        $this->logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                'Session write starting',
                self::callback(function (array $context) {
                    return $context['session_id'] === '...sion'
                        && !isset($context['data'])
                        && isset($context['data_keys'])
                        && isset($context['data_size']);
                })
            );

        $hook = new LoggingHook($this->logger);
        $data = ['key' => 'value'];
        $result = $hook->beforeWrite('test_session', $data);

        self::assertSame($data, $result);
    }

    public function testBeforeWriteLogsWithData(): void
    {
        $this->logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                'Session write starting',
                self::callback(function (array $context) {
                    return $context['session_id'] === '...sion'
                        && isset($context['data'])
                        && $context['data'] === ['key' => 'value'];
                })
            );

        $hook = new LoggingHook($this->logger, LogLevel::DEBUG, LogLevel::DEBUG, LogLevel::ERROR, true);
        $data = ['key' => 'value'];
        $hook->beforeWrite('test_session', $data);
    }

    public function testAfterWriteLogsSuccess(): void
    {
        $this->logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                'Session write successful',
                [
                    'session_id' => '...sion',
                    'success' => true,
                ]
            );

        $hook = new LoggingHook($this->logger);
        $hook->afterWrite('test_session', true);
    }

    public function testAfterWriteLogsFailure(): void
    {
        $this->logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                'Session write failed',
                [
                    'session_id' => '...sion',
                    'success' => false,
                ]
            );

        $hook = new LoggingHook($this->logger);
        $hook->afterWrite('test_session', false);
    }

    public function testOnWriteErrorLogsException(): void
    {
        $exception = new RuntimeException('Test error', 123);

        $this->logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::ERROR,
                'Session write error occurred',
                self::callback(function (array $context) {
                    return $context['session_id'] === '...sion'
                        && $context['exception_class'] === RuntimeException::class
                        && $context['exception_message'] === 'Test error'
                        && $context['exception_code'] === 123
                        && isset($context['exception_file'])
                        && isset($context['exception_line']);
                })
            );

        $hook = new LoggingHook($this->logger);
        $hook->onWriteError('test_session', $exception);
    }

    public function testCustomLogLevelsAreUsed(): void
    {
        $this->logger->expects(self::exactly(3))
            ->method('log')
            ->willReturnCallback(function (string $level, string $message) {
                /** @var int $callCount */
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    self::assertSame(LogLevel::INFO, $level);
                    self::assertSame('Session write starting', $message);
                } elseif ($callCount === 2) {
                    self::assertSame(LogLevel::NOTICE, $level);
                    self::assertSame('Session write successful', $message);
                } elseif ($callCount === 3) {
                    self::assertSame(LogLevel::CRITICAL, $level);
                    self::assertSame('Session write error occurred', $message);
                }
            });

        $hook = new LoggingHook(
            $this->logger,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::CRITICAL
        );

        $hook->beforeWrite('test_session', []);
        $hook->afterWrite('test_session', true);
        $hook->onWriteError('test_session', new \Exception('Test'));
    }
}
