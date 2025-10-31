<?php
declare(strict_types=1);

require '/app/vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

echo "<h1>Library Check</h1>";

if (class_exists(SessionHandlerFactory::class)) {
    echo "<p>✅ OK: SessionHandlerFactory クラスが読み込めました</p>";
} else {
    echo "<p>❌ ERROR: SessionHandlerFactory クラスが見つかりません</p>";
    exit(1);
}

echo "<p>PHP Version: " . PHP_VERSION . "</p>";

if (extension_loaded('redis')) {
    echo "<p>redis-ext Version: " . phpversion('redis') . "</p>";
} else {
    echo "<p>❌ ERROR: redis extension が読み込まれていません</p>";
    exit(1);
}

try {
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int)(getenv('REDIS_PORT') ?: 6379));
    $redis->ping();
    echo "<p>✅ Redis 接続: OK</p>";
} catch (Exception $e) {
    echo "<p>⚠️ Redis 接続: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><strong>すべてのチェックが完了しました</strong></p>";
