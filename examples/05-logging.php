<?php

declare(strict_types=1);

/**
 * ロギング機能の使用例 / Logging Functionality Example
 *
 * このサンプルは、Monologを使用したセッション操作のロギング方法を示します。
 * This example demonstrates how to log session operations using Monolog.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/05-logging.php
 * ```
 *
 * 前提条件 / Prerequisites:
 * - Redisサーバーがlocalhost:6379で起動していること
 * - Redis server running on localhost:6379
 *
 * 用途 / Use Cases:
 * - セッション問題のデバッグ
 * - セッションアクセスの監査
 * - セッションアクティビティの監視
 * - パフォーマンス分析
 * - Debugging session issues
 * - Auditing session access
 * - Monitoring session activity
 * - Performance analysis
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

echo "=== Enhanced Redis Session Handler - Logging Example ===\n\n";

/**
 * 例1: 基本的なロギング設定
 * Example 1: Basic Logging Setup
 */
echo "--- Example 1: Basic Logging Setup ---\n\n";

try {
    echo "1. Setting up logger with console output...\n";

    $logger = new Logger('session');
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);

    $formatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context%\n",
        "Y-m-d H:i:s"
    );
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    echo "2. Creating session configuration with logging...\n";

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        0,
        'session:logged:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $loggingHook = new LoggingHook(
        $logger,
        LogLevel::INFO,
        LogLevel::INFO,
        LogLevel::ERROR,
        false // セキュリティのため、デフォルトではデータをログに記録しない
    );

    $sessionConfig->addWriteHook($loggingHook);

    echo "3. Building session handler...\n\n";

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);

    echo "--- Session Operations (watch the logs below) ---\n\n";

    echo "4. Starting session...\n";
    session_start();
    $sessionId = session_id();
    echo "   Session ID: {$sessionId}\n\n";

    echo "5. Writing data to session...\n";
    $_SESSION['user_id'] = 12345;
    $_SESSION['username'] = 'logger_user';
    $_SESSION['login_time'] = time();

    echo "6. Saving session...\n";
    session_write_close();

    echo "\n7. Session operations completed.\n\n";

    $redis = new \Redis();
    $redis->connect('localhost', 6379);
    $redis->del('session:logged:' . $sessionId);
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * 例2: ファイルへのロギング
 * Example 2: Logging to File
 */
echo "--- Example 2: Logging to File ---\n\n";

try {
    echo "1. Setting up logger with file output...\n";

    $logDir = sys_get_temp_dir() . '/session-logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/session.log';
    echo "   Log file: {$logFile}\n\n";

    $logger = new Logger('session-file');

    $fileHandler = new RotatingFileHandler($logFile, 7, Logger::DEBUG);
    $formatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context%\n",
        "Y-m-d H:i:s"
    );
    $fileHandler->setFormatter($formatter);
    $logger->pushHandler($fileHandler);

    $consoleHandler = new StreamHandler('php://stdout', Logger::INFO);
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    echo "2. Creating session configuration...\n";

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        0,
        'session:file-logged:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $loggingHook = new LoggingHook(
        $logger,
        LogLevel::DEBUG,
        LogLevel::DEBUG,
        LogLevel::ERROR,
        false
    );

    $sessionConfig->addWriteHook($loggingHook);

    echo "3. Performing session operations...\n\n";

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $_SESSION['action'] = 'file_logging_test';
    $_SESSION['timestamp'] = time();

    session_write_close();

    echo "\n4. Session operations logged to file: {$logFile}\n";

    if (file_exists($logFile)) {
        echo "\n--- Log File Contents ---\n";
        echo file_get_contents($logFile);
        echo "--- End of Log File ---\n\n";
    }
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * 例3: 詳細なロギング（セッションデータを含む）
 * Example 3: Detailed Logging (Including Session Data)
 */
echo "--- Example 3: Detailed Logging (Including Session Data) ---\n\n";

try {
    echo "WARNING: This example logs session data, which may contain sensitive information.\n";
    echo "Only use this in development/debugging environments!\n\n";

    echo "1. Setting up detailed logger...\n";

    $logger = new Logger('session-detailed');
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $formatter = new LineFormatter(
        "[%datetime%] %level_name%: %message% %context%\n",
        "Y-m-d H:i:s"
    );
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    echo "2. Creating session configuration with detailed logging...\n";

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        0,
        'session:detailed:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $loggingHook = new LoggingHook(
        $logger,
        LogLevel::DEBUG,
        LogLevel::DEBUG,
        LogLevel::ERROR,
        true // ⚠️ 本番環境では使用しないこと / DO NOT use in production
    );

    $sessionConfig->addWriteHook($loggingHook);

    echo "3. Performing session operations with detailed logging...\n\n";

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $_SESSION['user_id'] = 99999;
    $_SESSION['username'] = 'detailed_log_user';
    $_SESSION['permissions'] = ['read', 'write'];
    $_SESSION['metadata'] = [
        'ip_address' => '192.168.1.100',
        'user_agent' => 'Mozilla/5.0',
        'login_time' => time(),
    ];

    echo "--- Watch the detailed logs below ---\n\n";

    session_write_close();

    echo "\n4. Detailed logging completed.\n";
    echo "   Notice how the logs include session data keys and values.\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * 例4: 複数のログハンドラ（コンソール + ファイル + エラー専用ファイル）
 * Example 4: Multiple Log Handlers (Console + File + Error-only File)
 */
echo "--- Example 4: Multiple Log Handlers ---\n\n";

try {
    echo "1. Setting up logger with multiple handlers...\n";

    $logDir = sys_get_temp_dir() . '/session-logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logger = new Logger('session-multi');

    $consoleHandler = new StreamHandler('php://stdout', Logger::INFO);
    $formatter = new LineFormatter("[%datetime%] %level_name%: %message%\n", "H:i:s");
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    $allLogsFile = $logDir . '/all.log';
    $allLogsHandler = new StreamHandler($allLogsFile, Logger::DEBUG);
    $logger->pushHandler($allLogsHandler);

    $errorLogsFile = $logDir . '/errors.log';
    $errorLogsHandler = new StreamHandler($errorLogsFile, Logger::ERROR);
    $logger->pushHandler($errorLogsHandler);

    echo "   Console: INFO and above\n";
    echo "   All logs: {$allLogsFile}\n";
    echo "   Error logs: {$errorLogsFile}\n\n";

    echo "2. Creating session configuration...\n";

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        0,
        'session:multi:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new DefaultSessionIdGenerator(),
        1440,
        $logger
    );

    $loggingHook = new LoggingHook(
        $logger,
        LogLevel::DEBUG,
        LogLevel::INFO,
        LogLevel::ERROR,
        false
    );

    $sessionConfig->addWriteHook($loggingHook);

    echo "3. Performing session operations...\n\n";

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $_SESSION['test'] = 'multi_handler_logging';
    $_SESSION['timestamp'] = time();

    session_write_close();

    echo "\n4. Logs written to multiple destinations:\n";
    echo "   - Console (you can see INFO level logs above)\n";
    echo "   - All logs file (includes DEBUG level)\n";
    echo "   - Error logs file (only if errors occur)\n\n";
} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
}

echo "=== Example completed successfully! ===\n";
echo "\nNote: This example demonstrates various logging configurations\n";
echo "for monitoring and debugging session operations.\n";
echo "\nBest Practices:\n";
echo "- Use DEBUG level in development for detailed information\n";
echo "- Use INFO level in production for important events\n";
echo "- Never log sensitive session data (passwords, tokens) in production\n";
echo "- Use rotating file handlers to manage log file sizes\n";
echo "- Separate error logs for easier troubleshooting\n";
