<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\SessionId;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\SessionId\PrefixedSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class PrefixedSessionIdGeneratorTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $generator = new PrefixedSessionIdGenerator();
        self::assertInstanceOf(SessionIdGeneratorInterface::class, $generator);
    }

    public function testGeneratesNonEmptyString(): void
    {
        $generator = new PrefixedSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertNotEmpty($sessionId);
    }

    public function testGeneratesStringWithDefaultPrefix(): void
    {
        $generator = new PrefixedSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertStringStartsWith('app_', $sessionId);
        self::assertMatchesRegularExpression('/^app_[0-9a-f]+$/', $sessionId);
    }

    public function testGeneratesStringWithCustomPrefix(): void
    {
        $generator = new PrefixedSessionIdGenerator('myapp');
        $sessionId = $generator->generate();

        self::assertStringStartsWith('myapp_', $sessionId);
        self::assertMatchesRegularExpression('/^myapp_[0-9a-f]+$/', $sessionId);
    }

    public function testGeneratesHexStringInRandomPart(): void
    {
        $generator = new PrefixedSessionIdGenerator('test');
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $randomPart);
    }

    public function testDefaultRandomPartLength(): void
    {
        $generator = new PrefixedSessionIdGenerator('test');
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(32, strlen($randomPart));
    }

    public function testCustomRandomPartLength(): void
    {
        $generator = new PrefixedSessionIdGenerator('test', 64);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(64, strlen($randomPart));
    }

    public function testGeneratesUniqueIds(): void
    {
        $generator = new PrefixedSessionIdGenerator('test');
        $sessionId1 = $generator->generate();
        $sessionId2 = $generator->generate();

        self::assertNotEquals($sessionId1, $sessionId2);
    }

    public function testThrowsExceptionForEmptyPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix cannot be empty');

        new PrefixedSessionIdGenerator('');
    }

    public function testThrowsExceptionForPrefixWithSpaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix can only contain alphanumeric characters and hyphens');

        new PrefixedSessionIdGenerator('app name');
    }

    public function testThrowsExceptionForPrefixWithUnderscore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix can only contain alphanumeric characters and hyphens');

        new PrefixedSessionIdGenerator('app_name');
    }

    public function testThrowsExceptionForPrefixWithSpecialCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix can only contain alphanumeric characters and hyphens');

        new PrefixedSessionIdGenerator('app@name');
    }

    public function testThrowsExceptionForPrefixWithSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix can only contain alphanumeric characters and hyphens');

        new PrefixedSessionIdGenerator('app/name');
    }

    public function testThrowsExceptionForPrefixWithDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix can only contain alphanumeric characters and hyphens');

        new PrefixedSessionIdGenerator('app.name');
    }

    public function testAcceptsPrefixWithHyphens(): void
    {
        $generator = new PrefixedSessionIdGenerator('my-app');
        $sessionId = $generator->generate();

        self::assertMatchesRegularExpression('/^my-app_[0-9a-f]+$/', $sessionId);
    }

    public function testAcceptsPrefixWithMultipleHyphens(): void
    {
        $generator = new PrefixedSessionIdGenerator('my-app-name');
        $sessionId = $generator->generate();

        self::assertMatchesRegularExpression('/^my-app-name_[0-9a-f]+$/', $sessionId);
    }

    public function testAcceptsPrefixWithNumbers(): void
    {
        $generator = new PrefixedSessionIdGenerator('app123');
        $sessionId = $generator->generate();

        self::assertMatchesRegularExpression('/^app123_[0-9a-f]+$/', $sessionId);
    }

    public function testAcceptsPrefixWithMixedCase(): void
    {
        $generator = new PrefixedSessionIdGenerator('MyApp');
        $sessionId = $generator->generate();

        self::assertMatchesRegularExpression('/^MyApp_[0-9a-f]+$/', $sessionId);
    }

    public function testThrowsExceptionForPrefixTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix length must be <= 64 characters');

        $longPrefix = str_repeat('a', 65);
        new PrefixedSessionIdGenerator($longPrefix);
    }

    public function testAcceptsPrefixAt64CharacterLimit(): void
    {
        $prefix = str_repeat('a', 64);
        $generator = new PrefixedSessionIdGenerator($prefix);
        $sessionId = $generator->generate();

        self::assertStringStartsWith($prefix . '_', $sessionId);
    }

    public function testThrowsExceptionForRandomLengthTooLarge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be <= 256 characters');

        new PrefixedSessionIdGenerator('test', 258);
    }

    public function testAcceptsRandomLengthAt256CharacterLimit(): void
    {
        $generator = new PrefixedSessionIdGenerator('test', 256);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(256, strlen($randomPart));
    }

    public function testThrowsExceptionForTooShortRandomLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be at least 16 characters');

        new PrefixedSessionIdGenerator('test', 14);
    }

    public function testThrowsExceptionForOddRandomLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be an even number');

        new PrefixedSessionIdGenerator('test', 33);
    }

    public function testAcceptsMinimumRandomLength(): void
    {
        $generator = new PrefixedSessionIdGenerator('test', 16);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(16, strlen($randomPart));
    }

    public function testAcceptsLargeRandomLength(): void
    {
        $generator = new PrefixedSessionIdGenerator('test', 128);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(128, strlen($randomPart));
    }

    public function testPrefixCanBeExtracted(): void
    {
        $generator = new PrefixedSessionIdGenerator('myapp');
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $extractedPrefix = $parts[0];

        self::assertSame('myapp', $extractedPrefix);
    }

    public function testDifferentPrefixesProduceDifferentSessionIds(): void
    {
        $generator1 = new PrefixedSessionIdGenerator('app1');
        $generator2 = new PrefixedSessionIdGenerator('app2');

        $sessionId1 = $generator1->generate();
        $sessionId2 = $generator2->generate();

        self::assertStringStartsWith('app1_', $sessionId1);
        self::assertStringStartsWith('app2_', $sessionId2);
        self::assertNotEquals($sessionId1, $sessionId2);
    }
}
