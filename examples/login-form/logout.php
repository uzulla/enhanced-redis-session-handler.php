<?php

declare(strict_types=1);

/**
 * ログアウト処理 / Logout Process
 *
 * このページはログアウト処理を実行します。
 * This page handles logout processing.
 *
 * PreventEmptySessionCookie機能のテスト：
 * Enhanced handlerを使用している場合、セッション破棄後に
 * 空セッションのCookieが削除されることを確認できます。
 *
 * PreventEmptySessionCookie feature test:
 * When using the Enhanced handler, verify that the cookie for empty sessions
 * is removed after session destruction.
 */

// セッションハンドラーの選択 / Select session handler
// ログアウト後も同じハンドラーを使用するため、セッションから取得
// Use the same handler after logout by getting it from the session

// まずセッションを開始してハンドラー名を取得
// Start session first to get handler name
$handler = $_GET['handler'] ?? null;

if ($handler === null) {
    // GETパラメータがない場合は、とりあえずenhancedで開始して確認
    // If no GET parameter, start with enhanced and check
    require_once __DIR__ . '/bootstrap-enhanced.php';
    $currentHandler = getCurrentHandlerName();

    // 実際のハンドラーに基づいて再起動
    // Restart based on actual handler
    session_write_close();

    if ($currentHandler === 'redis-ext') {
        $handler = 'redis-ext';
        require_once __DIR__ . '/bootstrap-redis-ext.php';
    } else {
        $handler = 'enhanced';
        require_once __DIR__ . '/bootstrap-enhanced.php';
    }
} elseif ($handler === 'redis-ext') {
    require_once __DIR__ . '/bootstrap-redis-ext.php';
} else {
    require_once __DIR__ . '/bootstrap-enhanced.php';
}

// セッションを破棄 / Destroy session
session_destroy();

// 新しいセッションを開始（ログアウトメッセージ用）
// Start new session (for logout message)
session_start();
$_SESSION['logout_message'] = 'You have been logged out successfully.';

// ログインページにリダイレクト / Redirect to login page
header('Location: index.php?handler=' . urlencode($handler));
exit;
