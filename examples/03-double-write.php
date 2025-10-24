<?php

declare(strict_types=1);

/**
 * ダブルライトフックの使用例 / Double Write Hook Example
 *
 * このサンプルは、プライマリとセカンダリのRedisインスタンスに
 * セッションデータを同時に書き込む方法を示します。
 *
 * This example demonstrates how to write session data to both
 * primary and secondary Redis instances simultaneously.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/03-double-write.php
 * ```
 *
 * 前提条件 / Prerequisites:
 * - プライマリRedisサーバーがlocalhost:6379で起動していること
 * - セカンダリRedisサーバーがlocalhost:6380で起動していること
 *   (または同じRedisサーバーの異なるデータベースを使用)
 * - Primary Redis server running on localhost:6379
 * - Secondary Redis server running on localhost:6380
 *   (or use different database on the same Redis server)
 *
 * 用途 / Use Cases:
 * - セッションデータのバックアップ作成
 * - データセンター間でのセッションレプリケーション
 * - 新しいRedisインスタンスへのセッション移行
 * - Creating backup copies of session data
 * - Replicating sessions across data centers
 * - Migrating sessions to a new Redis instance
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Psr\Log\NullLogger;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "=== Enhanced Redis Session Handler - Double Write Hook Example ===\n\n";

/**
 * 例1: 基本的なダブルライト設定
 * Example 1: Basic Double Write Setup
 */
echo "--- Example 1: Basic Double Write Setup ---\n\n";

try {
    $logger = new Logger('double-write');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

    echo "1. Setting up primary Redis connection...\n";
    $primaryConfig = new RedisConnectionConfig(
        host: 'localhost',
        port: 6379,
        database: 0,
        prefix: 'session:primary:'
    );

    echo "2. Setting up secondary Redis connection...\n";
    $secondaryConfig = new RedisConnectionConfig(
        host: 'localhost',
        port: 6379,
        database: 1, // 異なるデータベース / Different database
        prefix: 'session:secondary:'
    );

    $secondaryRedis = new \Redis();
    $secondaryConnection = new RedisConnection(
        $secondaryRedis,
        $secondaryConfig,
        $logger
    );

    echo "3. Creating session configuration with double write hook...\n";
    $sessionConfig = new SessionConfig(
        connectionConfig: $primaryConfig,
        idGenerator: new DefaultSessionIdGenerator(),
        maxLifetime: 1440,
        logger: $logger
    );

    $doubleWriteHook = new DoubleWriteHook(
        secondaryConnection: $secondaryConnection,
        ttl: 1440,
        failOnSecondaryError: false, // セカンダリ書き込み失敗時にエラーを投げない
        logger: $logger
    );

    $sessionConfig->addWriteHook($doubleWriteHook);

    echo "4. Building session handler...\n";
    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    echo "5. Writing data to session...\n";
    $sessionId = session_id();
    echo "   Session ID: {$sessionId}\n\n";

    $_SESSION['user_id'] = 99999;
    $_SESSION['username'] = 'double_write_user';
    $_SESSION['action'] = 'testing_double_write';
    $_SESSION['timestamp'] = time();

    echo "   Data written:\n";
    echo "   - user_id: " . $_SESSION['user_id'] . "\n";
    echo "   - username: " . $_SESSION['username'] . "\n";
    echo "   - action: " . $_SESSION['action'] . "\n";
    echo "   - timestamp: " . date('Y-m-d H:i:s', $_SESSION['timestamp']) . "\n\n";

    echo "6. Saving session (will write to both primary and secondary Redis)...\n";
    session_write_close();

    echo "\n   Session data has been written to:\n";
    echo "   - Primary Redis: localhost:6379 (database 0)\n";
    echo "   - Secondary Redis: localhost:6379 (database 1)\n\n";

    echo "7. Verifying data in both Redis instances...\n";

    $primaryRedis = new \Redis();
    $primaryRedis->connect('localhost', 6379);
    $primaryRedis->select(0);
    $primaryData = $primaryRedis->get('session:primary:' . $sessionId);
    echo "   Primary Redis data exists: " . ($primaryData !== false ? 'YES' : 'NO') . "\n";

    $secondaryRedis = new \Redis();
    $secondaryRedis->connect('localhost', 6379);
    $secondaryRedis->select(1);
    $secondaryData = $secondaryRedis->get('session:secondary:' . $sessionId);
    echo "   Secondary Redis data exists: " . ($secondaryData !== false ? 'YES' : 'NO') . "\n\n";

    if ($primaryData !== false && $secondaryData !== false) {
        echo "   ✓ Data successfully written to both Redis instances!\n\n";
    }

    $primaryRedis->del('session:primary:' . $sessionId);
    $secondaryRedis->del('session:secondary:' . $sessionId);
    echo "   Cleanup completed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例2: セカンダリ書き込み失敗時のエラーハンドリング
 * Example 2: Error Handling on Secondary Write Failure
 */
echo "--- Example 2: Error Handling on Secondary Write Failure ---\n\n";

try {
    echo "Demonstrating behavior when secondary Redis is unavailable...\n\n";

    $logger = new Logger('double-write-error');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

    $primaryConfig = new RedisConnectionConfig(
        host: 'localhost',
        port: 6379,
        database: 0,
        prefix: 'session:primary:'
    );

    $secondaryConfig = new RedisConnectionConfig(
        host: 'localhost',
        port: 9999, // 存在しないポート / Non-existent port
        database: 0,
        prefix: 'session:secondary:',
        timeout: 0.5 // 短いタイムアウト / Short timeout
    );

    $secondaryRedis = new \Redis();
    $secondaryConnection = new RedisConnection(
        $secondaryRedis,
        $secondaryConfig,
        $logger
    );

    $sessionConfig = new SessionConfig(
        connectionConfig: $primaryConfig,
        idGenerator: new DefaultSessionIdGenerator(),
        maxLifetime: 1440,
        logger: $logger
    );

    $doubleWriteHook = new DoubleWriteHook(
        secondaryConnection: $secondaryConnection,
        ttl: 1440,
        failOnSecondaryError: false,
        logger: $logger
    );

    $sessionConfig->addWriteHook($doubleWriteHook);

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $_SESSION['test'] = 'error_handling';
    $_SESSION['timestamp'] = time();

    echo "Writing session data...\n";
    echo "Expected: Primary write succeeds, secondary write fails (but doesn't throw error)\n\n";

    session_write_close();

    echo "✓ Session write completed successfully!\n";
    echo "  Primary Redis write: SUCCESS\n";
    echo "  Secondary Redis write: FAILED (but operation continued)\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
}

echo "=== Example completed successfully! ===\n";
echo "\nNote: This example demonstrates how DoubleWriteHook provides redundancy\n";
echo "and backup capabilities for session data across multiple Redis instances.\n";
