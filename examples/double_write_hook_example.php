<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$primaryRedis = new Redis();
$primaryConfig = new RedisConnectionConfig('localhost', 6379, 0);
$primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, $logger);

$secondaryRedis = new Redis();
$secondaryConfig = new RedisConnectionConfig('localhost', 6379, 1);
$secondaryConnection = new RedisConnection($secondaryRedis, $secondaryConfig, $logger);

$options = new RedisSessionHandlerOptions(null, null, $logger);
$handler = new RedisSessionHandler($primaryConnection, $options);

$doubleWriteHook = new DoubleWriteHook(
    $secondaryConnection,
    1440,
    false,
    $logger
);

$handler->addWriteHook($doubleWriteHook);

session_set_save_handler($handler, true);
session_start();

$_SESSION['user_id'] = 123;
$_SESSION['username'] = 'testuser';
$_SESSION['last_activity'] = time();

echo "Session data written to both primary and secondary Redis instances.\n";
echo "Session ID: " . session_id() . "\n";

session_write_close();

echo "Session data has been persisted.\n";
