<?php

declare(strict_types=1);

/**
 * Enhanced Redis Session Handlerを使用したセッションハンドラー設定
 * Session handler setup using Enhanced Redis Session Handler
 *
 * このファイルはenhanced-redis-session-handlerを設定します。
 * This file configures the enhanced-redis-session-handler.
 *
 * 互換性テスト用：Redis拡張で作成したセッションデータを
 * このハンドラーで正しく読み込めることを確認します。
 *
 * For compatibility testing: Verify that session data created with Redis extension
 * can be correctly read by this handler.
 *
 * PreventEmptySessionCookie機能を使用し、空セッション時のCookie送信を防止します。
 * Uses PreventEmptySessionCookie feature to prevent cookie transmission for empty sessions.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializer;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;
use Psr\Log\NullLogger;

// session.serialize_handlerを'php'に設定（Redis拡張と互換性を持たせる）
// Set session.serialize_handler to 'php' for compatibility with Redis extension
ini_set('session.serialize_handler', 'php');

// セッション名を設定 / Set session name
session_name(SESSION_NAME);

// Redis接続設定を作成 / Create Redis connection config
$connectionConfig = new RedisConnectionConfig(
    REDIS_HOST,
    REDIS_PORT,
    2.5,
    null,
    REDIS_DATABASE,
    REDIS_KEY_PREFIX
);

// セッション設定を作成 / Create session config
// PhpSerializerを使用してRedis拡張との互換性を確保
// Use PhpSerializer for compatibility with Redis extension
$sessionConfig = new SessionConfig(
    $connectionConfig,
    new PhpSerializer(), // 'php' serialize handler
    new DefaultSessionIdGenerator(),
    SESSION_LIFETIME,
    new NullLogger()
);

// セッションハンドラーを構築 / Build session handler
$factory = new SessionHandlerFactory($sessionConfig);
$handler = $factory->build();

// PreventEmptySessionCookieを設定
// Setup PreventEmptySessionCookie
// 空セッション時のCookie送信を防止（ログアウト後など）
// Prevent cookie transmission for empty sessions (e.g., after logout)
PreventEmptySessionCookie::setup($handler, new NullLogger());

// セッション開始 / Start session
session_start();

// セッションにハンドラー名を記録（デバッグ用）
// Record handler name in session (for debugging)
if (!isset($_SESSION['_handler_name'])) {
    $_SESSION['_handler_name'] = 'enhanced';
}

// セッション開始時刻を記録（初回のみ）
// Record session start time (first time only)
if (!isset($_SESSION['_started_at'])) {
    $_SESSION['_started_at'] = date('Y-m-d H:i:s');
}

// 最終アクセス時刻を更新
// Update last access time
$_SESSION['_last_access'] = date('Y-m-d H:i:s');
