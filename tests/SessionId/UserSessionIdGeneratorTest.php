<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\SessionId;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\SessionId\UserSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class UserSessionIdGeneratorTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $generator = new UserSessionIdGenerator();
        self::assertInstanceOf(SessionIdGeneratorInterface::class, $generator);
    }

    public function testGenerateWithoutUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $sessionId = $generator->generate();

        self::assertStringStartsWith('anon_', $sessionId);
        self::assertMatchesRegularExpression('/^anon_[0-9a-f]+$/', $sessionId);
    }

    public function testGenerateWithUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('123');
        $sessionId = $generator->generate();

        self::assertStringStartsWith('user123_', $sessionId);
        self::assertMatchesRegularExpression('/^user123_[0-9a-f]+$/', $sessionId);
    }

    public function testGenerateWithAlphanumericUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('abc123');
        $sessionId = $generator->generate();

        self::assertStringStartsWith('userabc123_', $sessionId);
        self::assertMatchesRegularExpression('/^userabc123_[0-9a-f]+$/', $sessionId);
    }

    public function testSetUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('123');

        self::assertSame('123', $generator->getUserId());
    }

    public function testGetUserIdReturnsNullByDefault(): void
    {
        $generator = new UserSessionIdGenerator();

        self::assertNull($generator->getUserId());
    }

    public function testHasUserIdReturnsFalseByDefault(): void
    {
        $generator = new UserSessionIdGenerator();

        self::assertFalse($generator->hasUserId());
    }

    public function testHasUserIdReturnsTrueAfterSet(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('123');

        self::assertTrue($generator->hasUserId());
    }

    public function testClearUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('123');
        $generator->clearUserId();

        self::assertNull($generator->getUserId());
        self::assertFalse($generator->hasUserId());
    }

    public function testGenerateAfterClearUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('123');
        $generator->clearUserId();
        $sessionId = $generator->generate();

        self::assertStringStartsWith('anon_', $sessionId);
    }

    public function testSetUserIdWithEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID cannot be empty');

        $generator = new UserSessionIdGenerator();
        $generator->setUserId('');
    }

    public function testSetUserIdTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID too long (max 64 chars)');

        $generator = new UserSessionIdGenerator();
        $longUserId = str_repeat('a', 65);
        $generator->setUserId($longUserId);
    }

    public function testSetUserIdWithInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user ID format');

        $generator = new UserSessionIdGenerator();
        $generator->setUserId('user@123');
    }

    public function testSetUserIdWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user ID format');

        $generator = new UserSessionIdGenerator();
        $generator->setUserId('user 123');
    }

    public function testSetUserIdWithReservedWordAnon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID cannot be a reserved word');

        $generator = new UserSessionIdGenerator();
        $generator->setUserId('anon');
    }

    public function testSetUserIdWithReservedWordUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID cannot be a reserved word');

        $generator = new UserSessionIdGenerator();
        $generator->setUserId('user');
    }

    public function testSetUserIdWithUsernameIsAllowed(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('username');

        self::assertSame('username', $generator->getUserId());
    }

    public function testSetUserIdWithUser123IsAllowed(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('user123');

        self::assertSame('user123', $generator->getUserId());
    }

    public function testSetUserIdWithAnonymousIsAllowed(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('anonymous');

        self::assertSame('anonymous', $generator->getUserId());
    }

    public function testSetUserIdWithAnonUserIsAllowed(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('anon-user');

        self::assertSame('anon-user', $generator->getUserId());
    }

    public function testSetUserIdWithHyphen(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('my-user-123');

        self::assertSame('my-user-123', $generator->getUserId());
    }

    public function testSetUserIdWithUnderscore(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('my_user_123');

        self::assertSame('my_user_123', $generator->getUserId());
    }

    public function testSetUserIdAt64CharacterLimit(): void
    {
        $userId = str_repeat('a', 64);
        $generator = new UserSessionIdGenerator();
        $generator->setUserId($userId);

        self::assertSame($userId, $generator->getUserId());
    }

    public function testConstructorWithCustomRandomLength(): void
    {
        $generator = new UserSessionIdGenerator(64);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(64, strlen($randomPart));
    }

    public function testConstructorWithCustomAnonymousPrefix(): void
    {
        $generator = new UserSessionIdGenerator(32, 'guest');
        $sessionId = $generator->generate();

        self::assertStringStartsWith('guest_', $sessionId);
    }

    public function testConstructorWithInvalidRandomLengthTooSmall(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be at least 16 characters');

        new UserSessionIdGenerator(14);
    }

    public function testConstructorWithInvalidRandomLengthTooLarge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be <= 256 characters');

        new UserSessionIdGenerator(258);
    }

    public function testConstructorWithInvalidRandomLengthOdd(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random part length must be an even number');

        new UserSessionIdGenerator(33);
    }

    public function testConstructorWithEmptyAnonymousPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anonymous prefix cannot be empty');

        new UserSessionIdGenerator(32, '');
    }

    public function testConstructorWithInvalidAnonymousPrefixCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anonymous prefix can only contain alphanumeric characters and hyphens');

        new UserSessionIdGenerator(32, 'guest_user');
    }

    public function testConstructorWithAnonymousPrefixTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anonymous prefix length must be <= 64 characters');

        $longPrefix = str_repeat('a', 65);
        new UserSessionIdGenerator(32, $longPrefix);
    }

    public function testConstructorWithAnonymousPrefixAt64CharacterLimit(): void
    {
        $prefix = str_repeat('a', 64);
        $generator = new UserSessionIdGenerator(32, $prefix);
        $sessionId = $generator->generate();

        self::assertStringStartsWith($prefix . '_', $sessionId);
    }

    public function testDefaultRandomPartLength(): void
    {
        $generator = new UserSessionIdGenerator();
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(32, strlen($randomPart));
    }

    public function testGeneratesUniqueIds(): void
    {
        $generator = new UserSessionIdGenerator();
        $sessionId1 = $generator->generate();
        $sessionId2 = $generator->generate();

        self::assertNotEquals($sessionId1, $sessionId2);
    }

    public function testGeneratesUniqueIdsWithUserId(): void
    {
        $generator = new UserSessionIdGenerator();
        $generator->setUserId('123');
        $sessionId1 = $generator->generate();
        $sessionId2 = $generator->generate();

        self::assertNotEquals($sessionId1, $sessionId2);
    }

    public function testRandomPartIsHexadecimal(): void
    {
        $generator = new UserSessionIdGenerator();
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $randomPart);
    }

    public function testUserIdChangeReflectedInGenerate(): void
    {
        $generator = new UserSessionIdGenerator();

        // 初期状態（匿名）
        $sessionId1 = $generator->generate();
        self::assertStringStartsWith('anon_', $sessionId1);

        // ユーザーID設定後
        $generator->setUserId('123');
        $sessionId2 = $generator->generate();
        self::assertStringStartsWith('user123_', $sessionId2);

        // ユーザーID変更後
        $generator->setUserId('456');
        $sessionId3 = $generator->generate();
        self::assertStringStartsWith('user456_', $sessionId3);
    }

    public function testMinimumRandomLength(): void
    {
        $generator = new UserSessionIdGenerator(16);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(16, strlen($randomPart));
    }

    public function testMaximumRandomLength(): void
    {
        $generator = new UserSessionIdGenerator(256);
        $sessionId = $generator->generate();

        $parts = explode('_', $sessionId);
        $randomPart = $parts[1];

        self::assertSame(256, strlen($randomPart));
    }
}
