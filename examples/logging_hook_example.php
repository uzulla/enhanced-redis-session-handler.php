<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$redis = new Redis();
$config = new RedisConnectionConfig('localhost', 6379);
$connection = new RedisConnection($redis, $config, $logger);

$options = new RedisSessionHandlerOptions(null, null, $logger);
$handler = new RedisSessionHandler($connection, $options);

$loggingHook = new LoggingHook(
    $logger,
    LogLevel::INFO,
    LogLevel::INFO,
    LogLevel::ERROR,
    false
);

$handler->addWriteHook($loggingHook);

session_set_save_handler($handler, true);
session_start();

$_SESSION['user_id'] = 456;
$_SESSION['username'] = 'loggeduser';
$_SESSION['login_time'] = time();

echo "Session operations are being logged.\n";
echo "Session ID: " . session_id() . "\n";

session_write_close();

echo "Check the logs above to see session write operations.\n";
