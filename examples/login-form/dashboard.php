<?php

declare(strict_types=1);

/**
 * ダッシュボード / Dashboard
 *
 * このページはログイン後のダッシュボードを表示します。
 * This page displays the dashboard after login.
 *
 * セッションハンドラー切り替えテスト：
 * このページで ?handler=redis-ext または ?handler=enhanced を指定することで
 * セッションハンドラーを切り替えられます。セッションデータが正しく引き継がれるかテストできます。
 *
 * Session handler switching test:
 * You can switch session handlers by specifying ?handler=redis-ext or ?handler=enhanced
 * on this page. Test if session data is correctly preserved.
 */

// セッションハンドラーの選択 / Select session handler
$handler = $_GET['handler'] ?? 'enhanced';

if ($handler === 'redis-ext') {
    require_once __DIR__ . '/bootstrap-redis-ext.php';
} else {
    require_once __DIR__ . '/bootstrap-enhanced.php';
}

// ログインチェック / Check login
if (!isLoggedIn()) {
    header('Location: index.php?handler=' . urlencode($handler));
    exit;
}

// 成功メッセージの取得 / Get success message
$successMessage = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

$user = $_SESSION['user'];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Enhanced Redis Session Handler Example</title>
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
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .user-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        .info-item label {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .info-item .value {
            font-size: 16px;
            color: #333;
        }
        .handler-info {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .handler-info h3 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .handler-info .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            font-size: 13px;
        }
        .handler-info .info-grid div {
            padding: 5px 0;
        }
        .handler-info strong {
            color: #333;
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: opacity 0.3s;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .session-data {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .session-data h3 {
            margin-bottom: 10px;
            font-size: 14px;
            color: #666;
        }
        .session-data pre {
            background: #fff;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            border: 1px solid #dee2e6;
        }
        .compatibility-test {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .compatibility-test h3 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .compatibility-test p {
            color: #856404;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .compatibility-test .test-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>👋 Welcome, <?php echo h($user['name']); ?>!</h1>
            <p class="subtitle">Enhanced Redis Session Handler - Dashboard</p>

            <?php if ($successMessage): ?>
                <div class="alert-success">
                    <?php echo h($successMessage); ?>
                </div>
            <?php endif; ?>

            <div class="user-info">
                <div class="info-item">
                    <label>Username</label>
                    <div class="value"><?php echo h($user['username']); ?></div>
                </div>
                <div class="info-item">
                    <label>Role</label>
                    <div class="value"><?php echo h(ucfirst($user['role'])); ?></div>
                </div>
                <div class="info-item">
                    <label>Logged in at</label>
                    <div class="value"><?php echo h($user['logged_in_at']); ?></div>
                </div>
            </div>

            <div class="handler-info">
                <h3>📊 Current Session Handler Information</h3>
                <div class="info-grid">
                    <div><strong>Handler:</strong> <?php echo h(getCurrentHandlerName()); ?></div>
                    <div><strong>Session ID:</strong> <?php echo h(session_id()); ?></div>
                    <div><strong>Serialize Handler:</strong> <?php echo h(ini_get('session.serialize_handler')); ?></div>
                    <div><strong>Session Started:</strong> <?php echo h($_SESSION['_started_at'] ?? 'N/A'); ?></div>
                    <div><strong>Last Access:</strong> <?php echo h($_SESSION['_last_access'] ?? 'N/A'); ?></div>
                </div>
            </div>

            <div class="compatibility-test">
                <h3>🔄 Session Handler Compatibility Test</h3>
                <p>
                    このページでセッションハンドラーを切り替えて、セッションデータが正しく引き継がれるかテストできます。<br>
                    Test session handler switching to verify that session data is correctly preserved.
                </p>
                <div class="test-buttons">
                    <?php if ($handler === 'enhanced'): ?>
                        <a href="?handler=redis-ext" class="btn btn-secondary">
                            Switch to Redis Extension →
                        </a>
                    <?php else: ?>
                        <a href="?handler=enhanced" class="btn btn-primary">
                            ← Switch to Enhanced Handler
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="session-data">
                <h3>📦 Session Data (Debug)</h3>
                <pre><?php echo h(print_r($_SESSION, true)); ?></pre>
            </div>

            <div class="actions">
                <a href="logout.php" class="btn btn-danger">Logout</a>
            </div>
        </div>
    </div>
</body>
</html>
