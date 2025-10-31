<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Session;

use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\Hook\EmptySessionFilter;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

/**
 * Facade class for preventing empty session cookie transmission.
 *
 * This class provides a simple setup() method that configures the session handler
 * to prevent sending cookies for empty sessions. It works by:
 * 1. Registering an EmptySessionFilter to prevent writing empty sessions to Redis
 * 2. Registering a shutdown function that destroys empty sessions and removes cookies
 *
 * Usage:
 * <code>
 * $handler = $factory->build();
 * PreventEmptySessionCookie::setup($handler, $logger);
 * session_start();
 * </code>
 *
 * This approach minimizes changes to existing application code - only the session
 * initialization code needs to be modified.
 */
class PreventEmptySessionCookie
{
    /**
     * Time offset in seconds for setting cookie expiration in the past.
     *
     * This value is used when deleting session cookies by setting their expiration
     * to a past time. The specific value (42000 seconds ≈ 11.6 hours) is conventional
     * and follows PHP manual examples. Any sufficiently past time would work; the exact
     * value is not critical as long as it's far enough in the past to ensure browsers
     * delete the cookie.
     *
     * @var int
     */
    private const PAST_EXPIRATION_OFFSET_SECONDS = 42000;

    /**
     * Tracks whether setup() has been called to prevent duplicate initialization.
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * The EmptySessionFilter instance used to detect empty sessions.
     * This is stored to check wasLastWriteEmpty() in the shutdown function.
     *
     * @var EmptySessionFilter|null
     */
    private static ?EmptySessionFilter $filter = null;

    /**
     * Set up the session handler with empty session prevention.
     *
     * This method should be called before session_start(). It:
     * 1. Creates and registers an EmptySessionFilter with the handler
     * 2. Calls session_set_save_handler() to register the handler
     * 3. For new sessions (no existing cookie), registers a shutdown function
     *    that will destroy the session and remove the cookie if it remains empty
     *
     * Calling this method multiple times is safe - subsequent calls are ignored.
     *
     * @param RedisSessionHandler $handler The session handler to configure
     * @param LoggerInterface $logger PSR-3 compatible logger for debugging
     * @return void
     */
    public static function setup(RedisSessionHandler $handler, LoggerInterface $logger): void
    {
        if (self::$initialized) {
            return;
        }

        self::$filter = new EmptySessionFilter($logger);
        $handler->addWriteFilter(self::$filter);

        session_set_save_handler($handler, true);

        if (!isset($_COOKIE[session_name()])) {
            register_shutdown_function([self::class, 'checkAndCleanup']);
            $logger->debug('Registered empty session cleanup handler');
        }

        self::$initialized = true;
    }

    /**
     * Shutdown function that checks and cleans up empty sessions.
     *
     * This method is automatically called at request end (via register_shutdown_function)
     * for new sessions. It checks if the session remained empty, and if so:
     * 1. Calls session_destroy() to prevent writing to Redis
     * 2. Sends a Set-Cookie header with past expiration to remove the cookie
     *
     * Note: session_destroy() does NOT clear the $_SESSION array itself, but it does
     * prevent the session handler's write() method from being called, which prevents
     * the Redis write operation.
     *
     * @return void
     */
    public static function checkAndCleanup(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || self::$filter === null) {
            return;
        }

        if (self::$filter->wasLastWriteEmpty()) {
            session_destroy();

            if (!headers_sent()) {
                $params = session_get_cookie_params();
                $sessionName = session_name();
                // session_name() can return false, but only if called with invalid argument
                assert($sessionName !== false);

                setcookie(
                    $sessionName,
                    '',
                    time() - self::PAST_EXPIRATION_OFFSET_SECONDS,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
        }
    }

    /**
     * Reset the internal state (for testing purposes only).
     *
     * This method clears the initialized flag and filter instance,
     * allowing setup() to be called again in test scenarios.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$initialized = false;
        self::$filter = null;
    }
}
