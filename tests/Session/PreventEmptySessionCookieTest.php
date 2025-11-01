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
        $redis = new \Redis();
        $config = new RedisConnectionConfig('127.0.0.1', 6379);
        $connection = new RedisConnection($redis, $config, $this->logger);
        $serializer = new PhpSerializer();

        $spy = new class ($connection, $serializer) extends RedisSessionHandler {
            public ?\Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface $capturedFilter = null;

            public function addWriteFilter(\Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface $filter): void
            {
                $this->capturedFilter = $filter;
                parent::addWriteFilter($filter);
            }
        };

        PreventEmptySessionCookie::setup($spy, $this->logger);

        self::assertInstanceOf(
            \Uzulla\EnhancedRedisSessionHandler\Hook\EmptySessionFilter::class,
            $spy->capturedFilter,
            'setup() should register an EmptySessionFilter with the handler'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupCanBeCalledMultipleTimes(): void
    {
        $redis = new \Redis();
        $config = new RedisConnectionConfig('127.0.0.1', 6379);
        $connection = new RedisConnection($redis, $config, $this->logger);
        $serializer = new PhpSerializer();

        $spy = new class ($connection, $serializer) extends RedisSessionHandler {
            public int $addWriteFilterCalls = 0;

            public function addWriteFilter(\Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface $filter): void
            {
                $this->addWriteFilterCalls++;
                parent::addWriteFilter($filter);
            }
        };

        PreventEmptySessionCookie::setup($spy, $this->logger);
        PreventEmptySessionCookie::setup($spy, $this->logger);
        PreventEmptySessionCookie::setup($spy, $this->logger);

        self::assertSame(1, $spy->addWriteFilterCalls, 'addWriteFilter should only be called once despite multiple setup() calls');
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupPreventsMultipleInitialization(): void
    {
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

    /**
     * @runInSeparateProcess
     */
    public function testCheckAndCleanupDoesNothingWhenSessionNotActive(): void
    {
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'Precondition: session should not be active');

        PreventEmptySessionCookie::checkAndCleanup();

        self::assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'Session should remain not active after checkAndCleanup()');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCheckAndCleanupDoesNothingWhenFilterIsNull(): void
    {
        session_start();

        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'Session should be active');

        PreventEmptySessionCookie::checkAndCleanup();

        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'Session should remain active when filter is null');
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetupRegistersSessionHandler(): void
    {
        $moduleNameBefore = session_module_name();

        PreventEmptySessionCookie::setup($this->handler, $this->logger);

        self::assertSame('user', session_module_name(), 'setup() should register a user-level session handler via session_set_save_handler()');
        self::assertNotSame($moduleNameBefore, session_module_name(), 'Session module name should change after setup()');
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
