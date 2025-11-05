<?php

declare(strict_types=1);

/**
 * ログイン処理 / Login Process
 *
 * このページはログイン処理を実行します。
 * This page handles login processing.
 */

// セッションハンドラーの選択 / Select session handler
$handler = $_POST['handler'] ?? 'enhanced';

if ($handler === 'redis-ext') {
    require_once __DIR__ . '/bootstrap-redis-ext.php';
} else {
    require_once __DIR__ . '/bootstrap-enhanced.php';
}

// POSTリクエストでない場合はログインページにリダイレクト
// Redirect to login page if not POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// 認証を実行 / Authenticate
$user = authenticateUser($username, $password);

if ($user === null) {
    // 認証失敗 / Authentication failed
    $_SESSION['login_error'] = 'Invalid username or password.';
    header('Location: index.php?handler=' . urlencode($handler));
    exit;
}

// 認証成功 - セッションにユーザー情報を保存
// Authentication successful - Save user info to session
$_SESSION['user'] = [
    'username' => $user['username'],
    'name' => $user['name'],
    'role' => $user['role'],
    'logged_in_at' => date('Y-m-d H:i:s'),
];

// ログイン成功メッセージ / Login success message
$_SESSION['success_message'] = 'Login successful! Welcome, ' . $user['name'];

// ダッシュボードにリダイレクト / Redirect to dashboard
header('Location: dashboard.php');
exit;
