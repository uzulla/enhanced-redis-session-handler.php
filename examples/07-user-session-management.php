<?php

declare(strict_types=1);

/**
 * ユーザーセッション管理の使用例 / User Session Management Example
 *
 * このサンプルは、UserSessionIdGeneratorとUserSessionHelperを使用した
 * ユーザー単位のセッション管理の実装方法を示します。
 *
 * This example demonstrates how to implement user-level session management
 * using UserSessionIdGenerator and UserSessionHelper.
 *
 * 主な機能 / Key Features:
 * - 匿名セッション（ログイン前）
 * - ログイン時のセッションID再生成（セッションフィクセーション攻撃対策）
 * - 特定ユーザーの全セッション強制ログアウト
 * - セッション監査機能
 *
 * - Anonymous sessions (before login)
 * - Session ID regeneration on login (session fixation attack prevention)
 * - Force logout all sessions for a specific user
 * - Session auditing features
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/07-user-session-management.php
 * ```
 *
 * 前提条件 / Prerequisites:
 * - Redisサーバーがlocalhost:6379で起動していること
 * - Redis server running on localhost:6379
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\UserSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\UserSessionHelper;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Psr\Log\NullLogger;
use Redis;

echo "=== Enhanced Redis Session Handler - User Session Management Example ===\n\n";

/**
 * セットアップ / Setup
 */
echo "--- Setup ---\n\n";

try {
    echo "Setting up session handler with UserSessionIdGenerator...\n";

    // UserSessionIdGeneratorを作成
    $generator = new UserSessionIdGenerator(32, 'anon');

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'example:usersession:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        $generator,
        1440,
        new NullLogger()
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    // UserSessionHelperを作成
    $redis = new Redis();
    $logger = new NullLogger();
    $connection = new \Uzulla\EnhancedRedisSessionHandler\RedisConnection(
        $redis,
        $connectionConfig,
        $logger
    );
    $connection->connect();

    $helper = new UserSessionHelper($generator, $connection, $logger);

    echo "Setup completed successfully!\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * シナリオ1: 匿名セッション（ログイン前）
 * Scenario 1: Anonymous Session (Before Login)
 */
echo "--- Scenario 1: Anonymous Session (Before Login) ---\n\n";

try {
    session_set_save_handler($handler, true);
    session_start();

    $anonymousSessionId = session_id();
    echo "Anonymous Session ID: {$anonymousSessionId}\n";
    echo "Notice the 'anon_' prefix - this user is not logged in yet\n\n";

    $_SESSION['visited_pages'] = ['home', 'products'];
    $_SESSION['cart_items'] = 2;

    echo "Data stored in anonymous session:\n";
    echo "- visited_pages: " . implode(', ', $_SESSION['visited_pages']) . "\n";
    echo "- cart_items: " . $_SESSION['cart_items'] . "\n\n";

    session_write_close();
    echo "Anonymous session saved\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * シナリオ2: ログイン処理とセッションID再生成
 * Scenario 2: Login and Session ID Regeneration
 */
echo "--- Scenario 2: Login and Session ID Regeneration ---\n\n";

try {
    // セッションを再開
    session_set_save_handler($handler, true);
    session_start();

    echo "Before login:\n";
    echo "- Session ID: " . session_id() . "\n";
    echo "- Has User ID: " . ($generator->hasUserId() ? 'Yes' : 'No') . "\n\n";

    // ログイン処理をシミュレート
    echo "Simulating login for user '123'...\n";

    // ユーザーIDを設定してセッションID再生成
    $helper->setUserIdAndRegenerate('123');

    echo "\nAfter login:\n";
    echo "- Session ID: " . session_id() . "\n";
    echo "- Notice the 'user123_' prefix - session fixation attack prevented!\n";
    echo "- Has User ID: " . ($generator->hasUserId() ? 'Yes' : 'No') . "\n";
    echo "- User ID: " . $generator->getUserId() . "\n\n";

    // セッションデータは自動的に新しいセッションIDに移行される
    echo "Session data preserved after regeneration:\n";
    echo "- visited_pages: " . implode(', ', $_SESSION['visited_pages']) . "\n";
    echo "- cart_items: " . $_SESSION['cart_items'] . "\n\n";

    // ログイン後のデータを追加
    $_SESSION['user_id'] = '123';
    $_SESSION['username'] = 'john_doe';
    $_SESSION['login_time'] = time();

    session_write_close();
    echo "User session saved successfully!\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * シナリオ3: 同一ユーザーの複数セッション
 * Scenario 3: Multiple Sessions for Same User
 */
echo "--- Scenario 3: Multiple Sessions for Same User ---\n\n";

try {
    echo "Creating multiple sessions for user '123' (e.g., different devices)...\n\n";

    // デバイス1: PC
    $connection->set('user123_session_pc_abc123', serialize(['device' => 'PC', 'ip' => '192.168.1.10']), 3600);
    echo "- Device 1 (PC): user123_session_pc_abc123\n";

    // デバイス2: スマートフォン
    $connection->set('user123_session_mobile_def456', serialize(['device' => 'Mobile', 'ip' => '192.168.1.20']), 3600);
    echo "- Device 2 (Mobile): user123_session_mobile_def456\n";

    // デバイス3: タブレット
    $connection->set('user123_session_tablet_ghi789', serialize(['device' => 'Tablet', 'ip' => '192.168.1.30']), 3600);
    echo "- Device 3 (Tablet): user123_session_tablet_ghi789\n\n";

    // アクティブセッション数を取得
    $sessionCount = $helper->countUserSessions('123');
    echo "Active sessions for user '123': {$sessionCount}\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * シナリオ4: セッション監査
 * Scenario 4: Session Auditing
 */
echo "--- Scenario 4: Session Auditing ---\n\n";

try {
    echo "Retrieving session information for user '123'...\n\n";

    $sessions = $helper->getUserSessions('123');

    echo "Session Details:\n";
    foreach ($sessions as $sessionKey => $info) {
        echo "- Session: {$info['session_id']}\n";
        echo "  Data size: {$info['data_size']} bytes\n";
    }
    echo "\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * シナリオ5: 強制ログアウト（管理機能）
 * Scenario 5: Force Logout (Admin Feature)
 */
echo "--- Scenario 5: Force Logout (Admin Feature) ---\n\n";

try {
    echo "Admin is forcing logout for user '123' (e.g., security incident)...\n";

    // セッション削除前の数を確認
    $beforeCount = $helper->countUserSessions('123');
    echo "Sessions before force logout: {$beforeCount}\n";

    // 全セッションを削除
    $deletedCount = $helper->forceLogoutUser('123');
    echo "Sessions deleted: {$deletedCount}\n";

    // セッション削除後の数を確認
    $afterCount = $helper->countUserSessions('123');
    echo "Sessions after force logout: {$afterCount}\n\n";

    echo "All sessions for user '123' have been invalidated.\n";
    echo "User will be prompted to login again on next request.\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * シナリオ6: セッション分離の確認
 * Scenario 6: Session Isolation Verification
 */
echo "--- Scenario 6: Session Isolation Verification ---\n\n";

try {
    echo "Creating sessions for different users...\n\n";

    // ユーザー456のセッション
    $connection->set('user456_session1', serialize(['user' => '456']), 3600);
    $connection->set('user456_session2', serialize(['user' => '456']), 3600);
    echo "Created 2 sessions for user '456'\n";

    // ユーザー789のセッション
    $connection->set('user789_session1', serialize(['user' => '789']), 3600);
    echo "Created 1 session for user '789'\n\n";

    echo "Session counts:\n";
    echo "- User '123': " . $helper->countUserSessions('123') . " sessions\n";
    echo "- User '456': " . $helper->countUserSessions('456') . " sessions\n";
    echo "- User '789': " . $helper->countUserSessions('789') . " sessions\n\n";

    echo "Force logout user '456'...\n";
    $helper->forceLogoutUser('456');

    echo "\nSession counts after force logout of user '456':\n";
    echo "- User '123': " . $helper->countUserSessions('123') . " sessions\n";
    echo "- User '456': " . $helper->countUserSessions('456') . " sessions (deleted)\n";
    echo "- User '789': " . $helper->countUserSessions('789') . " sessions (unaffected)\n\n";

    echo "Sessions are properly isolated between users!\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * クリーンアップ / Cleanup
 */
echo "--- Cleanup ---\n\n";

try {
    echo "Cleaning up test sessions...\n";

    // 全テストセッションを削除
    $allKeys = $connection->keys('*');
    foreach ($allKeys as $key) {
        $connection->delete($key);
    }

    $connection->disconnect();

    echo "Cleanup completed!\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

echo "=== Example completed successfully! ===\n";
echo "\n";
echo "Summary:\n";
echo "--------\n";
echo "This example demonstrated:\n";
echo "1. Anonymous sessions with 'anon_' prefix\n";
echo "2. Session ID regeneration on login (security best practice)\n";
echo "3. User-specific session ID format: 'user{userId}_{random}'\n";
echo "4. Managing multiple sessions for the same user\n";
echo "5. Session auditing capabilities\n";
echo "6. Force logout functionality for security/admin purposes\n";
echo "7. Session isolation between different users\n";
echo "\n";
echo "Security Notes:\n";
echo "- Always regenerate session ID on login to prevent session fixation attacks\n";
echo "- Session IDs are masked in logs (only last 4 chars shown)\n";
echo "- Force logout should be protected with proper admin authentication\n";
echo "- Redis SCAN is used (not KEYS) to avoid blocking in production\n";
