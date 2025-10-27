<?php

declare(strict_types=1);

/**
 * 基本的な使用例 / Basic Usage Example
 *
 * このサンプルは、enhanced-redis-session-handlerの最もシンプルな使用方法を示します。
 * This example demonstrates the simplest way to use enhanced-redis-session-handler.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/01-basic-usage.php
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
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Psr\Log\NullLogger;

echo "=== Enhanced Redis Session Handler - Basic Usage Example ===\n\n";

try {
    echo "1. Creating Redis connection configuration...\n";
    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:'
    );

    echo "2. Creating session configuration...\n";
    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(), // Use php_serialize format (default)
        new DefaultSessionIdGenerator(),
        1440, // 24分 / 24 minutes
        new NullLogger()
    );

    echo "3. Building session handler...\n";
    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    echo "4. Registering session handler...\n";
    session_set_save_handler($handler, true);

    echo "5. Starting session...\n";
    session_start();

    echo "   Session ID: " . session_id() . "\n\n";

    echo "6. Writing data to session...\n";
    $_SESSION['user_id'] = 12345;
    $_SESSION['username'] = 'john_doe';
    $_SESSION['login_time'] = time();
    $_SESSION['preferences'] = [
        'theme' => 'dark',
        'language' => 'ja',
        'notifications' => true,
    ];

    echo "   Data written:\n";
    echo "   - user_id: " . $_SESSION['user_id'] . "\n";
    echo "   - username: " . $_SESSION['username'] . "\n";
    echo "   - login_time: " . date('Y-m-d H:i:s', $_SESSION['login_time']) . "\n";
    echo "   - preferences: " . json_encode($_SESSION['preferences']) . "\n\n";

    echo "7. Saving and closing session...\n";
    session_write_close();

    echo "   Session saved successfully!\n\n";

    echo "8. Simulating new request - reloading session...\n";
    $sessionId = session_id();
    session_start();

    echo "   Session ID: " . session_id() . "\n";
    echo "   Data read from session:\n";
    echo "   - user_id: " . ($_SESSION['user_id'] ?? 'not found') . "\n";
    echo "   - username: " . ($_SESSION['username'] ?? 'not found') . "\n";
    echo "   - login_time: " . (isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'not found') . "\n";
    echo "   - preferences: " . (isset($_SESSION['preferences']) ? json_encode($_SESSION['preferences']) : 'not found') . "\n\n";

    echo "9. Destroying session...\n";
    session_destroy();
    echo "   Session destroyed successfully!\n\n";

    echo "=== Example completed successfully! ===\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
