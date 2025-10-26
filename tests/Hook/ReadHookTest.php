<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

class ReadHookTest extends TestCase
{
    private RedisConnection $connection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $logger = new NullLogger();

        $redis = new \Redis();
        $config = new RedisConnectionConfig('localhost', 6379);
        $this->connection = new RedisConnection($redis, $config, $logger);

        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $this->handler = new RedisSessionHandler($this->connection, $options);
    }

    public function testBeforeReadHookIsCalled(): void
    {
        $testState = new \stdClass();
        $testState->called = false;
        $testState->sessionId = '';

        $hook = new class ($testState) implements ReadHookInterface {
            private \stdClass $testState;

            public function __construct(\stdClass $testState)
            {
                $this->testState = $testState;
            }

            public function beforeRead(string $sessionId): void
            {
                $this->testState->called = true;
                $this->testState->sessionId = $sessionId;
            }

            public function afterRead(string $sessionId, string $data): string
            {
                return $data;
            }

            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return null;
            }
        };

        $this->handler->addReadHook($hook);

        $this->connection->connect();
        $this->handler->read('test-session-id');

        self::assertTrue($testState->called);
        self::assertSame('test-session-id', $testState->sessionId);
    }

    public function testAfterReadHookCanModifyData(): void
    {
        $hook = new class () implements ReadHookInterface {
            public function beforeRead(string $sessionId): void
            {
            }

            public function afterRead(string $sessionId, string $data): string
            {
                return 'modified:' . $data;
            }

            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return null;
            }
        };

        $this->handler->addReadHook($hook);

        $this->connection->connect();
        $testData = 'test-data';
        $this->connection->set('test-session-id', $testData, 3600);

        $result = $this->handler->read('test-session-id');

        self::assertSame('modified:' . $testData, $result);

        $this->connection->delete('test-session-id');
    }

    public function testMultipleReadHooksAreCalledInOrder(): void
    {
        $testState = new \stdClass();
        $testState->order = [];

        $hook1 = new class ($testState) implements ReadHookInterface {
            private \stdClass $testState;

            public function __construct(\stdClass $testState)
            {
                $this->testState = $testState;
            }

            public function beforeRead(string $sessionId): void
            {
                /** @var array<string> $order */
                $order = $this->testState->order;
                $order[] = 'hook1-before';
                $this->testState->order = $order;
            }

            public function afterRead(string $sessionId, string $data): string
            {
                /** @var array<string> $order */
                $order = $this->testState->order;
                $order[] = 'hook1-after';
                $this->testState->order = $order;
                return $data . ':hook1';
            }

            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return null;
            }
        };

        $hook2 = new class ($testState) implements ReadHookInterface {
            private \stdClass $testState;

            public function __construct(\stdClass $testState)
            {
                $this->testState = $testState;
            }

            public function beforeRead(string $sessionId): void
            {
                /** @var array<string> $order */
                $order = $this->testState->order;
                $order[] = 'hook2-before';
                $this->testState->order = $order;
            }

            public function afterRead(string $sessionId, string $data): string
            {
                /** @var array<string> $order */
                $order = $this->testState->order;
                $order[] = 'hook2-after';
                $this->testState->order = $order;
                return $data . ':hook2';
            }

            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return null;
            }
        };

        $this->handler->addReadHook($hook1);
        $this->handler->addReadHook($hook2);

        $this->connection->connect();
        $testData = 'data';
        $this->connection->set('test-session-id', $testData, 3600);

        $result = $this->handler->read('test-session-id');

        self::assertSame(['hook1-before', 'hook2-before', 'hook1-after', 'hook2-after'], $testState->order);
        self::assertSame($testData . ':hook1:hook2', $result);

        $this->connection->delete('test-session-id');
    }

    public function testOnReadErrorHookIsCalledOnException(): void
    {
        $testState = new \stdClass();
        $testState->errorCalled = false;
        $testState->caughtException = null;

        $hook = new class ($testState) implements ReadHookInterface {
            private \stdClass $testState;

            public function __construct(\stdClass $testState)
            {
                $this->testState = $testState;
            }

            public function beforeRead(string $sessionId): void
            {
            }

            public function afterRead(string $sessionId, string $data): string
            {
                throw new \RuntimeException('Test error');
            }

            // PHPStan: onReadError() は常に非 null を返すが、ReadHookInterface 実装としては ?string が必要（未使用の戻り値型警告）
            /** @phpstan-ignore-next-line return.unusedType */
            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                $this->testState->errorCalled = true;
                $this->testState->caughtException = $e;
                return 'fallback-data';
            }
        };

        $this->handler->addReadHook($hook);

        $this->connection->connect();
        $this->connection->set('test-session-id', 'test-data', 3600);

        $result = $this->handler->read('test-session-id');

        self::assertTrue($testState->errorCalled);
        self::assertInstanceOf(\RuntimeException::class, $testState->caughtException);
        self::assertSame('fallback-data', $result);

        $this->connection->delete('test-session-id');
    }

    public function testOnReadErrorReturnsEmptyStringWhenNoFallback(): void
    {
        $hook = new class () implements ReadHookInterface {
            public function beforeRead(string $sessionId): void
            {
            }

            public function afterRead(string $sessionId, string $data): string
            {
                throw new \RuntimeException('Test error');
            }

            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return null;
            }
        };

        $this->handler->addReadHook($hook);

        $this->connection->connect();
        $this->connection->set('test-session-id', 'test-data', 3600);

        $result = $this->handler->read('test-session-id');

        self::assertSame('', $result);

        $this->connection->delete('test-session-id');
    }

    public function testFirstHookWithFallbackDataIsUsed(): void
    {
        $hook1 = new class () implements ReadHookInterface {
            public function beforeRead(string $sessionId): void
            {
            }

            public function afterRead(string $sessionId, string $data): string
            {
                throw new \RuntimeException('Test error');
            }

            // PHPStan: onReadError() は常に非 null を返すが、ReadHookInterface 実装としては ?string が必要（未使用の戻り値型警告）
            /** @phpstan-ignore-next-line return.unusedType */
            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return 'fallback-from-hook1';
            }
        };

        $hook2 = new class () implements ReadHookInterface {
            public function beforeRead(string $sessionId): void
            {
            }

            public function afterRead(string $sessionId, string $data): string
            {
                return $data;
            }

            // PHPStan: onReadError() は常に非 null を返すが、ReadHookInterface 実装としては ?string が必要（未使用の戻り値型警告）
            /** @phpstan-ignore-next-line return.unusedType */
            public function onReadError(string $sessionId, \Throwable $e): ?string
            {
                return 'fallback-from-hook2';
            }
        };

        $this->handler->addReadHook($hook1);
        $this->handler->addReadHook($hook2);

        $this->connection->connect();
        $this->connection->set('test-session-id', 'test-data', 3600);

        $result = $this->handler->read('test-session-id');

        self::assertSame('fallback-from-hook1', $result);

        $this->connection->delete('test-session-id');
    }
}
