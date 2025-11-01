<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Redis;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;

/**
 * Integration test for PreventEmptySessionCookie functionality.
 *
 * This test verifies that the PreventEmptySessionCookie feature works correctly
 * with real Redis connections and PHP's session mechanism.
 */
class PreventEmptySessionCookieIntegrationTest extends TestCase
{
    private RedisConnection $connection;
    private Logger $logger;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for integration tests');
        }

        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        if ($redisHost === false) {
            self::fail('SESSION_REDIS_HOST environment variable must be set');
        }
        if ($redisPort === false) {
            self::fail('SESSION_REDIS_PORT environment variable must be set');
        }

        if (!ctype_digit($redisPort)) {
            self::fail('SESSION_REDIS_PORT must be a positive integer');
        }

        $host = $redisHost;
        $port = (int)$redisPort;

        $probe = new Redis();
        if (!@$probe->connect($host, $port, 1.5)) {
            self::fail("Redis/Valkey server not reachable at {$host}:{$port}");
        }

        try {
            $pong = $probe->ping();
            if ($pong !== true && $pong !== '+PONG' && $pong !== 'PONG') {
                self::fail('Redis/Valkey server ping failed');
            }
        } catch (Throwable $e) {
            self::fail('Redis/Valkey server check failed: ' . $e->getMessage());
        } finally {
            try {
                $probe->close();
            } catch (Throwable $e) {
            }
        }

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new StreamHandler('php://memory', Logger::DEBUG));

        $config = new RedisConnectionConfig(
            $host,
            $port,
            2.5,
            null,
            0,
            'test:session:'
        );

        $redis = new Redis();
        $this->connection = new RedisConnection($redis, $config, $this->logger);
        $this->connection->connect();
    }

    protected function tearDown(): void
    {
        PreventEmptySessionCookie::reset();

        if (isset($this->connection) && $this->connection->isConnected()) {
            try {
                $keys = $this->connection->keys('*');
                foreach ($keys as $key) {
                    $this->connection->delete($key);
                }
            } catch (Throwable $e) {
            }

            try {
                $this->connection->disconnect();
            } catch (Throwable $e) {
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Test that empty sessions do not write to Redis and cookies are deleted.
     *
     * @runInSeparateProcess
     */
    public function testEmptySessionDoesNotWriteToRedisAndDeletesCookie(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        if (isset($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
        }

        PreventEmptySessionCookie::setup($handler, $this->logger);
        session_start();

        $sessionId = session_id();
        self::assertNotFalse($sessionId, 'Session ID should be generated');

        session_write_close();

        $dataInRedis = $this->connection->get($sessionId);
        self::assertFalse($dataInRedis, 'Empty session should not be written to Redis');

        self::assertFalse(isset($_COOKIE[session_name()]), 'Cookie should not be set in $_COOKIE after cleanup');
    }

    /**
     * Test that sessions with data are written to Redis normally.
     *
     * @runInSeparateProcess
     */
    public function testSessionWithDataWritesToRedis(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        if (isset($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
        }

        PreventEmptySessionCookie::setup($handler, $this->logger);
        session_start();

        $sessionId = session_id();
        self::assertNotFalse($sessionId, 'Session ID should be generated');

        $_SESSION['user_id'] = 123;
        $_SESSION['username'] = 'testuser';

        session_write_close();
        PreventEmptySessionCookie::checkAndCleanup();

        $dataInRedis = $this->connection->get($sessionId);
        self::assertNotFalse($dataInRedis, 'Session with data should be written to Redis');

        $unserializedData = unserialize($dataInRedis);
        self::assertIsArray($unserializedData);
        self::assertSame(123, $unserializedData['user_id']);
        self::assertSame('testuser', $unserializedData['username']);
    }

    /**
     * Test that existing sessions (with cookie already present) work normally.
     *
     * @runInSeparateProcess
     */
    public function testExistingSessionWorksNormally(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        PreventEmptySessionCookie::setup($handler, $this->logger);
        session_start();

        $sessionId = session_id();
        self::assertNotFalse($sessionId, 'Session ID should be generated');

        $_SESSION['existing_data'] = 'test_value';

        session_write_close();

        $dataInRedis = $this->connection->get($sessionId);
        self::assertNotFalse($dataInRedis, 'Initial session data should be written to Redis');

        PreventEmptySessionCookie::reset();
        $_COOKIE[session_name()] = $sessionId;

        $handler2 = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);
        PreventEmptySessionCookie::setup($handler2, $this->logger);
        $setResult = session_id($sessionId);
        self::assertNotFalse($setResult, 'Setting session ID should succeed');
        session_start();

        self::assertArrayHasKey('existing_data', $_SESSION);
        self::assertSame('test_value', $_SESSION['existing_data']);

        session_write_close();
        PreventEmptySessionCookie::checkAndCleanup();

        $dataInRedis = $this->connection->get($sessionId);
        self::assertNotFalse($dataInRedis, 'Existing session data should remain in Redis');
    }

    /**
     * Test that cleanup handler is registered for new sessions.
     *
     * @runInSeparateProcess
     */
    public function testCleanupHandlerRegisteredForNewSession(): void
    {
        $testHandler = new TestHandler(Logger::DEBUG);
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        if (isset($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
        }

        PreventEmptySessionCookie::setup($handler, $logger);

        $records = $testHandler->getRecords();
        $cleanupHandlerRegistered = false;
        foreach ($records as $record) {
            if (isset($record['message']) && is_string($record['message']) && str_contains($record['message'], 'Registered empty session cleanup handler')) {
                $cleanupHandlerRegistered = true;
                break;
            }
        }

        self::assertTrue(
            $cleanupHandlerRegistered,
            'Cleanup handler must be registered for new sessions without existing cookie'
        );
    }

    /**
     * Test that cleanup handler is not registered when session cookie already exists.
     *
     * @runInSeparateProcess
     */
    public function testCleanupHandlerNotRegisteredForExistingCookie(): void
    {
        $testHandler = new TestHandler(Logger::DEBUG);
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        $_COOKIE[session_name()] = 'existing_session_id_' . uniqid();

        PreventEmptySessionCookie::setup($handler, $logger);

        $records = $testHandler->getRecords();
        $cleanupHandlerRegistered = false;
        foreach ($records as $record) {
            if (isset($record['message']) && is_string($record['message']) && str_contains($record['message'], 'Registered empty session cleanup handler')) {
                $cleanupHandlerRegistered = true;
                break;
            }
        }

        self::assertFalse(
            $cleanupHandlerRegistered,
            'Cleanup handler must not be registered when session cookie already exists'
        );
    }

    /**
     * Test that empty session filter prevents Redis write.
     *
     * @runInSeparateProcess
     */
    public function testEmptySessionFilterPreventsRedisWrite(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        PreventEmptySessionCookie::setup($handler, $this->logger);
        session_start();

        $sessionId = session_id();
        self::assertNotFalse($sessionId, 'Session ID should be generated');

        session_write_close();

        $dataInRedis = $this->connection->get($sessionId);
        self::assertFalse($dataInRedis, 'Empty session filter should prevent Redis write');
    }

    /**
     * Test that session with data added after start is written to Redis.
     *
     * @runInSeparateProcess
     */
    public function testSessionDataAddedAfterStartIsWritten(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->connection, new PhpSerializeSerializer(), $options);

        PreventEmptySessionCookie::setup($handler, $this->logger);
        session_start();

        $sessionId = session_id();
        self::assertNotFalse($sessionId, 'Session ID should be generated');

        $_SESSION['added_later'] = 'dynamic_value';

        session_write_close();

        $dataInRedis = $this->connection->get($sessionId);
        self::assertNotFalse($dataInRedis, 'Session with dynamically added data should be written to Redis');

        $unserializedData = unserialize($dataInRedis);
        self::assertIsArray($unserializedData);
        self::assertSame('dynamic_value', $unserializedData['added_later']);
    }
}
