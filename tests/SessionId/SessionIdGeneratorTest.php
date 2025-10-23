<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\SessionId;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConfigurationException;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SecureSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class SessionIdGeneratorTest extends TestCase
{
    public function testDefaultSessionIdGeneratorImplementsInterface(): void
    {
        $generator = new DefaultSessionIdGenerator();
        self::assertInstanceOf(SessionIdGeneratorInterface::class, $generator);
    }

    public function testSecureSessionIdGeneratorImplementsInterface(): void
    {
        $generator = new SecureSessionIdGenerator();
        self::assertInstanceOf(SessionIdGeneratorInterface::class, $generator);
    }

    public function testDefaultSessionIdGeneratorGeneratesNonEmptyString(): void
    {
        $generator = new DefaultSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertNotEmpty($sessionId);
    }

    public function testDefaultSessionIdGeneratorGeneratesHexString(): void
    {
        $generator = new DefaultSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $sessionId);
    }

    public function testDefaultSessionIdGeneratorGenerates32CharacterString(): void
    {
        $generator = new DefaultSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertSame(32, strlen($sessionId));
    }

    public function testDefaultSessionIdGeneratorGeneratesUniqueIds(): void
    {
        $generator = new DefaultSessionIdGenerator();
        $sessionId1 = $generator->generate();
        $sessionId2 = $generator->generate();

        self::assertNotEquals($sessionId1, $sessionId2);
    }

    public function testSecureSessionIdGeneratorGeneratesNonEmptyString(): void
    {
        $generator = new SecureSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertNotEmpty($sessionId);
    }

    public function testSecureSessionIdGeneratorGeneratesHexString(): void
    {
        $generator = new SecureSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $sessionId);
    }

    public function testSecureSessionIdGeneratorDefaultLength(): void
    {
        $generator = new SecureSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertSame(32, strlen($sessionId));
    }

    public function testSecureSessionIdGeneratorCustomLength(): void
    {
        $generator = new SecureSessionIdGenerator(64);
        $sessionId = $generator->generate();

        self::assertSame(64, strlen($sessionId));
    }

    public function testSecureSessionIdGeneratorGeneratesUniqueIds(): void
    {
        $generator = new SecureSessionIdGenerator();
        $sessionId1 = $generator->generate();
        $sessionId2 = $generator->generate();

        self::assertNotEquals($sessionId1, $sessionId2);
    }

    public function testSecureSessionIdGeneratorThrowsExceptionForTooShortLength(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Session ID length must be at least 32 characters');

        new SecureSessionIdGenerator(30);
    }

    public function testSecureSessionIdGeneratorThrowsExceptionForOddLength(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Session ID length must be an even number');

        new SecureSessionIdGenerator(33);
    }

    public function testSecureSessionIdGeneratorAcceptsMinimumLength(): void
    {
        $generator = new SecureSessionIdGenerator(32);
        $sessionId = $generator->generate();

        self::assertSame(32, strlen($sessionId));
    }

    public function testSecureSessionIdGeneratorAcceptsLargeLength(): void
    {
        $generator = new SecureSessionIdGenerator(128);
        $sessionId = $generator->generate();

        self::assertSame(128, strlen($sessionId));
    }
}
