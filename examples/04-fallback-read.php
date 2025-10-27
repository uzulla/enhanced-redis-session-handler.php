<?php

declare(strict_types=1);

/**
 * フォールバック読み込みの使用例 / Fallback Read Hook Example
 *
 * このサンプルは、プライマリRedisが利用できない場合に
 * セカンダリRedisからセッションデータを読み込む方法を示します。
 *
 * This example demonstrates how to read session data from secondary
 * Redis instances when the primary Redis is unavailable.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/04-fallback-read.php
 * ```
 *
 * 前提条件 / Prerequisites:
 * - プライマリRedisサーバーがlocalhost:6379で起動していること
 * - フォールバックRedisサーバーがlocalhost:6380で起動していること
 *   (または同じRedisサーバーの異なるデータベースを使用)
 * - Primary Redis server running on localhost:6379
 * - Fallback Redis server running on localhost:6380
 *   (or use different database on the same Redis server)
 *
 * 用途 / Use Cases:
 * - 高可用性セッション管理
 * - Redis障害時の自動フェイルオーバー
 * - 複数データセンター構成での冗長性
 * - High availability session management
 * - Automatic failover on Redis failure
 * - Redundancy in multi-datacenter configurations
 */

require_once __DIR__ . '/../vendor/autoload.php';


use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Hook\FallbackReadHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Psr\Log\NullLogger;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "=== Enhanced Redis Session Handler - Fallback Read Hook Example ===\n\n";

/**
 * 例1: 基本的なフォールバック読み込み設定
 * Example 1: Basic Fallback Read Setup
 */
echo "--- Example 1: Basic Fallback Read Setup ---\n\n";

try {
    $logger = new Logger('fallback-read');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

    echo "1. Setting up primary Redis connection...\n";
    $primaryConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:primary:'
    );

    echo "2. Setting up fallback Redis connections...\n";
    $fallback1Config = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        1,
        'session:fallback1:'
    );

    $fallback1Redis = new Redis();
    $fallback1Connection = new RedisConnection(
        $fallback1Redis,
        $fallback1Config,
        $logger
    );

    $fallback2Config = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        2,
        'session:fallback2:'
    );

    $fallback2Redis = new Redis();
    $fallback2Connection = new RedisConnection(
        $fallback2Redis,
        $fallback2Config,
        $logger
    );

    echo "3. Creating session configuration with fallback read hook...\n";
    $sessionConfig = new SessionConfig(
        $primaryConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $fallbackReadHook = new FallbackReadHook(
        [$fallback1Connection, $fallback2Connection],
        $logger
    );

    $sessionConfig->addReadHook($fallbackReadHook);

    $doubleWrite1 = new DoubleWriteHook(
        $fallback1Connection,
        1440,
        false,
        $logger
    );

    $doubleWrite2 = new DoubleWriteHook(
        $fallback2Connection,
        1440,
        false,
        $logger
    );

    $sessionConfig->addWriteHook($doubleWrite1);
    $sessionConfig->addWriteHook($doubleWrite2);

    echo "4. Building session handler...\n";
    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $sessionId = session_id();
    echo "5. Writing data to session...\n";
    echo "   Session ID: {$sessionId}\n\n";

    $_SESSION['user_id'] = 77777;
    $_SESSION['username'] = 'fallback_test_user';
    $_SESSION['feature'] = 'high_availability';
    $_SESSION['timestamp'] = time();

    echo "   Data written:\n";
    echo "   - user_id: " . $_SESSION['user_id'] . "\n";
    echo "   - username: " . $_SESSION['username'] . "\n";
    echo "   - feature: " . $_SESSION['feature'] . "\n";
    echo "   - timestamp: " . date('Y-m-d H:i:s', $_SESSION['timestamp']) . "\n\n";

    session_write_close();

    echo "6. Session data has been written to all Redis instances:\n";
    echo "   - Primary Redis: localhost:6379 (database 0)\n";
    echo "   - Fallback 1 Redis: localhost:6379 (database 1)\n";
    echo "   - Fallback 2 Redis: localhost:6379 (database 2)\n\n";

    echo "7. Verifying data in all Redis instances...\n";

    $redis0 = new Redis();
    $redis0->connect('localhost', 6379);
    $redis0->select(0);
    $data0 = $redis0->get('session:primary:' . $sessionId);
    echo "   Primary Redis (db 0): " . ($data0 !== false ? 'EXISTS' : 'NOT FOUND') . "\n";

    $redis1 = new Redis();
    $redis1->connect('localhost', 6379);
    $redis1->select(1);
    $data1 = $redis1->get('session:fallback1:' . $sessionId);
    echo "   Fallback 1 Redis (db 1): " . ($data1 !== false ? 'EXISTS' : 'NOT FOUND') . "\n";

    $redis2 = new Redis();
    $redis2->connect('localhost', 6379);
    $redis2->select(2);
    $data2 = $redis2->get('session:fallback2:' . $sessionId);
    echo "   Fallback 2 Redis (db 2): " . ($data2 !== false ? 'EXISTS' : 'NOT FOUND') . "\n\n";

    echo "8. Testing fallback mechanism...\n";
    echo "   Deleting data from primary Redis...\n";
    $redis0->del('session:primary:' . $sessionId);

    echo "   Simulating new request (primary Redis is empty)...\n";
    session_start();

    echo "   Reading session data...\n";
    if (isset($_SESSION['user_id'])) {
        echo "   ✓ Successfully read from fallback Redis!\n";
        echo "   - user_id: " . $_SESSION['user_id'] . "\n";
        echo "   - username: " . $_SESSION['username'] . "\n";
        echo "   - feature: " . $_SESSION['feature'] . "\n\n";
    } else {
        echo "   ✗ Failed to read from fallback Redis\n\n";
    }

    session_write_close();

    echo "9. Cleanup...\n";
    $redis0->del('session:primary:' . $sessionId);
    $redis1->del('session:fallback1:' . $sessionId);
    $redis2->del('session:fallback2:' . $sessionId);
    echo "   Cleanup completed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例2: 複数フォールバックの優先順位
 * Example 2: Priority Order of Multiple Fallbacks
 */
echo "--- Example 2: Priority Order of Multiple Fallbacks ---\n\n";

try {
    echo "Demonstrating fallback priority order...\n\n";

    $logger = new Logger('fallback-priority');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

    $testSessionId = 'test_' . bin2hex(random_bytes(16));

    echo "Test Session ID: {$testSessionId}\n\n";

    echo "1. Writing different data to each Redis instance...\n";

    $redis0 = new Redis();
    $redis0->connect('localhost', 6379);
    $redis0->select(0);

    $redis1 = new Redis();
    $redis1->connect('localhost', 6379);
    $redis1->select(1);
    $data1 = serialize(['source' => 'fallback1', 'priority' => 1]);
    $redis1->setex('session:fallback1:' . $testSessionId, 300, $data1);
    echo "   Fallback 1 (db 1): Written with priority 1\n";

    $redis2 = new Redis();
    $redis2->connect('localhost', 6379);
    $redis2->select(2);
    $data2 = serialize(['source' => 'fallback2', 'priority' => 2]);
    $redis2->setex('session:fallback2:' . $testSessionId, 300, $data2);
    echo "   Fallback 2 (db 2): Written with priority 2\n\n";

    echo "2. Testing fallback read (primary is empty)...\n";

    $primaryConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:primary:'
    );

    $fallback1Config = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        1,
        'session:fallback1:'
    );

    $fallback1Redis = new Redis();
    $fallback1Connection = new RedisConnection(
        $fallback1Redis,
        $fallback1Config,
        $logger
    );

    $fallback2Config = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        2,
        'session:fallback2:'
    );

    $fallback2Redis = new Redis();
    $fallback2Connection = new RedisConnection(
        $fallback2Redis,
        $fallback2Config,
        $logger
    );

    $fallbackReadHook = new FallbackReadHook(
        [$fallback1Connection, $fallback2Connection],
        $logger
    );

    $sessionConfig = new SessionConfig(
        $primaryConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $sessionConfig->addReadHook($fallbackReadHook);

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_id($testSessionId);
    session_start();

    echo "   Reading session data...\n";
    if (isset($_SESSION['source'])) {
        echo "   ✓ Data read from: " . $_SESSION['source'] . "\n";
        echo "   ✓ Priority: " . $_SESSION['priority'] . "\n";
        echo "   Expected: fallback1 (priority 1) because it's checked first\n\n";
    }

    session_write_close();

    echo "3. Cleanup...\n";
    $redis0->del('session:primary:' . $testSessionId);
    $redis1->del('session:fallback1:' . $testSessionId);
    $redis2->del('session:fallback2:' . $testSessionId);
    echo "   Cleanup completed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
}

echo "=== Example completed successfully! ===\n";
echo "\nNote: This example demonstrates how FallbackReadHook provides high availability\n";
echo "by automatically reading from backup Redis instances when the primary is unavailable.\n";
