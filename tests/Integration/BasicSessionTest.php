<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

class BasicSessionTest extends TestCase
{
    private RedisConnection $connection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is not loaded');
        }

        $redisHost = getenv('REDIS_HOST');
        $redisPort = getenv('REDIS_PORT');

        $this->connection = new RedisConnection([
            'host' => $redisHost !== false ? $redisHost : 'localhost',
            'port' => $redisPort !== false ? (int)$redisPort : 6379,
            'prefix' => 'test:session:',
        ]);

        $this->handler = new RedisSessionHandler($this->connection);

        try {
            $this->connection->connect();
        } catch (\Exception $e) {
            self::markTestSkipped('Cannot connect to Redis: ' . $e->getMessage());
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
