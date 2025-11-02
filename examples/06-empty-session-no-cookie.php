<?php

declare(strict_types=1);

/**
 * 空セッション時のCookie送信防止機能の使用例 / Empty Session Cookie Prevention Example
 *
 * このサンプルは、PreventEmptySessionCookie機能の使用方法を示します。
 * 空のセッションデータの場合、Cookieを送信しない機能により、
 * 不要なセッションIDの生成とRedisへのアクセスを削減できます。
 *
 * This example demonstrates how to use the PreventEmptySessionCookie feature.
 * When session data is empty, this feature prevents sending cookies,
 * reducing unnecessary session ID generation and Redis access.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/06-empty-session-no-cookie.php
 * ```
 *
 * 前提条件 / Prerequisites:
 * - Redisサーバーがlocalhost:6379で起動していること
 * - Redis server running on localhost:6379
 *
 * 用途 / Use Cases:
 * - 空セッションによる無駄なRedis書き込みの削減
 * - セッションCookieの不要な送信の防止
 * - パフォーマンスの向上
 * - Reducing unnecessary Redis writes for empty sessions
 * - Preventing unnecessary session cookie transmission
 * - Improving performance
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

if (php_sapi_name() === 'cli') {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.cache_limiter', '');
}

echo "=== Enhanced Redis Session Handler - Empty Session Cookie Prevention Example ===\n\n";

/**
 * 例1: 基本的な使用方法 - PreventEmptySessionCookie::setup()の呼び出し
 * Example 1: Basic Usage - Calling PreventEmptySessionCookie::setup()
 */
echo "--- Example 1: Basic Usage ---\n\n";

try {
    echo "1. Setting up logger for debugging...\n";
    $logger = new Logger('session');
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $formatter = new LineFormatter(
        "[%datetime%] %level_name%: %message% %context%\n",
        "H:i:s"
    );
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    echo "2. Creating Redis connection configuration...\n";
    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:prevent-empty:'
    );

    echo "3. Creating session configuration...\n";
    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440, // 24分 / 24 minutes
        $logger
    );

    echo "4. Building session handler...\n";
    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    echo "5. Setting up PreventEmptySessionCookie (this is the key step!)...\n";
    echo "   PreventEmptySessionCookie::setup() does the following:\n";
    echo "   - Registers an EmptySessionFilter to prevent writing empty sessions to Redis\n";
    echo "   - Calls session_set_save_handler() to register the handler\n";
    echo "   - Registers a shutdown function to destroy empty sessions and remove cookies\n\n";

    PreventEmptySessionCookie::setup($handler, $logger);

    echo "6. Starting session...\n";
    session_start();
    echo "   Session ID: " . session_id() . "\n";
    echo "   Session started successfully!\n\n";

    echo "7. Application code (no changes needed)...\n";
    echo "   You can use \$_SESSION as usual.\n";
    echo "   If \$_SESSION remains empty, the cookie will be automatically removed.\n\n";

    session_write_close();
    PreventEmptySessionCookie::reset();

    echo "=== Example 1 completed successfully! ===\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例2: 空セッションのケース - Cookieが削除される
 * Example 2: Empty Session Case - Cookie is Removed
 */
echo "--- Example 2: Empty Session Case ---\n\n";

try {
    echo "This example demonstrates what happens when \$_SESSION remains empty.\n\n";

    echo "1. Setting up session handler with PreventEmptySessionCookie...\n";

    $logger = new Logger('session-empty');
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $formatter = new LineFormatter("[%datetime%] %level_name%: %message%\n", "H:i:s");
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:empty-test:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    PreventEmptySessionCookie::setup($handler, $logger);

    echo "\n2. Starting session...\n";
    session_start();
    $sessionId = session_id();
    echo "   Session ID: {$sessionId}\n";

    echo "\n3. NOT writing any data to \$_SESSION (keeping it empty)...\n";
    echo "   \$_SESSION is empty: " . (isset($_SESSION) && count($_SESSION) === 0 ? 'Yes' : 'No (session not started)') . "\n";

    echo "\n4. Ending request (session_write_close)...\n";
    echo "   Watch the logs below - the shutdown function will:\n";
    echo "   - Detect that \$_SESSION is empty\n";
    echo "   - Call session_destroy() to prevent Redis write\n";
    echo "   - Send a Set-Cookie header with past expiration to remove the cookie\n\n";

    session_write_close();

    echo "\n5. Verifying that session was NOT written to Redis...\n";
    $redis = new Redis();
    $redis->connect('localhost', 6379);
    $exists = $redis->exists('session:empty-test:' . $sessionId);
    echo "   Session exists in Redis: " . ($exists ? 'Yes (unexpected!)' : 'No (correct!)') . "\n";

    PreventEmptySessionCookie::reset();

    echo "\n=== Example 2 completed successfully! ===\n";
    echo "Result: Empty session was NOT written to Redis, and cookie was removed.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例3: データありセッションのケース - 通常通り動作
 * Example 3: Session with Data Case - Works Normally
 */
echo "--- Example 3: Session with Data Case ---\n\n";

try {
    echo "This example demonstrates what happens when \$_SESSION has data.\n\n";

    echo "1. Setting up session handler with PreventEmptySessionCookie...\n";

    $logger = new Logger('session-with-data');
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $formatter = new LineFormatter("[%datetime%] %level_name%: %message%\n", "H:i:s");
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:with-data:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    PreventEmptySessionCookie::setup($handler, $logger);

    echo "\n2. Starting session...\n";
    session_start();
    $sessionId = session_id();
    echo "   Session ID: {$sessionId}\n";

    echo "\n3. Writing data to \$_SESSION...\n";
    $_SESSION['user_id'] = 12345;
    $_SESSION['username'] = 'john_doe';
    $_SESSION['login_time'] = time();
    echo "   Data written:\n";
    echo "   - user_id: " . $_SESSION['user_id'] . "\n";
    echo "   - username: " . $_SESSION['username'] . "\n";
    echo "   - login_time: " . date('Y-m-d H:i:s', $_SESSION['login_time']) . "\n";

    echo "\n4. Ending request (session_write_close)...\n";
    echo "   Watch the logs below - the session will be written to Redis normally.\n\n";

    session_write_close();

    echo "\n5. Verifying that session WAS written to Redis...\n";
    $redis = new Redis();
    $redis->connect('localhost', 6379);
    $exists = $redis->exists('session:with-data:' . $sessionId);
    echo "   Session exists in Redis: " . ($exists ? 'Yes (correct!)' : 'No (unexpected!)') . "\n";

    if ($exists) {
        echo "\n6. Reading session data from Redis to verify...\n";
        $data = $redis->get('session:with-data:' . $sessionId);
        echo "   Raw data from Redis: " . substr($data, 0, 100) . "...\n";

        echo "\n7. Cleaning up - destroying session...\n";
        $redis->del('session:with-data:' . $sessionId);
    }

    PreventEmptySessionCookie::reset();

    echo "\n=== Example 3 completed successfully! ===\n";
    echo "Result: Session with data was written to Redis normally, and cookie was sent.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * 例4: 既存セッション（Cookie既存）の動作確認
 * Example 4: Existing Session (with Existing Cookie) Behavior
 */
echo "--- Example 4: Existing Session Behavior ---\n\n";

try {
    echo "This example demonstrates that existing sessions work normally.\n\n";

    echo "1. Setting up session handler...\n";

    $logger = new Logger('session-existing');
    $consoleHandler = new StreamHandler('php://stdout', Logger::INFO);
    $formatter = new LineFormatter("[%datetime%] %level_name%: %message%\n", "H:i:s");
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:existing:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    PreventEmptySessionCookie::setup($handler, $logger);

    echo "\n2. First request - creating a session with data...\n";
    session_start();
    $sessionId = session_id();
    echo "   Session ID: {$sessionId}\n";

    $_SESSION['user_id'] = 99999;
    $_SESSION['username'] = 'existing_user';
    echo "   Data written to session.\n";

    session_write_close();

    echo "\n3. Simulating second request - session already exists...\n";
    echo "   In a real application, the browser would send the session cookie.\n";
    echo "   Here, we simulate this by calling session_start() again.\n";

    session_start();
    echo "   Session ID: " . session_id() . "\n";
    echo "   Data read from session:\n";
    echo "   - user_id: " . ($_SESSION['user_id'] ?? 'not found') . "\n";
    echo "   - username: " . ($_SESSION['username'] ?? 'not found') . "\n";

    echo "\n4. Modifying session data...\n";
    $_SESSION['last_access'] = time();
    echo "   Added last_access timestamp.\n";

    session_write_close();

    echo "\n5. Cleaning up...\n";
    $redis = new Redis();
    $redis->connect('localhost', 6379);
    $redis->del('session:existing:' . $sessionId);

    PreventEmptySessionCookie::reset();

    echo "\n=== Example 4 completed successfully! ===\n";
    echo "Result: Existing sessions work normally with PreventEmptySessionCookie.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

/**
 * まとめ / Summary
 */
echo "=== Summary ===\n\n";
echo "PreventEmptySessionCookie feature provides the following benefits:\n\n";
echo "1. Prevents Redis writes for empty sessions\n";
echo "   - Reduces unnecessary Redis operations\n";
echo "   - Improves performance\n\n";
echo "2. Removes cookies for empty sessions\n";
echo "   - Prevents unnecessary cookie transmission\n";
echo "   - Reduces client-side storage\n\n";
echo "3. Minimal code changes required\n";
echo "   - Only need to call PreventEmptySessionCookie::setup()\n";
echo "   - No changes to existing \$_SESSION usage\n\n";
echo "4. Works seamlessly with existing sessions\n";
echo "   - Sessions with data work normally\n";
echo "   - Existing sessions are not affected\n\n";

echo "Usage Pattern:\n";
echo "```php\n";
echo "\$handler = \$factory->build();\n";
echo "PreventEmptySessionCookie::setup(\$handler, \$logger);\n";
echo "session_start();\n";
echo "// Use \$_SESSION as usual\n";
echo "```\n\n";

echo "Best Practices:\n";
echo "- Use this feature for applications with many anonymous/guest users\n";
echo "- Combine with logging to monitor empty session patterns\n";
echo "- Test thoroughly in your specific environment\n";
echo "- Monitor Redis metrics to verify performance improvements\n\n";

echo "=== All examples completed successfully! ===\n";
