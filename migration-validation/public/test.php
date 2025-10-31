<?php

declare(strict_types=1);

ob_start();

/**
 * Bidirectional session interoperability test
 * 
 * Tests:
 * - write_old: Write session using redis-ext
 * - read_new: Read session using enhanced-redis-session-handler library
 * - write_new: Write session using enhanced-redis-session-handler library
 * - read_old: Read session using redis-ext
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Psr\Log\NullLogger;

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';
$serializer = $_GET['serializer'] ?? 'php';

if (!in_array($action, ['write_old', 'read_new', 'write_new', 'read_old'], true)) {
    header('Location: index.php');
    exit;
}

if (!in_array($serializer, ['php', 'php_serialize'], true)) {
    header('Location: index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Interoperability Test - <?php echo htmlspecialchars($action); ?> (<?php echo htmlspecialchars($serializer); ?>)</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 900px;
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
        .test-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-left: 4px solid #2196F3;
        }
        .test-step {
            margin: 10px 0;
            padding: 10px;
            background-color: #fff;
            border-radius: 4px;
        }
        .step-title {
            font-weight: bold;
            color: #2196F3;
            margin-bottom: 5px;
        }
        .step-result {
            color: #666;
            font-family: 'Courier New', monospace;
            margin-left: 20px;
        }
        .status-ok {
            color: #4CAF50;
            font-weight: bold;
        }
        .status-error {
            color: #f44336;
            font-weight: bold;
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
        pre {
            background-color: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-link:hover {
            background-color: #0b7dda;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Session Interoperability Test: <?php echo htmlspecialchars($action); ?></h1>
        
        <div class="test-section">
            <h3>Environment Information</h3>
            <div><span class="info-label">PHP Version:</span> <span class="info-value"><?php echo PHP_VERSION; ?></span></div>
            <div><span class="info-label">Redis Extension:</span> <span class="info-value"><?php echo extension_loaded('redis') ? '✓ Loaded (v' . phpversion('redis') . ')' : '✗ Not Loaded'; ?></span></div>
            <div><span class="info-label">Serializer:</span> <span class="info-value"><?php echo htmlspecialchars($serializer); ?></span></div>
            <div><span class="info-label">Action:</span> <span class="info-value"><?php echo htmlspecialchars($action); ?></span></div>
            <div><span class="info-label">Timestamp:</span> <span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
        </div>

        <?php
        $testPassed = true;
        $errorMessage = '';
        
        try {
            echo '<div class="test-section">';
            echo '<h3>Test Execution</h3>';
            
            switch ($action) {
                case 'write_old':
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 1: Configure Native Session Handler (redis-ext)</div>';
                    
                    ini_set('session.save_handler', 'redis');
                    ini_set('session.save_path', 'tcp://redis:6379?database=0');
                    ini_set('session.serialize_handler', $serializer);
                    
                    echo '<div class="step-result">✓ Session handler: redis-ext</div>';
                    echo '<div class="step-result">✓ Session save path: tcp://redis:6379?database=0</div>';
                    echo '<div class="step-result">✓ Serializer: ' . htmlspecialchars($serializer) . '</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 2: Start Session and Write Data</div>';
                    
                    session_start();
                    $sessionId = session_id();
                    
                    $_SESSION['test_type'] = 'write_old';
                    $_SESSION['php_version'] = PHP_VERSION;
                    $_SESSION['redis_ext_version'] = phpversion('redis');
                    $_SESSION['timestamp'] = time();
                    $_SESSION['test_data'] = [
                        'string' => 'Hello from redis-ext',
                        'number' => 12345,
                        'array' => ['a', 'b', 'c'],
                        'nested' => [
                            'key1' => 'value1',
                            'key2' => 'value2'
                        ]
                    ];
                    
                    echo '<div class="step-result status-ok">✓ Session started (ID: ' . htmlspecialchars($sessionId) . ')</div>';
                    echo '<div class="step-result">✓ Test data written to session</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 3: Session Data</div>';
                    echo '<pre>' . htmlspecialchars(print_r($_SESSION, true)) . '</pre>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 4: Save Session</div>';
                    session_write_close();
                    echo '<div class="step-result status-ok">✓ Session saved to Redis using redis-ext</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Next Step</div>';
                    echo '<div class="step-result">セッションID <strong>' . htmlspecialchars($sessionId) . '</strong> でデータを書き込みました。</div>';
                    echo '<div class="step-result">次に「ライブラリで読み込み」をクリックして、enhanced-redis-session-handler で読み込めるか確認してください。</div>';
                    echo '</div>';
                    
                    break;
                    
                case 'read_new':
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 1: Load Library</div>';
                    
                    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                        throw new Exception('vendor/autoload.php not found. Please run: docker-compose exec php81-apache composer install');
                    }
                    
                    echo '<div class="step-result">✓ Library loaded</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 2: Configure Enhanced Session Handler</div>';
                    
                    $connectionConfig = new RedisConnectionConfig(
                        'redis',
                        6379,
                        2.5,
                        null,
                        0,
                        'PHPREDIS_SESSION:'
                    );
                    
                    $serializerInstance = $serializer === 'php_serialize' 
                        ? new PhpSerializeSerializer() 
                        : new PhpSerializer();
                    
                    $sessionConfig = new SessionConfig(
                        $connectionConfig,
                        $serializerInstance,
                        new DefaultSessionIdGenerator(),
                        1440,
                        new NullLogger()
                    );
                    
                    $factory = new SessionHandlerFactory($sessionConfig);
                    $handler = $factory->build();
                    session_set_save_handler($handler, true);
                    
                    echo '<div class="step-result">✓ Session handler: enhanced-redis-session-handler</div>';
                    echo '<div class="step-result">✓ Serializer: ' . htmlspecialchars($serializerInstance->getName()) . '</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 3: Configure Session Serializer and Start Session</div>';
                    
                    ini_set('session.serialize_handler', $serializer);
                    session_start();
                    $sessionId = session_id();
                    
                    echo '<div class="step-result">✓ Session started (ID: ' . htmlspecialchars($sessionId) . ')</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 4: Read and Verify Session Data</div>';
                    
                    if (isset($_SESSION['test_type']) && $_SESSION['test_type'] === 'write_old') {
                        echo '<div class="step-result status-ok">✓ Successfully read session data written by redis-ext!</div>';
                        echo '<div class="step-result">  - test_type: ' . htmlspecialchars($_SESSION['test_type']) . '</div>';
                        echo '<div class="step-result">  - php_version: ' . htmlspecialchars($_SESSION['php_version']) . '</div>';
                        echo '<div class="step-result">  - redis_ext_version: ' . htmlspecialchars($_SESSION['redis_ext_version']) . '</div>';
                        echo '<div class="step-result">  - timestamp: ' . htmlspecialchars((string)$_SESSION['timestamp']) . '</div>';
                        
                        echo '<div class="test-step">';
                        echo '<div class="step-title">Complete Session Data</div>';
                        echo '<pre>' . htmlspecialchars(print_r($_SESSION, true)) . '</pre>';
                        echo '</div>';
                    } else {
                        echo '<div class="step-result status-error">✗ Session data not found or invalid</div>';
                        echo '<div class="step-result">Expected test_type=write_old, but got: ' . htmlspecialchars($_SESSION['test_type'] ?? 'null') . '</div>';
                        echo '<div class="step-result">Please run "redis-ext で書き込み" first.</div>';
                        $testPassed = false;
                    }
                    echo '</div>';
                    
                    session_write_close();
                    
                    break;
                    
                case 'write_new':
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 1: Load Library</div>';
                    
                    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                        throw new Exception('vendor/autoload.php not found. Please run: docker-compose exec php81-apache composer install');
                    }
                    
                    echo '<div class="step-result">✓ Library loaded</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 2: Configure Enhanced Session Handler</div>';
                    
                    $connectionConfig = new RedisConnectionConfig(
                        'redis',
                        6379,
                        2.5,
                        null,
                        0,
                        'PHPREDIS_SESSION:'
                    );
                    
                    $serializerInstance = $serializer === 'php_serialize' 
                        ? new PhpSerializeSerializer() 
                        : new PhpSerializer();
                    
                    $sessionConfig = new SessionConfig(
                        $connectionConfig,
                        $serializerInstance,
                        new DefaultSessionIdGenerator(),
                        1440,
                        new NullLogger()
                    );
                    
                    $factory = new SessionHandlerFactory($sessionConfig);
                    $handler = $factory->build();
                    session_set_save_handler($handler, true);
                    
                    echo '<div class="step-result">✓ Session handler: enhanced-redis-session-handler</div>';
                    echo '<div class="step-result">✓ Serializer: ' . htmlspecialchars($serializerInstance->getName()) . '</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 3: Configure Session Serializer and Start Session</div>';
                    
                    ini_set('session.serialize_handler', $serializer);
                    session_start();
                    $sessionId = session_id();
                    
                    $_SESSION['test_type'] = 'write_new';
                    $_SESSION['php_version'] = PHP_VERSION;
                    $_SESSION['library_version'] = 'enhanced-redis-session-handler';
                    $_SESSION['timestamp'] = time();
                    $_SESSION['test_data'] = [
                        'string' => 'Hello from enhanced-redis-session-handler',
                        'number' => 67890,
                        'array' => ['x', 'y', 'z'],
                        'nested' => [
                            'key3' => 'value3',
                            'key4' => 'value4'
                        ]
                    ];
                    
                    echo '<div class="step-result status-ok">✓ Session started (ID: ' . htmlspecialchars($sessionId) . ')</div>';
                    echo '<div class="step-result">✓ Test data written to session</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 4: Session Data</div>';
                    echo '<pre>' . htmlspecialchars(print_r($_SESSION, true)) . '</pre>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 5: Save Session</div>';
                    session_write_close();
                    echo '<div class="step-result status-ok">✓ Session saved to Redis using enhanced-redis-session-handler</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Next Step</div>';
                    echo '<div class="step-result">セッションID <strong>' . htmlspecialchars($sessionId) . '</strong> でデータを書き込みました。</div>';
                    echo '<div class="step-result">次に「redis-ext で読み込み」をクリックして、redis-ext で読み込めるか確認してください。</div>';
                    echo '</div>';
                    
                    break;
                    
                case 'read_old':
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 1: Configure Native Session Handler (redis-ext)</div>';
                    
                    ini_set('session.save_handler', 'redis');
                    ini_set('session.save_path', 'tcp://redis:6379?database=0');
                    ini_set('session.serialize_handler', $serializer);
                    
                    echo '<div class="step-result">✓ Session handler: redis-ext</div>';
                    echo '<div class="step-result">✓ Session save path: tcp://redis:6379?database=0</div>';
                    echo '<div class="step-result">✓ Serializer: ' . htmlspecialchars($serializer) . '</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 2: Start Session</div>';
                    
                    session_start();
                    $sessionId = session_id();
                    
                    echo '<div class="step-result">✓ Session started (ID: ' . htmlspecialchars($sessionId) . ')</div>';
                    echo '</div>';
                    
                    echo '<div class="test-step">';
                    echo '<div class="step-title">Step 3: Read and Verify Session Data</div>';
                    
                    if (isset($_SESSION['test_type']) && $_SESSION['test_type'] === 'write_new') {
                        echo '<div class="step-result status-ok">✓ Successfully read session data written by enhanced-redis-session-handler!</div>';
                        echo '<div class="step-result">  - test_type: ' . htmlspecialchars($_SESSION['test_type']) . '</div>';
                        echo '<div class="step-result">  - php_version: ' . htmlspecialchars($_SESSION['php_version']) . '</div>';
                        echo '<div class="step-result">  - library_version: ' . htmlspecialchars($_SESSION['library_version']) . '</div>';
                        echo '<div class="step-result">  - timestamp: ' . htmlspecialchars((string)$_SESSION['timestamp']) . '</div>';
                        
                        echo '<div class="test-step">';
                        echo '<div class="step-title">Complete Session Data</div>';
                        echo '<pre>' . htmlspecialchars(print_r($_SESSION, true)) . '</pre>';
                        echo '</div>';
                    } else {
                        echo '<div class="step-result status-error">✗ Session data not found or invalid</div>';
                        echo '<div class="step-result">Expected test_type=write_new, but got: ' . htmlspecialchars($_SESSION['test_type'] ?? 'null') . '</div>';
                        echo '<div class="step-result">Please run "ライブラリで書き込み" first.</div>';
                        $testPassed = false;
                    }
                    echo '</div>';
                    
                    session_write_close();
                    
                    break;
            }
            
            echo '</div>';
            
            echo '<div class="test-section">';
            if ($testPassed) {
                echo '<h3 class="status-ok">✓ Test Passed</h3>';
                echo '<p>The test completed successfully.</p>';
            } else {
                echo '<h3 class="status-error">✗ Test Failed</h3>';
                echo '<p>Please review the error messages above.</p>';
            }
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="test-section">';
            echo '<h3 class="status-error">✗ Test Failed</h3>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        ?>
        
        <a href="index.php" class="back-link">← Back to Menu</a>
    </div>
</body>
</html>
<?php ob_end_flush(); ?>
