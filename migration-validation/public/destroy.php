<?php

declare(strict_types=1);

/**
 * Session cleanup script
 * 
 * Destroys the current session and cleans up test data from Redis.
 */

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Cleanup</title>
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
            border-bottom: 3px solid #f44336;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-left: 4px solid #f44336;
        }
        .test-step {
            margin: 10px 0;
            padding: 10px;
            background-color: #fff;
            border-radius: 4px;
        }
        .step-title {
            font-weight: bold;
            color: #f44336;
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
        <h1>🧹 Session Cleanup</h1>
        
        <div class="test-section">
            <h3>Environment Information</h3>
            <div><span class="info-label">PHP Version:</span> <span class="info-value"><?php echo PHP_VERSION; ?></span></div>
            <div><span class="info-label">Redis Extension:</span> <span class="info-value"><?php echo extension_loaded('redis') ? '✓ Loaded (v' . phpversion('redis') . ')' : '✗ Not Loaded'; ?></span></div>
            <div><span class="info-label">Timestamp:</span> <span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
        </div>

        <?php
        $cleanupSuccess = true;
        $sessionId = '';
        
        try {
            echo '<div class="test-section">';
            echo '<h3>Cleanup Process</h3>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 1: Check Current Session</div>';
            
            session_start();
            $sessionId = session_id();
            
            if ($sessionId !== '' && $sessionId !== false) {
                echo '<div class="step-result">✓ Current session ID: ' . htmlspecialchars($sessionId) . '</div>';
                
                if (count($_SESSION) > 0) {
                    echo '<div class="step-result">✓ Session contains data:</div>';
                    echo '<pre>' . htmlspecialchars(print_r($_SESSION, true)) . '</pre>';
                } else {
                    echo '<div class="step-result">  Session is empty</div>';
                }
            } else {
                echo '<div class="step-result">  No active session found</div>';
            }
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 2: Destroy Session</div>';
            
            if ($sessionId !== '' && $sessionId !== false) {
                $_SESSION = [];
                
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }
                
                $destroyResult = session_destroy();
                
                if ($destroyResult) {
                    echo '<div class="step-result status-ok">✓ Session destroyed successfully</div>';
                    echo '<div class="step-result">  - Session ID: ' . htmlspecialchars($sessionId) . '</div>';
                    echo '<div class="step-result">  - Session data cleared</div>';
                    echo '<div class="step-result">  - Session cookie deleted</div>';
                } else {
                    echo '<div class="step-result status-error">✗ Failed to destroy session</div>';
                    $cleanupSuccess = false;
                }
            } else {
                echo '<div class="step-result">  No session to destroy</div>';
            }
            echo '</div>';
            
            echo '<div class="test-step">';
            echo '<div class="step-title">Step 3: Verify Cleanup</div>';
            
            try {
                $redis = new Redis();
                $redis->connect('redis', 6379);
                
                $keyExists = $redis->exists('PHPREDIS_SESSION:' . $sessionId);
                
                if ($keyExists === 0 || $keyExists === false) {
                    echo '<div class="step-result status-ok">✓ Session data removed from Redis</div>';
                } else {
                    echo '<div class="step-result">  Session key still exists in Redis (may be TTL-based)</div>';
                }
                
                $redis->close();
            } catch (Exception $e) {
                echo '<div class="step-result">  Could not verify Redis cleanup: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
            echo '</div>';
            
            echo '</div>';
            
            echo '<div class="test-section">';
            if ($cleanupSuccess) {
                echo '<h3 class="status-ok">✓ Cleanup Completed</h3>';
                echo '<p>The session has been successfully destroyed and cleaned up.</p>';
                echo '<p>You can now start a new test from the beginning.</p>';
            } else {
                echo '<h3 class="status-error">✗ Cleanup Failed</h3>';
                echo '<p>Please review the error messages above.</p>';
            }
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="test-section">';
            echo '<h3 class="status-error">✗ Cleanup Failed</h3>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        ?>
        
        <a href="index.php" class="back-link">← Back to Menu</a>
    </div>
</body>
</html>
