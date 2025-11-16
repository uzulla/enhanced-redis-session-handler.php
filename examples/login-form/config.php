<?php

declare(strict_types=1);

/**
 * 共通設定ファイル / Common Configuration File
 *
 * このファイルはRedis接続設定と共通定数を定義します。
 * This file defines Redis connection settings and common constants.
 */

// Redis接続設定 / Redis connection settings
define('REDIS_HOST', getenv('SESSION_REDIS_HOST') ?: 'localhost');
define('REDIS_PORT', (int)(getenv('SESSION_REDIS_PORT') ?: 6379));
define('REDIS_DATABASE', 0);
define('REDIS_KEY_PREFIX', 'login_example:');

// セッション設定 / Session settings
define('SESSION_NAME', 'LOGIN_EXAMPLE_SESS');
define('SESSION_LIFETIME', 1440); // 24分 / 24 minutes

// デモ用のユーザーデータ / Demo user data
// 実際のアプリケーションではデータベースを使用してください
// In real applications, use a database
define('DEMO_USERS', [
    'admin' => [
        'username' => 'admin',
        'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'name' => 'Administrator',
        'role' => 'admin',
    ],
    'user1' => [
        'username' => 'user1',
        'password_hash' => password_hash('password1', PASSWORD_DEFAULT),
        'name' => 'Test User 1',
        'role' => 'user',
    ],
    'user2' => [
        'username' => 'user2',
        'password_hash' => password_hash('password2', PASSWORD_DEFAULT),
        'name' => 'Test User 2',
        'role' => 'user',
    ],
]);

/**
 * ユーザー認証を行う / Authenticate user
 *
 * @param string $username ユーザー名 / Username
 * @param string $password パスワード / Password
 * @return array|null ユーザー情報またはnull / User info or null
 */
function authenticateUser(string $username, string $password): ?array
{
    $users = DEMO_USERS;

    if (!isset($users[$username])) {
        return null;
    }

    $user = $users[$username];

    if (password_verify($password, $user['password_hash'])) {
        return $user;
    }

    return null;
}

/**
 * ログインしているかチェック / Check if user is logged in
 *
 * @return bool ログイン済みならtrue / True if logged in
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

/**
 * 現在のセッションハンドラー名を取得 / Get current session handler name
 *
 * @return string セッションハンドラー名 / Session handler name
 */
function getCurrentHandlerName(): string
{
    return $_SESSION['_handler_name'] ?? 'unknown';
}

/**
 * HTMLエスケープ / HTML escape
 *
 * @param string $str エスケープする文字列 / String to escape
 * @return string エスケープ済み文字列 / Escaped string
 */
function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
