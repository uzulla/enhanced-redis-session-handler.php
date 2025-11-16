<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;

class RedisConnectionTest extends TestCase
{
    public function testConstructorWithDefaultConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new Redis();
        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testConstructorWithCustomConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new Redis();
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

        $redis = new Redis();
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

        $redis = new Redis();
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

        $redis = new Redis();
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

        $redis = new Redis();
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

        $redis = new Redis();
        // 無効なホストで接続失敗させる
        $config = new RedisConnectionConfig('invalid-host-that-does-not-exist', 6379, 0.1, null, 0, '', false, 0);
        $connection = new RedisConnection($redis, $config, $logger);

        // 接続失敗時はConnectionExceptionがスローされる
        $this->expectException(ConnectionException::class);
        $connection->delete('test_key');
    }

    /**
     * scan()メソッドがプレフィックスを先頭からのみ除去することをテスト
     *
     * プレフィックスがキー名の途中にも含まれる場合、先頭のプレフィックスのみが除去され、
     * キー内の他の出現箇所は保持されることを検証します。
     *
     * 例: prefix="test_prefix:"、キー="test_prefix:test_prefix:abc"の場合
     * 期待される結果: "test_prefix:abc" (先頭のプレフィックスのみ除去)
     * バグがある場合: "abc" (すべてのプレフィックスが除去されてしまう)
     *
     * @return void
     */
    public function testScanRemovesOnlyPrefixAtBeginning(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for this test');
        }

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new Redis();
        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPortEnv = getenv('SESSION_REDIS_PORT');
        $redisPort = $redisPortEnv !== false ? (int)$redisPortEnv : 6379;

        $prefix = 'test_prefix:';
        $config = new RedisConnectionConfig($redisHost, $redisPort, 2.5, null, 0, $prefix);
        $connection = new RedisConnection($redis, $config, $logger);

        if (!$connection->connect()) {
            self::markTestSkipped('Redis connection not available');
        }

        // プレフィックスがキーの途中にも含まれるテストケース
        // 例: prefix="test_prefix:"で、キー="test_prefix:test_prefix:abc"の場合
        // 結果は"test_prefix:abc"であるべき（先頭のプレフィックスのみ除去）
        self::assertTrue($connection->set('test_prefix:abc', 'value1', 60), 'Failed to create test key: test_prefix:abc');
        self::assertTrue($connection->set('test_prefix:xyz', 'value2', 60), 'Failed to create test key: test_prefix:xyz');

        $keys = $connection->scan('test_prefix:*');

        // プレフィックスが正しく除去されていることを確認
        self::assertContains('test_prefix:abc', $keys);
        self::assertContains('test_prefix:xyz', $keys);

        // プレフィックスが2回除去されていないことを確認（バグがある場合は"abc"になってしまう）
        self::assertNotContains('abc', $keys);
        self::assertNotContains('xyz', $keys);

        // クリーンアップ
        $connection->delete('test_prefix:abc');
        $connection->delete('test_prefix:xyz');
    }

    /**
     * scan()メソッドが重複キーを排除することをテスト
     *
     * Redis SCANコマンドはイテレーション中に同じキーを複数回返す可能性があります。
     * このテストでは、scan()メソッドが配列キーを使用して重複を正しく排除し、
     * 各キーが結果配列に一度だけ含まれることを検証します。
     *
     * Redis SCANの動作仕様として、以下の条件で重複が発生する可能性があります：
     * - データセットが大きい場合
     * - イテレーション中にキーの追加・削除が行われた場合
     * - Redisのリハッシュ処理が実行された場合
     *
     * テスト戦略：
     * - 大量のキー（200個）を作成して重複発生確率を高める
     * - 結果配列に重複がないことを検証（array_unique との比較）
     * - すべてのキーが正しく取得できることを検証
     * - 配列キーによる重複排除ロジック（src/RedisConnection.php:272）が機能することを確認
     *
     * @return void
     */
    public function testScanReturnsDeduplicated(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for this test');
        }

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new Redis();
        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPortEnv = getenv('SESSION_REDIS_PORT');
        $redisPort = $redisPortEnv !== false ? (int)$redisPortEnv : 6379;

        $prefix = 'test_dedup:';
        $config = new RedisConnectionConfig($redisHost, $redisPort, 2.5, null, 0, $prefix);
        $connection = new RedisConnection($redis, $config, $logger);

        if (!$connection->connect()) {
            self::markTestSkipped('Redis connection not available');
        }

        // 大量のキーを作成してRedis SCANの重複発生確率を高める
        // Redis SCANは内部のハッシュテーブルを走査するため、キー数が多いほど
        // イテレーション中に同じスロットを複数回訪問する可能性が高まる
        $testKeys = [];
        for ($i = 1; $i <= 200; $i++) {
            $testKeys[] = sprintf('key_%03d', $i);
        }

        // キーを作成
        foreach ($testKeys as $key) {
            self::assertTrue($connection->set($key, 'value', 60), "Failed to create test key: $key");
        }

        // scan() を実行
        $keys = $connection->scan('*');

        // 検証1: 結果配列に重複がないことを確認
        // array_uniqueを適用しても配列のサイズが変わらないことで、重複がないことを検証
        $uniqueKeys = array_unique($keys);
        self::assertCount(
            count($uniqueKeys),
            $keys,
            'scan() result should not contain duplicates. The deduplication logic using array keys should prevent duplicates.'
        );

        // 検証2: すべてのテストキーが取得できていることを確認
        sort($testKeys);
        sort($keys);
        self::assertEquals(
            $testKeys,
            $keys,
            'scan() should return all created keys exactly once'
        );

        // 検証3: 各キーが正確に1回だけ出現することを確認
        $keyCounts = array_count_values($keys);
        foreach ($testKeys as $key) {
            self::assertArrayHasKey($key, $keyCounts, "Key '$key' should be present in scan results");
            self::assertEquals(1, $keyCounts[$key], "Key '$key' should appear exactly once, not {$keyCounts[$key]} times");
        }

        // 検証4: 期待される数のキーが返されることを確認
        self::assertCount(
            count($testKeys),
            $keys,
            'scan() should return exactly ' . count($testKeys) . ' unique keys'
        );

        // クリーンアップ
        foreach ($testKeys as $key) {
            $connection->delete($key);
        }
    }

    /**
     * scan()メソッドが接続失敗時にConnectionExceptionをスローすることをテスト
     *
     * 無効なホストへの接続を試みた場合、scan()メソッドがConnectionExceptionを
     * 正しくスローすることを検証します。これにより、呼び出し元でエラーハンドリングが
     * 可能になります。
     *
     * @return void
     */
    public function testScanThrowsConnectionExceptionOnFailure(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new Redis();
        // 無効なホストで接続失敗させる
        $config = new RedisConnectionConfig('invalid-host-that-does-not-exist', 6379, 0.1, null, 0, '', false, 0);
        $connection = new RedisConnection($redis, $config, $logger);

        // 接続失敗時はConnectionExceptionがスローされる
        $this->expectException(ConnectionException::class);
        $connection->scan('*');
    }
}
