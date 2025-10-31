<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/vendor/autoload.php';

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Psr\Log\NullLogger;

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Interoperability Test</title>
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Session Interoperability Test</h1>
        
        <div class="test-section">
            <h3>Environment Information</h3>
            <div><span class="info-label">PHP Version:</span> <span class="info-value"><?php echo PHP_VERSION; ?></span></div>
            <div><span class="info-label">Redis Extension:</span> <span class="info-value"><?php echo extension_loaded('redis') ? '✓ Loaded (v' . phpversion('redis') . ')' : '✗ Not Loaded'; ?></span></div>
            <div><span class="info-label">Timestamp:</span> <span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
        </div>

        <?php
        $allTestsPassed = true;
        
        try {
            echo '<div class="test-section">';
            echo '<h3>Session Lifecycle Test</h3>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 1: Configure Redis Connection</div>';
            $connectionConfig = new RedisConnectionConfig(
                'redis',
                6379,
                2.5,
                null,
                0,
                'migration_test:'
            );
            echo '<div class="step-result">✓ Redis connection configured (redis:6379)</div>';
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 2: Create Session Configuration</div>';
            $sessionConfig = new SessionConfig(
                $connectionConfig,
                new PhpSerializeSerializer(),
                new DefaultSessionIdGenerator(),
                1440,
                new NullLogger()
            );
            echo '<div class="step-result">✓ Session configuration created</div>';
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 3: Build and Register Session Handler</div>';
            $factory = new SessionHandlerFactory($sessionConfig);
            $handler = $factory->build();
            session_set_save_handler($handler, true);
            echo '<div class="step-result">✓ Session handler registered</div>';
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 4: Configure Session Serializer and Start Session</div>';
            ini_set('session.serialize_handler', 'php_serialize');
            session_start();
            $sessionId = session_id();
            
            if ($sessionId === '' || $sessionId === false) {
                session_regenerate_id(true);
                $sessionId = session_id();
            }
            
            $_SESSION['test_timestamp'] = time();
            $_SESSION['test_data'] = 'interop_test_value';
            $_SESSION['test_array'] = [
                'php_version' => PHP_VERSION,
                'redis_ext_version' => phpversion('redis'),
                'test_id' => uniqid('test_', true)
            ];
            
            echo '<div class="step-result">✓ Session serializer configured (php_serialize)</div>';
            echo '<div class="step-result">✓ Session started (ID: ' . htmlspecialchars($sessionId) . ')</div>';
            echo '<div class="step-result">✓ Test data written to session</div>';
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 5: Save and Close Session</div>';
            session_write_close();
            echo '<div class="step-result">✓ Session saved to Redis</div>';
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 6: Reopen Session and Verify Data</div>';
            session_start();
            $currentSessionId = session_id();
            
            $dataValid = isset($_SESSION['test_timestamp']) && 
                        isset($_SESSION['test_data']) && 
                        isset($_SESSION['test_array']) &&
                        $_SESSION['test_data'] === 'interop_test_value';
            
            if ($dataValid) {
                echo '<div class="step-result status-ok">✓ Session data successfully retrieved</div>';
                echo '<div class="step-result">  - test_timestamp: ' . htmlspecialchars((string)$_SESSION['test_timestamp']) . '</div>';
                echo '<div class="step-result">  - test_data: ' . htmlspecialchars($_SESSION['test_data']) . '</div>';
                echo '<div class="step-result">  - test_array: ' . htmlspecialchars(json_encode($_SESSION['test_array'])) . '</div>';
            } else {
                echo '<div class="step-result status-error">✗ Session data verification failed</div>';
                $allTestsPassed = false;
            }
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 7: Test Session ID Validation</div>';
            $isValid = $handler->validateId($currentSessionId);
            if ($isValid) {
                echo '<div class="step-result status-ok">✓ Session ID validation: VALID</div>';
            } else {
                echo '<div class="step-result status-error">✗ Session ID validation: INVALID</div>';
                $allTestsPassed = false;
            }
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 8: Test Timestamp Update</div>';
            
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            
            $data = session_encode();
            $updateResult = $handler->updateTimestamp($currentSessionId, $data);
            
            if ($updateResult) {
                echo '<div class="step-result status-ok">✓ Timestamp update: SUCCESS</div>';
            } else {
                echo '<div class="step-result status-error">✗ Timestamp update: FAILED</div>';
                $allTestsPassed = false;
            }
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 9: Destroy Session</div>';
            session_destroy();
            echo '<div class="step-result">✓ Session destroyed</div>';
            echo '</div>';
            
            echo '</div>';
            
            echo '<div class="test-section">';
            if ($allTestsPassed) {
                echo '<h3 class="status-ok">✓ All Tests Passed</h3>';
                echo '<p>The enhanced-redis-session-handler library is working correctly with redis-ext ' . phpversion('redis') . ' on PHP ' . PHP_VERSION . '</p>';
            } else {
                echo '<h3 class="status-error">✗ Some Tests Failed</h3>';
                echo '<p>Please review the failed tests above.</p>';
            }
            echo '</div>';
            
        } catch (\Exception $e) {
            echo '<div class="test-section">';
            echo '<h3 class="status-error">✗ Test Failed</h3>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        
        ob_end_flush();
        ?>
    </div>
</body>
</html>
