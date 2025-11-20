<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\RedisIntegrationTestTrait;

class BasicSessionTest extends TestCase
{
    use RedisIntegrationTestTrait;

    private RedisConnection $connection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        $params = $this->getRedisConnectionParameters();
        $this->assertRedisAvailable($params['host'], $params['port']);

        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $this->connection = $this->createRedisConnection(
            $params['host'],
            $params['port'],
            $logger,
            'test:session:'
        );

        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $serializer = new PhpSerializeSerializer();
        $this->handler = new RedisSessionHandler($this->connection, $serializer, $options);

        $this->connection->connect();
    }

    protected function tearDown(): void
    {
        $this->cleanupRedisKeys($this->connection);
    }

    public function testOpenSession(): void
    {
        $result = $this->handler->open('/tmp', 'PHPSESSID');
        self::assertTrue($result);
    }

    public function testWriteAndReadSession(): void
    {
        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData();

        $this->handler->open('/tmp', 'PHPSESSID');
        $writeResult = $this->handler->write($sessionId, $sessionData);
        self::assertTrue($writeResult);

        $readData = $this->handler->read($sessionId);
        self::assertSame($sessionData, $readData);
    }

    public function testDestroySession(): void
    {
        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData(['test_key' => 'test_data']);

        $this->handler->open('/tmp', 'PHPSESSID');
        $this->handler->write($sessionId, $sessionData);

        $destroyResult = $this->handler->destroy($sessionId);
        self::assertTrue($destroyResult);

        $readData = $this->handler->read($sessionId);
        self::assertSame('', $readData);
    }

    public function testValidateId(): void
    {
        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData(['test_key' => 'test_data']);

        $this->handler->open('/tmp', 'PHPSESSID');
        $this->handler->write($sessionId, $sessionData);

        self::assertTrue($this->handler->validateId($sessionId));
        self::assertFalse($this->handler->validateId('non_existent_session'));
    }

    public function testUpdateTimestamp(): void
    {
        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData(['test_key' => 'test_data']);

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
