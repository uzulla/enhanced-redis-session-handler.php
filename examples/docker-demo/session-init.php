<?php

declare(strict_types=1);

/**
 * セッションハンドラ初期化スクリプト / Session Handler Initialization Script
 *
 * このファイルは、Docker環境でEnhanced Redis Session Handlerを初期化します。
 * This file initializes the Enhanced Redis Session Handler in Docker environment.
 *
 * 使用方法 / Usage:
 * ```php
 * require_once __DIR__ . '/session-init.php';
 * session_start();
 * ```
 *
 * 環境変数 / Environment Variables:
 * - REDIS_HOST: Redisサーバーのホスト名 (デフォルト: localhost)
 * - REDIS_PORT: Redisサーバーのポート番号 (デフォルト: 6379)
 * - REDIS_PASSWORD: Redisサーバーのパスワード (オプション)
 * - REDIS_DATABASE: Redisデータベース番号 (デフォルト: 0)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LogLevel;

try {
    $redisHost = getenv('REDIS_HOST') ?: 'localhost';
    $redisPort = (int)(getenv('REDIS_PORT') ?: '6379');
    $redisPassword = getenv('REDIS_PASSWORD') ?: null;
    $redisDatabase = (int)(getenv('REDIS_DATABASE') ?: '0');
    $redisKeyPrefix = 'session:';

    $logger = new Logger('session');
    $logger->pushHandler(new StreamHandler('php://stderr', LogLevel::INFO));

    $connectionConfig = new RedisConnectionConfig(
        $redisHost,
        $redisPort,
        2.5,
        $redisPassword,
        $redisDatabase,
        $redisKeyPrefix
    );

    $sessionConfig = new SessionConfig(
        $connectionConfig,
        new DefaultSessionIdGenerator(),
        1440, // 24分 / 24 minutes
        $logger
    );

    $factory = new SessionHandlerFactory($sessionConfig);
    $handler = $factory->build();
    session_set_save_handler($handler, true);

    $logger->info('Session handler initialized successfully', [
        'redis_host' => $redisHost,
        'redis_port' => $redisPort,
        'redis_database' => $redisDatabase,
    ]);
} catch (\Exception $e) {
    error_log('Failed to initialize session handler: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo '<!DOCTYPE html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Session Handler Error</title>';
    echo '<style>';
    echo 'body { font-family: sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }';
    echo '.error { background-color: #fee; border: 1px solid #fcc; padding: 20px; border-radius: 5px; }';
    echo 'h1 { color: #c00; }';
    echo 'pre { background-color: #f5f5f5; padding: 10px; overflow-x: auto; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="error">';
    echo '<h1>セッションハンドラの初期化に失敗しました / Session Handler Initialization Failed</h1>';
    echo '<p>Redis接続の設定を確認してください。 / Please check your Redis connection settings.</p>';
    echo '<h2>エラー詳細 / Error Details:</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    echo '<h2>環境変数 / Environment Variables:</h2>';
    echo '<pre>';
    echo 'REDIS_HOST: ' . htmlspecialchars($redisHost ?? 'not set') . "\n";
    echo 'REDIS_PORT: ' . htmlspecialchars((string)($redisPort ?? 'not set')) . "\n";
    echo 'REDIS_DATABASE: ' . htmlspecialchars((string)($redisDatabase ?? 'not set')) . "\n";
    echo '</pre>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
    exit(1);
}
