<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Util;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Util\SessionIdMasker;

class SessionIdMaskerTest extends TestCase
{
    public function testMaskReturnsEmptyStringForEmptyInput(): void
    {
        $result = SessionIdMasker::mask('');

        self::assertSame('', $result);
    }

    public function testMaskReturnsOriginalForShortSessionId(): void
    {
        $sessionId = 'short';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame('short', $result);
    }

    public function testMaskReturnsOriginalForExactly8Characters(): void
    {
        $sessionId = '12345678';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame('12345678', $result);
    }

    public function testMaskMasksLongSessionIdCorrectly(): void
    {
        $sessionId = 'abcdef1234567890';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame('abcdef12********', $result);
        self::assertStringStartsWith('abcdef12', $result);
        self::assertStringContainsString('*', $result);
    }

    public function testMaskShowsFirst8CharactersOnly(): void
    {
        $sessionId = 'abcdefghijklmnopqrstuvwxyz';
        $result = SessionIdMasker::mask($sessionId);

        self::assertStringStartsWith('abcdefgh', $result);
        self::assertStringNotContainsString('i', $result);
        self::assertStringNotContainsString('j', $result);
    }

    public function testMaskPreservesCorrectLength(): void
    {
        $sessionId = 'abcdef1234567890';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame(strlen($sessionId), strlen($result));
    }

    public function testMaskHandlesVeryLongSessionId(): void
    {
        $sessionId = str_repeat('a', 100);
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame(100, strlen($result));
        self::assertStringStartsWith('aaaaaaaa', $result);
        self::assertSame(str_repeat('a', 8) . str_repeat('*', 92), $result);
    }

    public function testMaskHandles9CharacterSessionId(): void
    {
        $sessionId = '123456789';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame('12345678*', $result);
    }

    public function testMaskHandlesTypical32CharacterSessionId(): void
    {
        $sessionId = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame('a1b2c3d4************************', $result);
        self::assertSame(32, strlen($result));
    }

    public function testMaskHandlesSpecialCharactersInSessionId(): void
    {
        $sessionId = 'abc-def_123.456!';
        $result = SessionIdMasker::mask($sessionId);

        self::assertSame('abc-def_********', $result);
    }

    public function testMaskIsConsistentForSameInput(): void
    {
        $sessionId = 'test_session_id_12345678';
        $result1 = SessionIdMasker::mask($sessionId);
        $result2 = SessionIdMasker::mask($sessionId);

        self::assertSame($result1, $result2);
    }
}
