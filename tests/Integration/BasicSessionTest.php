<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

class BasicSessionTest extends TestCase
{
    private RedisConnection $connection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for integration tests');
        }

        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $config = new RedisConnectionConfig(
            host: $redisHost !== false ? $redisHost : 'localhost',
            port: $redisPort !== false ? (int)$redisPort : 6379,
            prefix: 'test:session:'
        );

        $this->connection = new RedisConnection($config, $logger);
        $this->handler = new RedisSessionHandler($this->connection, ['logger' => $logger]);

        try {
            $this->connection->connect();
        } catch (\Exception $e) {
            self::markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }
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

    public function testOpenSession(): void
    {
        $result = $this->handler->open('/tmp', 'PHPSESSID');
        self::assertTrue($result);
    }

    public function testWriteAndReadSession(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $sessionData = 'test_data_' . time();

        $this->handler->open('/tmp', 'PHPSESSID');
        $writeResult = $this->handler->write($sessionId, $sessionData);
        self::assertTrue($writeResult);

        $readData = $this->handler->read($sessionId);
        self::assertSame($sessionData, $readData);
    }

    public function testDestroySession(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $sessionData = 'test_data';

        $this->handler->open('/tmp', 'PHPSESSID');
        $this->handler->write($sessionId, $sessionData);

        $destroyResult = $this->handler->destroy($sessionId);
        self::assertTrue($destroyResult);

        $readData = $this->handler->read($sessionId);
        self::assertSame('', $readData);
    }

    public function testValidateId(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $sessionData = 'test_data';

        $this->handler->open('/tmp', 'PHPSESSID');
        $this->handler->write($sessionId, $sessionData);

        self::assertTrue($this->handler->validateId($sessionId));
        self::assertFalse($this->handler->validateId('non_existent_session'));
    }

    public function testUpdateTimestamp(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $sessionData = 'test_data';

        $this->handler->open('/tmp', 'PHPSESSID');
        $this->handler->write($sessionId, $sessionData);

        $result = $this->handler->updateTimestamp($sessionId, $sessionData);
        self::assertTrue($result);
    }

    public function testCreateSid(): void
    {
        $sid = $this->handler->create_sid();
        self::assertNotEmpty($sid);
    }
}
