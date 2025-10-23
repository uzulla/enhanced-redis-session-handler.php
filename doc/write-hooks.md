# Write Hooks

Write hooks provide a powerful mechanism to extend session write operations with custom functionality. They allow you to intercept and react to session write events at different stages of the write lifecycle.

## Overview

The `WriteHookInterface` defines three methods that are called at different points during a session write operation:

1. **beforeWrite**: Called before the session data is written to Redis
2. **afterWrite**: Called after the write operation completes (success or failure)
3. **onWriteError**: Called when an exception occurs during the write operation

## WriteHookInterface

```php
interface WriteHookInterface
{
    /**
     * Called before writing session data to Redis.
     *
     * @param string $sessionId The session ID
     * @param array<string, mixed> $data The unserialized session data
     * @return array<string, mixed> The modified session data
     */
    public function beforeWrite(string $sessionId, array $data): array;

    /**
     * Called after writing session data to Redis.
     *
     * @param string $sessionId The session ID
     * @param bool $success Whether the write operation was successful
     */
    public function afterWrite(string $sessionId, bool $success): void;

    /**
     * Called when an error occurs during the write operation.
     *
     * @param string $sessionId The session ID
     * @param \Throwable $exception The exception that occurred
     */
    public function onWriteError(string $sessionId, \Throwable $exception): void;
}
```

## Built-in Implementations

### LoggingHook

The `LoggingHook` provides comprehensive logging of session write operations using PSR-3 compatible loggers.

**Features:**
- Logs session write start, success, and failure events
- Configurable log levels for different events
- Optional session data logging (disabled by default for security)
- Detailed error information on write failures

**Example:**

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LogLevel;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('/var/log/sessions.log', Logger::INFO));

$loggingHook = new LoggingHook(
    $logger,
    LogLevel::INFO,      // beforeWrite log level
    LogLevel::INFO,      // afterWrite log level
    LogLevel::ERROR,     // onWriteError log level
    false                // log session data (false for security)
);

$handler->addWriteHook($loggingHook);
```

### DoubleWriteHook

The `DoubleWriteHook` writes session data to a secondary Redis instance, providing redundancy and backup capabilities.

**Features:**
- Writes to secondary Redis after primary write succeeds
- Configurable TTL for secondary storage
- Optional failure mode (fail or continue on secondary write errors)
- Comprehensive logging of secondary write operations

**Use Cases:**
- Creating backup copies of session data
- Replicating sessions across data centers
- Migrating sessions to a new Redis instance
- High availability setups

**Example:**

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;

$secondaryRedis = new Redis();
$secondaryConfig = new RedisConnectionConfig('backup-redis.example.com', 6379);
$secondaryConnection = new RedisConnection($secondaryRedis, $secondaryConfig, $logger);

$doubleWriteHook = new DoubleWriteHook(
    $secondaryConnection,
    1440,                // TTL in seconds
    false,               // fail on secondary error
    $logger
);

$handler->addWriteHook($doubleWriteHook);
```

## Creating Custom Hooks

You can create custom hooks by implementing the `WriteHookInterface`:

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;

class CustomAuditHook implements WriteHookInterface
{
    private $auditLogger;

    public function __construct($auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // Log audit trail before write
        $this->auditLogger->info('Session write initiated', [
            'session_id' => $sessionId,
            'user_id' => $data['user_id'] ?? null,
        ]);

        // Return data unchanged (or modify if needed)
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        // Log audit trail after write
        if ($success) {
            $this->auditLogger->info('Session write completed', [
                'session_id' => $sessionId,
            ]);
        }
    }

    public function onWriteError(string $sessionId, \Throwable $exception): void
    {
        // Log error to audit system
        $this->auditLogger->error('Session write failed', [
            'session_id' => $sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

## Hook Execution Order

When multiple hooks are registered, they are executed in the order they were added:

```php
$handler->addWriteHook($loggingHook);      // Executed first
$handler->addWriteHook($doubleWriteHook);  // Executed second
$handler->addWriteHook($customHook);       // Executed third
```

**beforeWrite** hooks are chained - each hook receives the data returned by the previous hook:

```php
// Hook 1 modifies data
public function beforeWrite(string $sessionId, array $data): array
{
    $data['processed_by_hook1'] = true;
    return $data;
}

// Hook 2 receives modified data from Hook 1
public function beforeWrite(string $sessionId, array $data): array
{
    // $data['processed_by_hook1'] is true here
    $data['processed_by_hook2'] = true;
    return $data;
}
```

## Error Handling

If an exception occurs during the write operation:

1. The `onWriteError` method is called on all registered hooks
2. The error is logged
3. The write operation returns `false`

Hooks should handle their own errors gracefully and not throw exceptions unless absolutely necessary.

## Best Practices

1. **Keep hooks lightweight**: Hooks are called on every session write, so keep processing minimal
2. **Handle errors gracefully**: Don't let hook failures break session functionality
3. **Use appropriate log levels**: Avoid excessive logging in production
4. **Be security conscious**: Don't log sensitive session data
5. **Test thoroughly**: Test hooks with various scenarios including error conditions
6. **Document behavior**: Clearly document what your custom hooks do

## Performance Considerations

- Hooks add overhead to session write operations
- Use asynchronous processing for expensive operations when possible
- Consider using write filters to skip unnecessary writes
- Monitor hook performance in production

## See Also

- [Write Filters](./write-filters.md) - For conditional write operations
- [Read Hooks](./read-hooks.md) - For intercepting read operations
- [Architecture](./architecture.md) - Overall system architecture
