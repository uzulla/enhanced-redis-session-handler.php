<?php

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

class UserSessionIntegrationTest extends TestCase
{
    private RedisConnection $connection;
    private UserSessionIdGenerator $generator;
    private UserSessionHelper $helper;

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
            'test:usersession:'
        );

        $redis = new Redis();
        $this->connection = new RedisConnection($redis, $config, $logger);
        $this->connection->connect();

        $this->generator = new UserSessionIdGenerator();
        $this->helper = new UserSessionHelper($this->generator, $this->connection, $logger);
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

    public function testMultipleSessionsForSameUser(): void
    {
        $userId = '123';

        // 同一ユーザーで3つのセッションを作成
        $sessionId1 = 'user123_abc123';
        $sessionId2 = 'user123_def456';
        $sessionId3 = 'user123_ghi789';

        $this->connection->set($sessionId1, 'session_data_1', 3600);
        $this->connection->set($sessionId2, 'session_data_2', 3600);
        $this->connection->set($sessionId3, 'session_data_3', 3600);

        // セッション数を確認
        $count = $this->helper->countUserSessions($userId);
        self::assertSame(3, $count);

        // セッション一覧を取得
        $sessions = $this->helper->getUserSessions($userId);
        self::assertCount(3, $sessions);
        self::assertArrayHasKey($sessionId1, $sessions);
        self::assertArrayHasKey($sessionId2, $sessions);
        self::assertArrayHasKey($sessionId3, $sessions);
    }

    public function testForceLogoutDeletesAllUserSessions(): void
    {
        $userId = '456';

        // ユーザーの複数セッションを作成
        $sessionId1 = 'user456_session1';
        $sessionId2 = 'user456_session2';
        $this->connection->set($sessionId1, 'data1', 3600);
        $this->connection->set($sessionId2, 'data2', 3600);

        // 別ユーザーのセッションも作成（削除されないことを確認）
        $otherSessionId = 'user789_session1';
        $this->connection->set($otherSessionId, 'other_data', 3600);

        // 強制ログアウト実行
        $deletedCount = $this->helper->forceLogoutUser($userId);
        self::assertSame(2, $deletedCount);

        // ユーザー456のセッションが削除されたことを確認
        self::assertFalse($this->connection->exists($sessionId1));
        self::assertFalse($this->connection->exists($sessionId2));

        // 別ユーザーのセッションは残っていることを確認
        self::assertTrue($this->connection->exists($otherSessionId));
    }

    public function testGetUserSessionsReturnsCorrectData(): void
    {
        $userId = '999';

        $sessionId1 = 'user999_abc';
        $sessionId2 = 'user999_def';
        $data1 = 'session_data_for_user_999_session_1';
        $data2 = 'session_data_for_user_999_session_2';

        $this->connection->set($sessionId1, $data1, 3600);
        $this->connection->set($sessionId2, $data2, 3600);

        $sessions = $this->helper->getUserSessions($userId);

        self::assertCount(2, $sessions);

        // セッション情報の構造を確認
        self::assertArrayHasKey('session_id', $sessions[$sessionId1]);
        self::assertArrayHasKey('data_size', $sessions[$sessionId1]);

        // データサイズが正しいことを確認
        self::assertSame(strlen($data1), $sessions[$sessionId1]['data_size']);
        self::assertSame(strlen($data2), $sessions[$sessionId2]['data_size']);

        // セッションIDがマスキングされていることを確認
        self::assertStringStartsWith('...', $sessions[$sessionId1]['session_id']);
    }

    public function testCountUserSessionsWithNoSessions(): void
    {
        $userId = 'nonexistent';

        $count = $this->helper->countUserSessions($userId);
        self::assertSame(0, $count);
    }

    public function testForceLogoutUserWithNoSessions(): void
    {
        $userId = 'nonexistent';

        $deletedCount = $this->helper->forceLogoutUser($userId);
        self::assertSame(0, $deletedCount);
    }

    public function testGetUserSessionsWithNoSessions(): void
    {
        $userId = 'nonexistent';

        $sessions = $this->helper->getUserSessions($userId);
        self::assertSame([], $sessions);
    }

    public function testUserSessionIsolation(): void
    {
        // 異なるユーザーのセッションが混ざらないことを確認
        $this->connection->set('user111_session1', 'data1', 3600);
        $this->connection->set('user111_session2', 'data2', 3600);
        $this->connection->set('user222_session1', 'data3', 3600);
        $this->connection->set('user333_session1', 'data4', 3600);

        // ユーザー111のセッションのみ取得
        $sessions111 = $this->helper->getUserSessions('111');
        self::assertCount(2, $sessions111);
        self::assertArrayHasKey('user111_session1', $sessions111);
        self::assertArrayHasKey('user111_session2', $sessions111);

        // ユーザー222のセッションのみ取得
        $sessions222 = $this->helper->getUserSessions('222');
        self::assertCount(1, $sessions222);
        self::assertArrayHasKey('user222_session1', $sessions222);

        // ユーザー111のセッションを削除
        $deletedCount = $this->helper->forceLogoutUser('111');
        self::assertSame(2, $deletedCount);

        // ユーザー111のセッションが削除され、他は残っていることを確認
        self::assertSame(0, $this->helper->countUserSessions('111'));
        self::assertSame(1, $this->helper->countUserSessions('222'));
        self::assertSame(1, $this->helper->countUserSessions('333'));
    }

    public function testForceLogoutWithHyphenatedUserId(): void
    {
        $userId = 'user-with-hyphens';

        // ハイフン付きユーザーIDのセッションを作成
        $sessionId1 = 'useruser-with-hyphens_session1';
        $sessionId2 = 'useruser-with-hyphens_session2';
        $this->connection->set($sessionId1, 'data1', 3600);
        $this->connection->set($sessionId2, 'data2', 3600);

        $count = $this->helper->countUserSessions($userId);
        self::assertSame(2, $count);

        $deletedCount = $this->helper->forceLogoutUser($userId);
        self::assertSame(2, $deletedCount);
    }

    public function testLargeNumberOfSessions(): void
    {
        $userId = 'stress-test-user';
        $sessionCount = 100;

        // 100個のセッションを作成
        for ($i = 0; $i < $sessionCount; $i++) {
            $sessionId = sprintf('user%s_session%03d', $userId, $i);
            $this->connection->set($sessionId, 'data_' . $i, 3600);
        }

        // セッション数を確認
        $count = $this->helper->countUserSessions($userId);
        self::assertSame($sessionCount, $count);

        // 全セッションを取得
        $sessions = $this->helper->getUserSessions($userId);
        self::assertCount($sessionCount, $sessions);

        // 全セッションを削除
        $deletedCount = $this->helper->forceLogoutUser($userId);
        self::assertSame($sessionCount, $deletedCount);

        // 削除されたことを確認
        $count = $this->helper->countUserSessions($userId);
        self::assertSame(0, $count);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSetUserIdAndRegenerateWithRealSession(): void
    {
        // セッションハンドラを設定
        $handler = $this->createSessionHandler();
        session_set_save_handler($handler, true);

        // セッションを開始
        session_start();
        $oldSessionId = session_id();
        self::assertIsString($oldSessionId);

        // セッションデータを設定
        $_SESSION['test_data'] = 'before_regenerate';

        // ユーザーIDを設定してセッションIDを再生成
        $userId = 'integration_test_user_123';
        $result = $this->helper->setUserIdAndRegenerate($userId);

        self::assertTrue($result);

        // セッションIDが変更されたことを確認
        $newSessionId = session_id();
        self::assertIsString($newSessionId);
        self::assertNotEquals($oldSessionId, $newSessionId);

        // セッションデータが保持されていることを確認
        self::assertSame('before_regenerate', $_SESSION['test_data']);

        // 新しいセッションIDにユーザープレフィックスが含まれることを確認
        self::assertStringStartsWith('user' . $userId . '_', $newSessionId);

        // 古いセッションがRedisから削除されていることを確認
        self::assertFalse($this->connection->exists($oldSessionId));

        // 新しいセッションがRedisに存在することを確認
        self::assertTrue($this->connection->exists($newSessionId));

        session_write_close();
        $this->connection->delete($newSessionId);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSetUserIdAndRegenerateCreatesUserPrefixedSessionId(): void
    {
        $handler = $this->createSessionHandler();
        session_set_save_handler($handler, true);

        session_start();
        $userId = 'testuser456';

        $result = $this->helper->setUserIdAndRegenerate($userId);

        self::assertTrue($result);

        $newSessionId = session_id();
        self::assertIsString($newSessionId);
        self::assertStringStartsWith('user' . $userId . '_', $newSessionId);

        // ジェネレータのユーザーIDが正しく設定されていることを確認
        self::assertSame($userId, $this->generator->getUserId());

        session_write_close();
        $this->connection->delete($newSessionId);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSetUserIdAndRegenerateMultipleTimes(): void
    {
        $handler = $this->createSessionHandler();
        session_set_save_handler($handler, true);

        session_start();

        // 最初のユーザーでログイン
        $userId1 = 'user_one';
        $result1 = $this->helper->setUserIdAndRegenerate($userId1);
        self::assertTrue($result1);

        $sessionId1 = session_id();
        self::assertIsString($sessionId1);
        self::assertStringStartsWith('user' . $userId1 . '_', $sessionId1);

        $_SESSION['user_data'] = 'data_for_user_one';

        // 2番目のユーザーでログイン（同じセッション内でユーザー切り替え）
        $userId2 = 'user_two';
        $result2 = $this->helper->setUserIdAndRegenerate($userId2);
        self::assertTrue($result2);

        $sessionId2 = session_id();
        self::assertIsString($sessionId2);
        self::assertStringStartsWith('user' . $userId2 . '_', $sessionId2);
        self::assertNotEquals($sessionId1, $sessionId2);

        // セッションデータは保持される
        self::assertSame('data_for_user_one', $_SESSION['user_data']);

        // 最初のセッションは削除されている
        self::assertFalse($this->connection->exists($sessionId1));

        session_write_close();
        $this->connection->delete($sessionId2);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSetUserIdAndRegenerateWithInvalidUserId(): void
    {
        $handler = $this->createSessionHandler();
        session_set_save_handler($handler, true);

        session_start();

        // 空文字列のユーザーIDは無効
        $this->expectException(\InvalidArgumentException::class);
        $this->helper->setUserIdAndRegenerate('');

        session_write_close();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSetUserIdAndRegenerateWithReservedWords(): void
    {
        $handler = $this->createSessionHandler();
        session_set_save_handler($handler, true);

        session_start();

        // 予約語は使用できない
        $this->expectException(\InvalidArgumentException::class);
        $this->helper->setUserIdAndRegenerate('anonymous');

        session_write_close();
    }

    /**
     * セッションハンドラを作成するヘルパーメソッド
     */
    private function createSessionHandler(): RedisSessionHandler
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

        $options = new RedisSessionHandlerOptions(
            $this->generator,
            null,
            $logger
        );
        $serializer = new PhpSerializeSerializer();

        return new RedisSessionHandler(
            $this->connection,
            $serializer,
            $options
        );
    }
}
