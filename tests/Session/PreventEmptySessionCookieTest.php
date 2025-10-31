<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Session;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializer;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;

class PreventEmptySessionCookieTest extends TestCase
{
    private PsrTestLogger $logger;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        PreventEmptySessionCookie::reset();

        $this->logger = new PsrTestLogger();

        $redis = new \Redis();
        $config = new RedisConnectionConfig('127.0.0.1', 6379);
        $connection = new RedisConnection($redis, $config, $this->logger);
        $serializer = new PhpSerializer();

        $this->handler = new RedisSessionHandler($connection, $serializer);
    }

    protected function tearDown(): void
    {
        PreventEmptySessionCookie::reset();
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupRegistersFilterWithHandler(): void
    {
        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        self::assertTrue($this->logger->hasDebugRecords(), 'Logger should have debug records');

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Registered empty session cleanup handler') {
                $found = true;
                break;
            }
        }

        if (!isset($_COOKIE[session_name()])) {
            self::assertTrue($found, 'Expected log message about cleanup handler registration not found');
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupCanBeCalledMultipleTimes(): void
    {
        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        self::assertInstanceOf(PsrTestLogger::class, $this->logger);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupPreventsMultipleInitialization(): void
    {
        $initialLogCount = count($this->logger->getRecords());

        PreventEmptySessionCookie::setup($this->handler, $this->logger);
        $afterFirstCall = count($this->logger->getRecords());

        PreventEmptySessionCookie::setup($this->handler, $this->logger);
        $afterSecondCall = count($this->logger->getRecords());

        self::assertSame(
            $afterFirstCall,
            $afterSecondCall,
            'Second setup() call should not add new log entries (initialization prevented)'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testResetClearsInternalState(): void
    {
        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        PreventEmptySessionCookie::reset();

        $logCountBeforeSecondSetup = count($this->logger->getRecords());
        PreventEmptySessionCookie::setup($this->handler, $this->logger);
        $logCountAfterSecondSetup = count($this->logger->getRecords());

        self::assertGreaterThan(
            $logCountBeforeSecondSetup,
            $logCountAfterSecondSetup,
            'After reset(), setup() should work again and add new log entries'
        );
    }

    public function testCheckAndCleanupDoesNothingWhenSessionNotActive(): void
    {
        PreventEmptySessionCookie::checkAndCleanup();

        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testCheckAndCleanupDoesNothingWhenFilterIsNull(): void
    {
        PreventEmptySessionCookie::checkAndCleanup();

        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupRegistersSessionHandler(): void
    {
        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        self::assertInstanceOf(RedisSessionHandler::class, $this->handler);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupWithExistingSessionCookie(): void
    {
        $_COOKIE[session_name()] = 'existing_session_id';

        try {
            PreventEmptySessionCookie::setup($this->handler, $this->logger);

            $records = $this->logger->getRecords();
            $found = false;
            foreach ($records as $record) {
                if ($record['message'] === 'Registered empty session cleanup handler') {
                    $found = true;
                    break;
                }
            }

            self::assertFalse($found, 'Cleanup handler should not be registered when session cookie exists');
        } finally {
            unset($_COOKIE[session_name()]);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupWithoutExistingSessionCookie(): void
    {
        if (isset($_COOKIE[session_name()])) {
            $originalCookie = $_COOKIE[session_name()];
            unset($_COOKIE[session_name()]);
        }

        try {
            PreventEmptySessionCookie::setup($this->handler, $this->logger);

            $records = $this->logger->getRecords();
            $found = false;
            foreach ($records as $record) {
                if ($record['message'] === 'Registered empty session cleanup handler') {
                    $found = true;
                    break;
                }
            }

            self::assertTrue($found, 'Cleanup handler should be registered when no session cookie exists');
        } finally {
            if (isset($originalCookie)) {
                $_COOKIE[session_name()] = $originalCookie;
            }
        }
    }
}
