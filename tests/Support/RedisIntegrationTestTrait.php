<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Support;

use Redis;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

/**
 * Redis統合テストで共通的に使用されるヘルパーメソッド群
 *
 * このトレイトは、Redis接続のセットアップ、環境変数の取得、
 * テストデータのクリーンアップなど、統合テストで頻繁に使用される
 * 処理を提供します。
 */
trait RedisIntegrationTestTrait
{
    /**
     * Redis接続のセットアップ用環境変数を取得し、検証する
     *
     * @return array{host: string, port: int}
     */
    protected function getRedisConnectionParameters(): array
    {
        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        self::assertNotFalse($redisHost, 'SESSION_REDIS_HOST environment variable must be set');
        self::assertNotFalse($redisPort, 'SESSION_REDIS_PORT environment variable must be set');

        return [
            'host' => $redisHost,
            'port' => (int)$redisPort,
        ];
    }

    /**
     * Redisサーバーの接続性を検証する
     *
     * @param string $host Redis ホスト
     * @param int $port Redis ポート
     */
    protected function assertRedisAvailable(string $host, int $port): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for integration tests');
        }

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
                // クリーンアップ中のエラーは無視
            }
        }
    }

    /**
     * Redis接続を作成する
     *
     * @param string $host
     * @param int $port
     * @param \Psr\Log\LoggerInterface $logger
     * @param string $prefix キープレフィックス
     * @param int $database データベース番号
     * @return RedisConnection
     */
    protected function createRedisConnection(
        string $host,
        int $port,
        \Psr\Log\LoggerInterface $logger,
        string $prefix = 'session:',
        int $database = 0
    ): RedisConnection {
        $redis = new Redis();
        $config = new RedisConnectionConfig(
            $host,
            $port,
            2.5,
            null,
            $database,
            $prefix
        );

        return new RedisConnection($redis, $config, $logger);
    }

    /**
     * Redis接続をクリーンアップする（全てのキーを削除）
     *
     * @param RedisConnection ...$connections クリーンアップ対象の接続（複数可）
     */
    protected function cleanupRedisKeys(RedisConnection ...$connections): void
    {
        foreach ($connections as $connection) {
            try {
                if (!$connection->isConnected()) {
                    $connection->connect();
                }
                $keys = $connection->scan('*');
                foreach ($keys as $key) {
                    $connection->delete($key);
                }
                $connection->disconnect();
            } catch (Throwable $e) {
                // テスト後のクリーンアップ失敗は無視
            }
        }
    }

    /**
     * テスト用のユニークなセッションIDを生成する
     *
     * @param string $prefix プレフィックス（デフォルト: 'test_session_'）
     * @return string
     */
    protected function generateTestSessionId(string $prefix = 'test_session_'): string
    {
        return $prefix . uniqid();
    }

    /**
     * テスト用のセッションデータを作成する
     *
     * @param array<string, mixed> $data
     * @return string シリアライズされたデータ
     */
    protected function createTestSessionData(array $data = []): string
    {
        $defaultData = ['test_key' => 'test_data_' . time()];
        return serialize(array_merge($defaultData, $data));
    }
}
