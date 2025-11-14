<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\UserSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\UserSessionHelper;
use Redis;

/**
 * UserSessionHelper.setUserIdAndRegenerate() の統合テスト
 *
 * このテストは、実際のRedis接続とPHPのセッション機構を使用して、
 * セッションID再生成時のプレフィックス付与が正しく動作することを検証します。
 */
class UserSessionRegenerateTest extends TestCase
{
    private RedisConnection $connection;
    private UserSessionIdGenerator $generator;
    private UserSessionHelper $helper;
    private Logger $logger;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required');
        }

        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        self::assertNotFalse($redisHost, 'SESSION_REDIS_HOST environment variable must be set');
        self::assertNotFalse($redisPort, 'SESSION_REDIS_PORT environment variable must be set');

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new StreamHandler('php://memory', Logger::DEBUG));

        $config = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'test:usersession:'
        );

        $redis = new Redis();
        $this->connection = new RedisConnection($redis, $config, $this->logger);
        $this->connection->connect();

        $this->generator = new UserSessionIdGenerator();
        $this->helper = new UserSessionHelper($this->generator, $this->connection, $this->logger);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (isset($this->connection) && $this->connection->isConnected()) {
            try {
                /** @var list<string> $keys */
                $keys = $this->connection->keys('*');
                foreach ($keys as $key) {
                    $this->connection->delete($key);
                }
            } catch (\Throwable $e) {
                // Cleanup failure is not critical for tests
            }
            $this->connection->disconnect();
        }
    }

    /**
     * セッションID再生成時にユーザーIDプレフィックスが付与されることを検証
     *
     * @runInSeparateProcess
     */
    public function testSetUserIdAndRegenerateWithPrefixAssertion(): void
    {
        $options = new RedisSessionHandlerOptions(
            $this->generator,
            null,
            $this->logger
        );
        $handler = new RedisSessionHandler(
            $this->connection,
            new PhpSerializeSerializer(),
            $options
        );

        // カスタムセッションハンドラーを登録
        session_set_save_handler($handler, true);

        // セッション保存パスを設定（デフォルトのファイルシステムパスの問題を回避）
        session_save_path(sys_get_temp_dir());

        // セッション開始
        session_start();
        $oldSessionId = session_id();
        self::assertNotFalse($oldSessionId, 'Session ID should be generated');

        // 最初のセッションIDは匿名プレフィックスで始まることを確認
        self::assertStringStartsWith('anon_', $oldSessionId);

        // セッションデータを設定
        $_SESSION['test_data'] = 'value';

        // ユーザーIDを設定してセッションID再生成
        $userId = 'test_user_123';
        $result = $this->helper->setUserIdAndRegenerate($userId);

        self::assertTrue($result, 'setUserIdAndRegenerate should return true');

        // 新しいセッションIDを取得
        $newSessionId = session_id();
        self::assertNotFalse($newSessionId, 'New session ID should not be false');
        self::assertNotEquals($oldSessionId, $newSessionId, 'Session ID should change');

        // 新しいセッションIDがユーザーIDプレフィックスで始まることを確認
        self::assertStringStartsWith("user{$userId}_", $newSessionId);

        // セッションデータが保持されていることを確認
        self::assertArrayHasKey('test_data', $_SESSION);
        self::assertSame('value', $_SESSION['test_data']);

        // ジェネレータにユーザーIDが設定されていることを確認
        self::assertSame($userId, $this->generator->getUserId());

        session_write_close();
    }

    /**
     * 異なるユーザーIDで複数回再生成できることを検証
     *
     * @runInSeparateProcess
     */
    public function testMultipleRegenerationsWithDifferentUserIds(): void
    {
        $options = new RedisSessionHandlerOptions(
            $this->generator,
            null,
            $this->logger
        );
        $handler = new RedisSessionHandler(
            $this->connection,
            new PhpSerializeSerializer(),
            $options
        );

        session_set_save_handler($handler, true);
        session_save_path(sys_get_temp_dir());
        session_start();

        $anonymousId = session_id();
        self::assertNotFalse($anonymousId, 'Anonymous session ID should be generated');
        self::assertStringStartsWith('anon_', $anonymousId);

        // 最初のユーザーでログイン
        $userId1 = 'user_001';
        $result1 = $this->helper->setUserIdAndRegenerate($userId1);
        self::assertTrue($result1, 'setUserIdAndRegenerate should return true for first user');
        $sessionId1 = session_id();
        self::assertNotFalse($sessionId1, 'User session ID should be generated');
        self::assertStringStartsWith("user{$userId1}_", $sessionId1);

        // セッションを閉じてログアウトをシミュレート
        session_write_close();

        // 古いセッションを手動で削除
        $this->connection->delete($sessionId1);

        // ジェネレータをリセット
        $this->generator->clearUserId();

        // 新しいセッションIDを強制（古いセッションIDを使わない）
        // session_start()の前に呼ぶ必要がある
        session_id('');

        // 新しいセッションを開始
        session_start();

        $newAnonymousId = session_id();
        self::assertNotFalse($newAnonymousId, 'New anonymous session ID should be generated');
        self::assertStringStartsWith('anon_', $newAnonymousId);
        self::assertNotEquals($sessionId1, $newAnonymousId);

        // 別のユーザーでログイン
        $userId2 = 'user_002';
        $result2 = $this->helper->setUserIdAndRegenerate($userId2);
        self::assertTrue($result2, 'setUserIdAndRegenerate should return true for second user');
        $sessionId2 = session_id();
        self::assertNotFalse($sessionId2, 'Second user session ID should be generated');
        self::assertStringStartsWith("user{$userId2}_", $sessionId2);
        self::assertNotEquals($sessionId1, $sessionId2);
        self::assertNotEquals($newAnonymousId, $sessionId2);

        session_write_close();
    }
}
