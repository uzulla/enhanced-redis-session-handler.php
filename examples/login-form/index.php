<?php

declare(strict_types=1);

/**
 * ログインフォーム / Login Form
 *
 * このページはログインフォームを表示します。
 * This page displays the login form.
 *
 * 使用方法 / How to use:
 * 1. ブラウザで http://localhost/examples/login-form/index.php にアクセス
 * 2. デフォルトでは enhanced-redis-session-handler を使用します
 * 3. ?handler=redis-ext を追加すると Redis拡張を使用します
 *
 * Access http://localhost/examples/login-form/index.php in your browser
 * By default, it uses enhanced-redis-session-handler
 * Add ?handler=redis-ext to use Redis extension
 */

// セッションハンドラーの選択 / Select session handler
$handler = $_GET['handler'] ?? 'enhanced';

if ($handler === 'redis-ext') {
    require_once __DIR__ . '/bootstrap-redis-ext.php';
} else {
    require_once __DIR__ . '/bootstrap-enhanced.php';
}

// 既にログイン済みの場合はダッシュボードにリダイレクト
// Redirect to dashboard if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// メッセージの取得 / Get messages
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

$logoutMessage = $_SESSION['logout_message'] ?? null;
unset($_SESSION['logout_message']);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Enhanced Redis Session Handler Example</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .handler-info {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .handler-info strong {
            color: #667eea;
        }
        .alert {
            background: #fee;
            border-left: 4px solid #e33;
            color: #c00;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        button:hover {
            opacity: 0.9;
        }
        .demo-users {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .demo-users h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        .demo-users ul {
            list-style: none;
            font-size: 13px;
            color: #666;
        }
        .demo-users li {
            padding: 4px 0;
        }
        .demo-users code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .handler-switch {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
        }
        .handler-switch a {
            color: #667eea;
            text-decoration: none;
        }
        .handler-switch a:hover {
            text-decoration: underline;
        }
        .session-info {
            margin-top: 15px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            font-size: 12px;
            color: #666;
        }
        .session-info div {
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🔐 Login Example</h1>
        <p class="subtitle">Enhanced Redis Session Handler</p>

        <div class="handler-info">
            <strong>Current Handler:</strong> <?php echo h(getCurrentHandlerName()); ?><br>
            <strong>Session ID:</strong> <?php echo h(substr(session_id(), -8)); ?>...
        </div>

        <?php if ($logoutMessage): ?>
            <div class="alert-success">
                <?php echo h($logoutMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert">
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="username">ユーザー名 / Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">パスワード / Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <input type="hidden" name="handler" value="<?php echo h($handler); ?>">

            <button type="submit">ログイン / Login</button>
        </form>

        <div class="demo-users">
            <h3>📝 Demo Accounts:</h3>
            <ul>
                <li><code>admin</code> / <code>admin123</code> (Admin)</li>
                <li><code>user1</code> / <code>password1</code> (User)</li>
                <li><code>user2</code> / <code>password2</code> (User)</li>
            </ul>
        </div>

        <div class="handler-switch">
            <?php if ($handler === 'enhanced'): ?>
                <a href="?handler=redis-ext">Switch to Redis Extension →</a>
            <?php else: ?>
                <a href="?handler=enhanced">← Switch to Enhanced Handler</a>
            <?php endif; ?>
        </div>

        <div class="session-info">
            <div><strong>Session Handler:</strong> <?php echo h($handler); ?></div>
            <div><strong>Serialize Handler:</strong> <?php echo h(ini_get('session.serialize_handler')); ?></div>
            <?php if (isset($_SESSION['_started_at'])): ?>
                <div><strong>Session Started:</strong> <?php echo h($_SESSION['_started_at']); ?></div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
