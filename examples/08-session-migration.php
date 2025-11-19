<?php

declare(strict_types=1);

/**
 * セッションマイグレーションの使用例 / Session Migration Example
 *
 * このサンプルは、セッションIDを指定のものにマイグレーションする方法を示します。
 * マイグレーション中のブラウザではセッションが途切れず、
 * 他のブラウザからはログアウトされます。
 *
 * This example demonstrates how to migrate session data to a new session ID.
 * The current browser maintains session continuity, while other browsers
 * using the old session ID will be logged out.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/08-session-migration.php
 * ```
 *
 * 前提条件 / Prerequisites:
 * - Redisサーバーがlocalhost:6379で起動していること
 * - Redis server running on localhost:6379
 *
 * 用途 / Use Cases:
 * - セキュリティ強化のためのセッションID再生成
 * - ユーザー認証後のセッション固定攻撃対策
 * - 既存セッションを特定のIDに移行
 * - Session ID regeneration for security enhancement
 * - Session fixation attack prevention after user authentication
 * - Migrating existing session to a specific ID
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Migration\SessionMigrationService;
use Uzulla\EnhancedRedisSessionHandler\Hook\SessionMigrationHook;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "=== Enhanced Redis Session Handler - Session Migration Example ===\n\n";

/**
 * 例1: SessionMigrationServiceを使用したマイグレーション
 * Example 1: Migration using SessionMigrationService
 */
echo "--- Example 1: Migration using SessionMigrationService ---\n\n";

try {
    $logger = new Logger('session-migration');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

    echo "1. Setting up Redis connection and session handler...\n";
    $redisConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:migration:'
    );

    $sessionConfig = new SessionConfig(
        $redisConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $oldSessionId = session_id();
    echo "2. Initial session started with ID: ...{$oldSessionId}\n\n";

    // Set some session data
    $_SESSION['user_id'] = 12345;
    $_SESSION['username'] = 'migration_test_user';
    $_SESSION['login_time'] = time();
    $_SESSION['roles'] = ['admin', 'editor'];

    echo "3. Session data set:\n";
    echo "   - user_id: " . $_SESSION['user_id'] . "\n";
    echo "   - username: " . $_SESSION['username'] . "\n";
    echo "   - login_time: " . date('Y-m-d H:i:s', $_SESSION['login_time']) . "\n";
    echo "   - roles: " . implode(', ', $_SESSION['roles']) . "\n\n";

    // Create a new session ID for migration
    $generator = new DefaultSessionIdGenerator();
    $newSessionId = $generator->generate();
    echo "4. Generated new session ID for migration: ...{$newSessionId}\n\n";

    // Create migration service
    $redis = new Redis();
    $connection = new RedisConnection($redis, $redisConfig, $logger);

    $migrationService = new SessionMigrationService(
        $connection,
        1440,
        $logger
    );

    echo "5. Performing session migration...\n";
    $migrationService->migrate($newSessionId, true);

    $currentSessionId = session_id();
    echo "\n6. Migration complete!\n";
    echo "   Old session ID: ...{$oldSessionId}\n";
    echo "   New session ID: ...{$currentSessionId}\n\n";

    // Verify session data is preserved
    echo "7. Verifying session data is preserved:\n";
    echo "   - user_id: " . $_SESSION['user_id'] . "\n";
    echo "   - username: " . $_SESSION['username'] . "\n";
    echo "   - login_time: " . date('Y-m-d H:i:s', $_SESSION['login_time']) . "\n";
    echo "   - roles: " . implode(', ', $_SESSION['roles']) . "\n\n";

    // Verify old session is deleted
    $oldSessionExists = $migrationService->sessionExists($oldSessionId);
    echo "8. Old session exists in Redis: " . ($oldSessionExists ? 'YES (unexpected)' : 'NO (expected - logged out other browsers)') . "\n\n";

    session_write_close();

    // Cleanup
    $connection->delete($newSessionId);
    echo "   Cleanup completed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例2: SessionMigrationHookを使用したマイグレーション
 * Example 2: Migration using SessionMigrationHook
 */
echo "--- Example 2: Migration using SessionMigrationHook ---\n\n";

try {
    $logger = new Logger('session-migration-hook');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

    echo "1. Setting up Redis connection with migration hook...\n";
    $redisConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:hook:'
    );

    $redis = new Redis();
    $connection = new RedisConnection($redis, $redisConfig, $logger);

    // Create migration hook
    $migrationHook = new SessionMigrationHook(
        $connection,
        1440,
        false, // Don't fail on migration error
        $logger
    );

    $sessionConfig = new SessionConfig(
        $redisConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    // Add the migration hook
    $sessionConfig->addWriteHook($migrationHook);

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $oldSessionId = session_id();
    echo "2. Session started with ID: ...{$oldSessionId}\n\n";

    // Set session data
    $_SESSION['product_id'] = 9876;
    $_SESSION['cart_items'] = ['item1', 'item2', 'item3'];
    $_SESSION['total'] = 299.99;

    echo "3. Session data set:\n";
    echo "   - product_id: " . $_SESSION['product_id'] . "\n";
    echo "   - cart_items: " . implode(', ', $_SESSION['cart_items']) . "\n";
    echo "   - total: $" . $_SESSION['total'] . "\n\n";

    // Set migration target
    $generator = new DefaultSessionIdGenerator();
    $newSessionId = $generator->generate();
    echo "4. Setting migration target to: ...{$newSessionId}\n";
    $migrationHook->setMigrationTarget($newSessionId, true);

    echo "   Migration pending: " . ($migrationHook->hasPendingMigration() ? 'YES' : 'NO') . "\n\n";

    echo "5. Closing session (migration will occur during write)...\n";
    session_write_close();

    echo "\n6. Migration via hook complete!\n";
    echo "   - Session data has been copied to new session ID\n";
    echo "   - Old session has been deleted\n";
    echo "   - Migration pending: " . ($migrationHook->hasPendingMigration() ? 'YES' : 'NO (cleared after migration)') . "\n\n";

    // Verify by reading the new session
    $newSessionData = $connection->get($newSessionId);
    if ($newSessionData !== false) {
        $data = unserialize($newSessionData);
        echo "7. Verifying data in new session:\n";
        echo "   - product_id: " . ($data['product_id'] ?? 'N/A') . "\n";
        echo "   - cart_items: " . (isset($data['cart_items']) ? implode(', ', $data['cart_items']) : 'N/A') . "\n";
        echo "   - total: $" . ($data['total'] ?? 'N/A') . "\n\n";
    }

    // Cleanup
    $connection->delete($newSessionId);
    echo "   Cleanup completed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例3: copy()を使用したセッションデータのコピー
 * Example 3: Session data copy using copy()
 */
echo "--- Example 3: Session data copy ---\n\n";

try {
    $logger = new Logger('session-copy');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

    echo "1. Setting up Redis connection...\n";
    $redisConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:copy:'
    );

    $redis = new Redis();
    $connection = new RedisConnection($redis, $redisConfig, $logger);

    // Create source session data directly in Redis
    $sourceSessionId = bin2hex(random_bytes(16));
    $sourceData = serialize([
        'user_id' => 54321,
        'preferences' => ['theme' => 'dark', 'language' => 'ja'],
    ]);
    $connection->set($sourceSessionId, $sourceData, 1440);

    echo "2. Source session created: ...{$sourceSessionId}\n\n";

    // Create migration service
    $migrationService = new SessionMigrationService(
        $connection,
        1440,
        $logger
    );

    // Copy to a new session ID
    $targetSessionId = bin2hex(random_bytes(16));
    echo "3. Copying session data to: ...{$targetSessionId}\n";
    $migrationService->copy($sourceSessionId, $targetSessionId, false);

    echo "\n4. Session copy complete!\n";
    echo "   Source session exists: " . ($migrationService->sessionExists($sourceSessionId) ? 'YES' : 'NO') . "\n";
    echo "   Target session exists: " . ($migrationService->sessionExists($targetSessionId) ? 'YES' : 'NO') . "\n\n";

    // Verify target data
    $targetData = $connection->get($targetSessionId);
    if ($targetData !== false) {
        $data = unserialize($targetData);
        echo "5. Target session data:\n";
        echo "   - user_id: " . ($data['user_id'] ?? 'N/A') . "\n";
        echo "   - theme: " . ($data['preferences']['theme'] ?? 'N/A') . "\n";
        echo "   - language: " . ($data['preferences']['language'] ?? 'N/A') . "\n\n";
    }

    // Cleanup
    $connection->delete($sourceSessionId);
    $connection->delete($targetSessionId);
    echo "   Cleanup completed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

echo "=== Example completed successfully! ===\n";
echo "\nNote: Session migration is useful for:\n";
echo "- Preventing session fixation attacks after login\n";
echo "- Forcing logout on other devices while keeping current session\n";
echo "- Migrating sessions during system upgrades\n";
