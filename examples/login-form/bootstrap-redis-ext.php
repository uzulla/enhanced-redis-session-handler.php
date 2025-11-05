<?php

declare(strict_types=1);

/**
 * Redis拡張を使用したセッションハンドラー設定
 * Session handler setup using Redis extension
 *
 * このファイルは標準のRedis拡張のセッションハンドラーを設定します。
 * This file configures the standard Redis extension session handler.
 *
 * 互換性テスト用：このハンドラーで作成したセッションデータが
 * enhanced-redis-session-handlerでも正しく読み込めることを確認します。
 *
 * For compatibility testing: Verify that session data created with this handler
 * can be correctly read by enhanced-redis-session-handler.
 */

require_once __DIR__ . '/config.php';

// Redis拡張が利用可能か確認 / Check if Redis extension is available
if (!extension_loaded('redis')) {
    die('Redis extension is not loaded. Please install php-redis extension.');
}

// セッション名を設定 / Set session name
session_name(SESSION_NAME);

// session.serialize_handlerを'php'に設定（enhanced-redis-session-handlerと互換性を持たせる）
// Set session.serialize_handler to 'php' for compatibility with enhanced-redis-session-handler
ini_set('session.serialize_handler', 'php');

// Redis拡張のセッションハンドラーを使用
// Use Redis extension session handler
ini_set('session.save_handler', 'redis');

// Redis接続文字列を構築
// Build Redis connection string
$redisUrl = sprintf(
    'tcp://%s:%d?database=%d&prefix=%s',
    REDIS_HOST,
    REDIS_PORT,
    REDIS_DATABASE,
    REDIS_KEY_PREFIX
);

ini_set('session.save_path', $redisUrl);

// セッションのライフタイムを設定 / Set session lifetime
ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

// セッション開始 / Start session
session_start();

// セッションにハンドラー名を記録（デバッグ用）
// Record handler name in session (for debugging)
if (!isset($_SESSION['_handler_name'])) {
    $_SESSION['_handler_name'] = 'redis-ext';
}

// セッション開始時刻を記録（初回のみ）
// Record session start time (first time only)
if (!isset($_SESSION['_started_at'])) {
    $_SESSION['_started_at'] = date('Y-m-d H:i:s');
}

// 最終アクセス時刻を更新
// Update last access time
$_SESSION['_last_access'] = date('Y-m-d H:i:s');
