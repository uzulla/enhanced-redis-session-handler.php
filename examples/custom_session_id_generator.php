<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SecureSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$config = new RedisConnectionConfig(
    'localhost',
    6379,
    2.5,
    null,
    0,
    'session:'
);

$redis = new \Redis();
$connection = new RedisConnection($redis, $config, $logger);

echo "Example 1: Using DefaultSessionIdGenerator (32 characters)\n";
echo "============================================================\n";
$defaultGenerator = new DefaultSessionIdGenerator();
$options1 = new RedisSessionHandlerOptions($defaultGenerator, null, $logger);
$handler1 = new RedisSessionHandler($connection, $options1);
session_set_save_handler($handler1, true);
session_start();
echo "Session ID: " . session_id() . " (length: " . strlen(session_id()) . ")\n";
session_write_close();
echo "\n";

echo "Example 2: Using SecureSessionIdGenerator (64 characters)\n";
echo "==========================================================\n";
$secureGenerator = new SecureSessionIdGenerator(64);
$options2 = new RedisSessionHandlerOptions($secureGenerator, null, $logger);
$handler2 = new RedisSessionHandler($connection, $options2);
session_set_save_handler($handler2, true);
session_start();
echo "Session ID: " . session_id() . " (length: " . strlen(session_id()) . ")\n";
session_write_close();
echo "\n";

echo "Example 3: Using Custom SessionIdGenerator with prefix\n";
echo "=======================================================\n";
class PrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'app')
    {
        $this->prefix = $prefix;
    }

    public function generate(): string
    {
        return $this->prefix . '_' . bin2hex(random_bytes(16));
    }
}

$customGenerator = new PrefixedSessionIdGenerator('myapp');
$options3 = new RedisSessionHandlerOptions($customGenerator, null, $logger);
$handler3 = new RedisSessionHandler($connection, $options3);
session_set_save_handler($handler3, true);
session_start();
echo "Session ID: " . session_id() . " (length: " . strlen(session_id()) . ")\n";
session_write_close();
echo "\n";

echo "Example 4: Using Custom SessionIdGenerator with timestamp\n";
echo "==========================================================\n";
class TimestampedSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generate(): string
    {
        return time() . '_' . bin2hex(random_bytes(16));
    }
}

$timestampGenerator = new TimestampedSessionIdGenerator();
$options4 = new RedisSessionHandlerOptions($timestampGenerator, null, $logger);
$handler4 = new RedisSessionHandler($connection, $options4);
session_set_save_handler($handler4, true);
session_start();
echo "Session ID: " . session_id() . " (length: " . strlen(session_id()) . ")\n";
session_write_close();
echo "\n";

echo "All examples completed successfully!\n";
