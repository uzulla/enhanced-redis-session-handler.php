<?php

declare(strict_types=1);

/**
 * Test menu for PHP serializer interoperability validation
 * 
 * Provides a simple interface to test bidirectional compatibility between
 * redis-ext and enhanced-redis-session-handler library.
 * 
 * Supports both 'php' and 'php_serialize' serializers.
 */

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Validation - Session Serializer Interoperability Test</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1000px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
            border-bottom: 2px solid #2196F3;
            padding-bottom: 8px;
        }
        .info-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #e3f2fd;
            border-left: 4px solid #2196F3;
        }
        .test-menu {
            margin: 20px 0;
        }
        .test-item {
            margin: 15px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 4px;
            border-left: 4px solid #4CAF50;
        }
        .test-title {
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
            font-size: 16px;
        }
        .test-description {
            color: #666;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .test-link {
            display: inline-block;
            padding: 8px 16px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
            font-size: 14px;
        }
        .test-link:hover {
            background-color: #45a049;
        }
        .test-link.destroy {
            background-color: #f44336;
        }
        .test-link.destroy:hover {
            background-color: #da190b;
        }
        .info-label {
            font-weight: bold;
            color: #555;
            display: inline-block;
            width: 200px;
        }
        .info-value {
            color: #333;
            font-family: 'Courier New', monospace;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
        .warning-title {
            font-weight: bold;
            color: #856404;
            margin-bottom: 5px;
        }
        .note {
            background-color: #f0f0f0;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Session Serializer Interoperability Test</h1>
        
        <div class="info-section">
            <h3>Environment Information</h3>
            <div><span class="info-label">PHP Version:</span> <span class="info-value"><?php echo PHP_VERSION; ?></span></div>
            <div><span class="info-label">Redis Extension:</span> <span class="info-value"><?php echo extension_loaded('redis') ? '✓ Loaded (v' . phpversion('redis') . ')' : '✗ Not Loaded'; ?></span></div>
            <div><span class="info-label">Library Available:</span> <span class="info-value"><?php 
                if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                    require_once __DIR__ . '/vendor/autoload.php';
                    echo class_exists('Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory') ? '✓ Yes' : '✗ No';
                } else {
                    echo '✗ No (vendor/autoload.php not found)';
                }
            ?></span></div>
            <div><span class="info-label">Timestamp:</span> <span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
        </div>

        <div class="note">
            <h3>📋 テスト手順</h3>
            <p>このページでは、redis-ext と enhanced-redis-session-handler ライブラリの相互運用性をテストできます。</p>
            <p>2つのシリアライザ形式をサポートしています：</p>
            <ul>
                <li><strong>php</strong>: 従来のPHPセッション形式 (key|serialized_value)</li>
                <li><strong>php_serialize</strong>: 標準のPHP serialize()形式 (PHP 7.0+のデフォルト)</li>
            </ul>
            <p>各シリアライザで以下のテストを実行できます：</p>
            <ol>
                <li><strong>旧→新テスト</strong>: redis-ext でセッションを書き込み、ライブラリで読み込む</li>
                <li><strong>新→旧テスト</strong>: ライブラリでセッションを書き込み、redis-ext で読み込む</li>
                <li><strong>クロスバージョンテスト</strong>: PHP 7.4 と PHP 8.1 間でセッションを共有</li>
            </ol>
        </div>

        <h2>🧪 PHP Serializer Tests</h2>

        <div class="test-menu">
            <div class="test-item">
                <div class="test-title">1. 旧→新テスト (redis-ext → library) - php serializer</div>
                <div class="test-description">
                    redis-ext でセッションを書き込み (php形式)、enhanced-redis-session-handler ライブラリで読み込みます。
                </div>
                <a href="test.php?action=write_old&serializer=php" class="test-link">Step 1: redis-ext で書き込み</a>
                <a href="test.php?action=read_new&serializer=php" class="test-link">Step 2: ライブラリで読み込み</a>
            </div>

            <div class="test-item">
                <div class="test-title">2. 新→旧テスト (library → redis-ext) - php serializer</div>
                <div class="test-description">
                    enhanced-redis-session-handler ライブラリでセッションを書き込み (php形式)、redis-ext で読み込みます。
                </div>
                <a href="test.php?action=write_new&serializer=php" class="test-link">Step 1: ライブラリで書き込み</a>
                <a href="test.php?action=read_old&serializer=php" class="test-link">Step 2: redis-ext で読み込み</a>
            </div>
        </div>

        <h2>🧪 PHP Serialize Serializer Tests</h2>

        <div class="test-menu">
            <div class="test-item">
                <div class="test-title">3. 旧→新テスト (redis-ext → library) - php_serialize serializer</div>
                <div class="test-description">
                    redis-ext でセッションを書き込み (php_serialize形式)、enhanced-redis-session-handler ライブラリで読み込みます。
                </div>
                <a href="test.php?action=write_old&serializer=php_serialize" class="test-link">Step 1: redis-ext で書き込み</a>
                <a href="test.php?action=read_new&serializer=php_serialize" class="test-link">Step 2: ライブラリで読み込み</a>
            </div>

            <div class="test-item">
                <div class="test-title">4. 新→旧テスト (library → redis-ext) - php_serialize serializer</div>
                <div class="test-description">
                    enhanced-redis-session-handler ライブラリでセッションを書き込み (php_serialize形式)、redis-ext で読み込みます。
                </div>
                <a href="test.php?action=write_new&serializer=php_serialize" class="test-link">Step 1: ライブラリで書き込み</a>
                <a href="test.php?action=read_old&serializer=php_serialize" class="test-link">Step 2: redis-ext で読み込み</a>
            </div>
        </div>

        <h2>🧹 Session Management</h2>

        <div class="test-menu">
            <div class="test-item">
                <div class="test-title">セッションのクリーンアップ</div>
                <div class="test-description">
                    テスト用のセッションデータを削除します。
                </div>
                <a href="destroy.php" class="test-link destroy">セッションを削除</a>
            </div>
        </div>

        <div class="warning">
            <div class="warning-title">⚠️ クロスバージョンテストについて</div>
            <p>PHP 7.4 と PHP 8.1 間のクロスバージョンテストを行うには：</p>
            <ul>
                <li>PHP 7.4 環境 (http://localhost:8074/) で書き込み → PHP 8.1 環境 (http://localhost:8081/) で読み込み</li>
                <li>PHP 8.1 環境 (http://localhost:8081/) で書き込み → PHP 7.4 環境 (http://localhost:8074/) で読み込み</li>
            </ul>
            <p>同じセッションIDを使用するため、ブラウザのCookieが共有されます。</p>
        </div>

        <h2>🔍 Other Tools</h2>

        <div class="test-menu">
            <div class="test-item">
                <div class="test-title">環境ヘルスチェック</div>
                <div class="test-description">
                    PHP と Redis の基本的な動作確認を行います。
                </div>
                <a href="health.php" class="test-link">Health Check</a>
            </div>

            <div class="test-item">
                <div class="test-title">ライブラリ読み込み確認</div>
                <div class="test-description">
                    enhanced-redis-session-handler ライブラリが正しく読み込めるか確認します。
                </div>
                <a href="library-check.php" class="test-link">Library Check</a>
            </div>

            <div class="test-item">
                <div class="test-title">セッション相互運用性テスト（旧版）</div>
                <div class="test-description">
                    ライブラリの基本的な動作確認を行います（Issue #67 で実装）。
                </div>
                <a href="session_interop.php" class="test-link">Session Interop Test</a>
            </div>
        </div>
    </div>
</body>
</html>
