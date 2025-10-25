<?php

declare(strict_types=1);

/**
 * Docker環境でのセッションハンドラデモ / Session Handler Demo in Docker Environment
 *
 * このファイルは、Docker環境でEnhanced Redis Session Handlerを使用した
 * Webアプリケーションのデモを提供します。
 *
 * This file provides a web application demo using Enhanced Redis Session Handler
 * in Docker environment.
 *
 * アクセス方法 / How to access:
 * http://localhost:8080/examples/docker-demo/
 */

require_once __DIR__ . '/session-init.php';

session_start();

if (isset($_GET['action']) && $_GET['action'] === 'destroy') {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['visit_count'])) {
    $_SESSION['visit_count'] = 0;
    $_SESSION['created_at'] = time();
}
$_SESSION['visit_count']++;
$_SESSION['last_access'] = time();

$sessionId = session_id();
$visitCount = $_SESSION['visit_count'];
$createdAt = $_SESSION['created_at'];
$lastAccess = $_SESSION['last_access'];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Redis Session Handler - Docker Demo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 800px;
            width: 100%;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .content {
            padding: 30px;
        }

        .card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .card h2::before {
            content: '📊';
            margin-right: 10px;
            font-size: 24px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            background: white;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 18px;
            color: #333;
            font-weight: 600;
            word-break: break-all;
        }

        .visit-counter {
            text-align: center;
            padding: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .visit-counter .number {
            font-size: 72px;
            font-weight: bold;
            margin: 20px 0;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }

        .visit-counter .label {
            font-size: 18px;
            opacity: 0.9;
        }

        .session-data {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .session-data h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
        }

        .session-data pre {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #e53e3e;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 101, 101, 0.4);
        }

        .btn-secondary {
            background: #718096;
            color: white;
        }

        .btn-secondary:hover {
            background: #4a5568;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(113, 128, 150, 0.4);
        }

        .footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            color: #666;
            font-size: 13px;
            border-top: 1px solid #e2e8f0;
        }

        .footer a {
            color: #667eea;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .visit-counter .number {
                font-size: 48px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Enhanced Redis Session Handler</h1>
            <p>Docker環境でのセッション管理デモ / Session Management Demo in Docker</p>
        </div>

        <div class="content">
            <!-- 訪問カウンター / Visit Counter -->
            <div class="visit-counter">
                <div class="label">訪問回数 / Visit Count</div>
                <div class="number"><?php echo htmlspecialchars((string)$visitCount); ?></div>
                <div class="label">このページをリロードすると増加します / Increases on page reload</div>
            </div>

            <!-- セッション情報 / Session Information -->
            <div class="card">
                <h2>セッション情報 / Session Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">セッションID / Session ID</div>
                        <div class="info-value" style="font-size: 12px;"><?php echo htmlspecialchars($sessionId); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">作成日時 / Created At</div>
                        <div class="info-value" style="font-size: 14px;"><?php echo date('Y-m-d H:i:s', $createdAt); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">最終アクセス / Last Access</div>
                        <div class="info-value" style="font-size: 14px;"><?php echo date('Y-m-d H:i:s', $lastAccess); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">訪問回数 / Visit Count</div>
                        <div class="info-value"><?php echo htmlspecialchars((string)$visitCount); ?></div>
                    </div>
                </div>
            </div>

            <!-- セッションデータ / Session Data -->
            <div class="session-data">
                <h2>📦 セッションデータ / Session Data</h2>
                <pre><?php echo htmlspecialchars(json_encode($_SESSION, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            </div>

            <!-- アクション / Actions -->
            <div class="actions">
                <a href="index.php" class="btn btn-primary">🔄 リロード / Reload</a>
                <a href="index.php?action=destroy" class="btn btn-danger" onclick="return confirm('セッションを破棄しますか？ / Destroy session?')">🗑️ セッション破棄 / Destroy Session</a>
                <a href="../" class="btn btn-secondary">📚 サンプル一覧へ / Back to Examples</a>
            </div>
        </div>

        <div class="footer">
            <p>
                Enhanced Redis Session Handler by 
                <a href="https://github.com/uzulla/enhanced-redis-session-handler.php" target="_blank">uzulla</a>
                | 
                <a href="https://github.com/uzulla/enhanced-redis-session-handler.php/blob/main/LICENSE" target="_blank">MIT License</a>
            </p>
        </div>
    </div>
</body>
</html>
