<?php

declare(strict_types=1);

/**
 * カスタムセッションIDジェネレータの使用例 / Custom Session ID Generator Example
 *
 * このサンプルは、カスタムセッションIDジェネレータの使用方法を示します。
 * This example demonstrates how to use custom session ID generators.
 *
 * 実行方法 / How to run:
 * ```bash
 * php examples/02-custom-session-id.php
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
use Uzulla\EnhancedRedisSessionHandler\SessionId\PrefixedSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\TimestampPrefixedSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Psr\Log\NullLogger;

echo "=== Enhanced Redis Session Handler - Custom Session ID Generator Example ===\n\n";

/**
 * 例1: プレフィックス付きセッションIDジェネレータ
 * Example 1: Prefixed Session ID Generator
 */
echo "--- Example 1: Prefixed Session ID Generator ---\n\n";

try {
    echo "Creating session handler with prefixed session ID generator...\n";

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:prefixed:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        new PrefixedSessionIdGenerator('myapp', 32),
        1440,
        new NullLogger()
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $sessionId = session_id();
    echo "Generated Session ID: {$sessionId}\n";
    echo "Notice the 'myapp_' prefix in the session ID\n\n";

    $_SESSION['example'] = 'prefixed_session';
    $_SESSION['timestamp'] = time();

    echo "Data written to session:\n";
    echo "- example: " . $_SESSION['example'] . "\n";
    echo "- timestamp: " . date('Y-m-d H:i:s', $_SESSION['timestamp']) . "\n\n";

    session_write_close();
    echo "Session saved successfully!\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * 例2: タイムスタンプ付きセッションIDジェネレータ
 * Example 2: Timestamp Prefixed Session ID Generator
 */
echo "--- Example 2: Timestamp Prefixed Session ID Generator ---\n\n";

try {
    echo "Creating session handler with timestamp-prefixed session ID generator...\n";

    $connectionConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:timestamped:'
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new PhpSerializeSerializer(),
        new TimestampPrefixedSessionIdGenerator(32),
        1440,
        new NullLogger()
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();

    session_set_save_handler($handler, true);
    session_start();

    $sessionId = session_id();
    echo "Generated Session ID: {$sessionId}\n";

    $parts = explode('_', $sessionId);
    if (count($parts) >= 2) {
        $timestamp = (int)$parts[0];
        echo "Session created at: " . date('Y-m-d H:i:s', $timestamp) . "\n";
        echo "Notice the Unix timestamp prefix in the session ID\n\n";
    }

    $_SESSION['example'] = 'timestamped_session';
    $_SESSION['created_at'] = time();

    echo "Data written to session:\n";
    echo "- example: " . $_SESSION['example'] . "\n";
    echo "- created_at: " . date('Y-m-d H:i:s', $_SESSION['created_at']) . "\n\n";

    session_write_close();
    echo "Session saved successfully!\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

/**
 * 例3: 複数のアプリケーションで異なるプレフィックスを使用
 * Example 3: Using different prefixes for multiple applications
 */
echo "--- Example 3: Multiple Applications with Different Prefixes ---\n\n";

try {
    echo "Demonstrating how different applications can share the same Redis instance...\n\n";

    echo "Application 1: Admin Panel\n";
    $adminConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:admin:'
    );

    $adminSessionConfig = new SessionConfig(
        $adminConfig,
        new PhpSerializeSerializer(),
        new PrefixedSessionIdGenerator('admin', 32),
        1440,
        new NullLogger()
    );

    $adminFactory = new SessionHandlerFactory($adminSessionConfig);
    $adminHandler = $adminFactory->build();

    session_set_save_handler($adminHandler, true);
    session_start();

    $adminSessionId = session_id();
    echo "Admin Session ID: {$adminSessionId}\n";
    $_SESSION['role'] = 'administrator';
    $_SESSION['permissions'] = ['read', 'write', 'delete'];

    session_write_close();
    echo "Admin session saved\n\n";

    echo "Application 2: API\n";
    $apiConfig = new RedisConnectionConfig(
        'localhost',
        6379,
        2.5,
        null,
        0,
        'session:api:'
    );

    $apiSessionConfig = new SessionConfig(
        $apiConfig,
        new PhpSerializeSerializer(),
        new PrefixedSessionIdGenerator('api', 32),
        1440,
        new NullLogger()
    );

    $apiFactory = new SessionHandlerFactory($apiSessionConfig);
    $apiHandler = $apiFactory->build();

    session_set_save_handler($apiHandler, true);
    session_start();

    $apiSessionId = session_id();
    echo "API Session ID: {$apiSessionId}\n";
    $_SESSION['api_key'] = 'abc123xyz789';
    $_SESSION['rate_limit'] = 1000;

    session_write_close();
    echo "API session saved\n\n";

    echo "Both applications can use the same Redis instance without conflicts\n";
    echo "because they use different prefixes and session ID generators.\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
}

echo "=== Example completed successfully! ===\n";
