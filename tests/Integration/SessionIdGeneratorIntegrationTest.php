<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SecureSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;
use Redis;

class SessionIdGeneratorIntegrationTest extends TestCase
{
    private RedisConnection $connection;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for integration tests');
        }

        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        self::assertNotFalse($redisHost, 'SESSION_REDIS_HOST environment variable must be set');
        self::assertNotFalse($redisPort, 'SESSION_REDIS_PORT environment variable must be set');

        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $config = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'test:session:'
        );

        $redis = new Redis();
        $this->connection = new RedisConnection($redis, $config, $logger);

        $this->connection->connect();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $keys = $this->connection->keys('*');
            foreach ($keys as $key) {
                $this->connection->delete($key);
            }
            $this->connection->disconnect();
        }
    }

    public function testDefaultSessionIdGeneratorIntegration(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $generator = new DefaultSessionIdGenerator();
        $options = new RedisSessionHandlerOptions($generator, null, $logger);
        $handler = new RedisSessionHandler($this->connection, $options);

        $handler->open('/tmp', 'PHPSESSID');
        $sessionId = $handler->create_sid();

        self::assertSame(32, strlen($sessionId));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $sessionId);
    }

    public function testSecureSessionIdGeneratorIntegration(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $generator = new SecureSessionIdGenerator(64);
        $options = new RedisSessionHandlerOptions($generator, null, $logger);
        $handler = new RedisSessionHandler($this->connection, $options);

        $handler->open('/tmp', 'PHPSESSID');
        $sessionId = $handler->create_sid();

        self::assertSame(64, strlen($sessionId));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $sessionId);
    }

    public function testCustomSessionIdGeneratorIntegration(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $customGenerator = new class () implements SessionIdGeneratorInterface {
            public function generate(): string
            {
                return 'custom_' . bin2hex(random_bytes(16));
            }
        };

        $options = new RedisSessionHandlerOptions($customGenerator, null, $logger);
        $handler = new RedisSessionHandler($this->connection, $options);

        $handler->open('/tmp', 'PHPSESSID');
        $sessionId = $handler->create_sid();

        self::assertStringStartsWith('custom_', $sessionId);
    }

    public function testSessionIdGeneratorCreatesUniqueIds(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $generator = new DefaultSessionIdGenerator();
        $options = new RedisSessionHandlerOptions($generator, null, $logger);
        $handler = new RedisSessionHandler($this->connection, $options);

        $handler->open('/tmp', 'PHPSESSID');

        $sessionIds = [];
        for ($i = 0; $i < 100; $i++) {
            $sessionIds[] = $handler->create_sid();
        }

        $uniqueIds = array_unique($sessionIds);
        self::assertCount(100, $uniqueIds);
    }

    public function testSessionIdGeneratorAvoidsCollisions(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $generator = new DefaultSessionIdGenerator();
        $options = new RedisSessionHandlerOptions($generator, null, $logger);
        $handler = new RedisSessionHandler($this->connection, $options);

        $handler->open('/tmp', 'PHPSESSID');

        $sessionId1 = $handler->create_sid();
        $handler->write($sessionId1, 'test_data');

        $sessionId2 = $handler->create_sid();

        self::assertNotEquals($sessionId1, $sessionId2);
        self::assertFalse($this->connection->exists($sessionId2));
    }

    public function testSwitchingGeneratorsBetweenHandlers(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $defaultGenerator = new DefaultSessionIdGenerator();
        $defaultOptions = new RedisSessionHandlerOptions($defaultGenerator, null, $logger);
        $defaultHandler = new RedisSessionHandler($this->connection, $defaultOptions);

        $secureGenerator = new SecureSessionIdGenerator(64);
        $secureOptions = new RedisSessionHandlerOptions($secureGenerator, null, $logger);
        $secureHandler = new RedisSessionHandler($this->connection, $secureOptions);

        $defaultHandler->open('/tmp', 'PHPSESSID');
        $defaultSessionId = $defaultHandler->create_sid();

        $secureHandler->open('/tmp', 'PHPSESSID');
        $secureSessionId = $secureHandler->create_sid();

        self::assertSame(32, strlen($defaultSessionId));
        self::assertSame(64, strlen($secureSessionId));
    }
}
