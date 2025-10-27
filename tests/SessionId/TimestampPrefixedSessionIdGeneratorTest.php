<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\SessionId;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\TimestampPrefixedSessionIdGenerator;

class TimestampPrefixedSessionIdGeneratorTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator();
        self::assertInstanceOf(SessionIdGeneratorInterface::class, $generator);
    }

    public function testGeneratesNonEmptyString(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertNotEmpty($sessionId);
    }

    public function testGeneratesStringWithTimestampPrefix(): void
    {
        $beforeTimestamp = time();
        $generator = new TimestampPrefixedSessionIdGenerator();
        $sessionId = $generator->generate();
        $afterTimestamp = time();

        self::assertMatchesRegularExpression('/^\d+_[0-9a-f]+$/', $sessionId);

        $parts = explode('_', $sessionId);
        self::assertCount(2, $parts);

        $timestamp = (int)$parts[0];
        self::assertGreaterThanOrEqual($beforeTimestamp, $timestamp);
        self::assertLessThanOrEqual($afterTimestamp, $timestamp);
    }

    public function testGeneratesHexStringInRandomPart(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator();
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $randomPart);
    }

    public function testDefaultRandomPartLength(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator();
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(32, strlen($randomPart));
    }

    public function testCustomRandomPartLength(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator(64);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(64, strlen($randomPart));
    }

    public function testGeneratesUniqueIds(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator();
        $sessionId1 = $generator->generate();
        $sessionId2 = $generator->generate();

        self::assertNotEquals($sessionId1, $sessionId2);
    }

    public function testThrowsExceptionForTooShortRandomLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be at least 16 characters');

        new TimestampPrefixedSessionIdGenerator(14);
    }

    public function testThrowsExceptionForOddRandomLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be an even number');

        new TimestampPrefixedSessionIdGenerator(33);
    }

    public function testAcceptsMinimumRandomLength(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator(16);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(16, strlen($randomPart));
    }

    public function testAcceptsLargeRandomLength(): void
    {
        $generator = new TimestampPrefixedSessionIdGenerator(128);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(128, strlen($randomPart));
    }

    public function testTimestampCanBeExtracted(): void
    {
        $beforeTimestamp = time();
        $generator = new TimestampPrefixedSessionIdGenerator();
        $sessionId = $generator->generate();
        $afterTimestamp = time();

        $parts = explode('_', $sessionId);
        $extractedTimestamp = (int)$parts[0];

        self::assertGreaterThanOrEqual($beforeTimestamp, $extractedTimestamp);
        self::assertLessThanOrEqual($afterTimestamp, $extractedTimestamp);
    }
}
