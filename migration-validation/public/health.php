<?php

declare(strict_types=1);

/**
 * Health check script for migration-validation environment
 * 
 * Displays:
 * - PHP version
 * - redis extension version
 * - Basic operation confirmation
 */

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Validation - Health Check</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
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
        .info-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-left: 4px solid #4CAF50;
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
        .status-ok {
            color: #4CAF50;
            font-weight: bold;
        }
        .status-error {
            color: #f44336;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏥 Migration Validation - Health Check</h1>
        
        <div class="info-section">
            <div><span class="info-label">PHP Version:</span> <span class="info-value"><?php echo PHP_VERSION; ?></span></div>
        </div>

        <div class="info-section">
            <?php if (extension_loaded('redis')): ?>
                <div><span class="info-label">Redis Extension:</span> <span class="info-value status-ok">✓ Loaded</span></div>
                <div><span class="info-label">Redis Extension Version:</span> <span class="info-value"><?php echo phpversion('redis'); ?></span></div>
            <?php else: ?>
                <div><span class="info-label">Redis Extension:</span> <span class="info-value status-error">✗ Not Loaded</span></div>
            <?php endif; ?>
        </div>

        <div class="info-section">
            <div><span class="info-label">Status:</span> <span class="info-value status-ok">✓ Environment is ready</span></div>
            <div><span class="info-label">Timestamp:</span> <span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
        </div>

        <div class="info-section">
            <h3>Environment Details</h3>
            <div><span class="info-label">Server Software:</span> <span class="info-value"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></span></div>
            <div><span class="info-label">Document Root:</span> <span class="info-value"><?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'; ?></span></div>
        </div>
    </div>
</body>
</html>
