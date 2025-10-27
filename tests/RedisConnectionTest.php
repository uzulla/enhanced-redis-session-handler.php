<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;

class RedisConnectionTest extends TestCase
{
    public function testConstructorWithDefaultConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testConstructorWithCustomConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            '127.0.0.1',
            6380,
            2.5,
            null,
            0,
            'test:'
        );
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertFalse($connection->isConnected());
    }

    public function testDeleteReturnsTrueWhenKeyExists(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for this test');
        }

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPortEnv = getenv('SESSION_REDIS_PORT');
        $redisPort = $redisPortEnv !== false ? (int)$redisPortEnv : 6379;

        $config = new RedisConnectionConfig($redisHost, $redisPort);
        $connection = new RedisConnection($redis, $config, $logger);

        if (!$connection->connect()) {
            self::markTestSkipped('Redis connection not available');
        }

        // テストキーを設定
        $connection->set('test_delete_key_exists', 'value', 60);

        $result = $connection->delete('test_delete_key_exists');
        self::assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenKeyDoesNotExist(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for this test');
        }

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPortEnv = getenv('SESSION_REDIS_PORT');
        $redisPort = $redisPortEnv !== false ? (int)$redisPortEnv : 6379;

        $config = new RedisConnectionConfig($redisHost, $redisPort);
        $connection = new RedisConnection($redis, $config, $logger);

        if (!$connection->connect()) {
            self::markTestSkipped('Redis connection not available');
        }

        // キーが存在しないことを確認
        $connection->delete('test_delete_nonexistent_key');

        $result = $connection->delete('test_delete_nonexistent_key');
        self::assertFalse($result);
    }

    public function testDeleteReturnsTrueWhenKeyExistsAmongMultiple(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for this test');
        }

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPortEnv = getenv('SESSION_REDIS_PORT');
        $redisPort = $redisPortEnv !== false ? (int)$redisPortEnv : 6379;

        $config = new RedisConnectionConfig($redisHost, $redisPort);
        $connection = new RedisConnection($redis, $config, $logger);

        if (!$connection->connect()) {
            self::markTestSkipped('Redis connection not available');
        }

        // 複数のキーを設定して、その中の1つを削除できることを確認
        $connection->set('test_delete_multiple_1', 'value1', 60);
        $connection->set('test_delete_multiple_2', 'value2', 60);

        // 1つのキーを削除（存在するキーなので true を返す）
        $result = $connection->delete('test_delete_multiple_1');
        self::assertTrue($result);

        // クリーンアップ
        $connection->delete('test_delete_multiple_2');
    }

    public function testDeleteHandlesConnectionFailure(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        // 無効なホストで接続失敗させる
        $config = new RedisConnectionConfig('invalid-host-that-does-not-exist', 6379, 0.1, null, 0, '', false, 0);
        $connection = new RedisConnection($redis, $config, $logger);

        // 接続失敗時はConnectionExceptionがスローされる
        $this->expectException(\Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException::class);
        $connection->delete('test_key');
    }
}
