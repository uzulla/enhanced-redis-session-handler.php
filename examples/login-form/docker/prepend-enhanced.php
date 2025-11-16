<?php

/**
 * Auto-prepend file for Enhanced Redis Session Handler
 * enhanced-redis-session-handler用の自動プリペンドファイル
 *
 * このファイルは Apache の php_value auto_prepend_file 設定により
 * すべてのPHPスクリプトの実行前に自動的に読み込まれます。
 *
 * This file is automatically loaded before all PHP scripts
 * via Apache's php_value auto_prepend_file setting.
 */

// Force handler parameter to 'enhanced'
// ハンドラーパラメータを 'enhanced' に強制
$_GET['handler'] = 'enhanced';
$_POST['handler'] = 'enhanced';
