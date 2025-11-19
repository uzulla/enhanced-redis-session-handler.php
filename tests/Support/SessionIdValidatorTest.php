<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdValidator;

class SessionIdValidatorTest extends TestCase
{
    public function testIsValidReturnsTrueForValidIds(): void
    {
        self::assertTrue(SessionIdValidator::isValid('abc123'));
        self::assertTrue(SessionIdValidator::isValid('session_id_123'));
        self::assertTrue(SessionIdValidator::isValid('session-id-123'));
        self::assertTrue(SessionIdValidator::isValid('ABC123xyz'));
    }

    public function testIsValidReturnsFalseForEmptyId(): void
    {
        self::assertFalse(SessionIdValidator::isValid(''));
    }

    public function testIsValidReturnsFalseForInvalidCharacters(): void
    {
        self::assertFalse(SessionIdValidator::isValid('invalid/session'));
        self::assertFalse(SessionIdValidator::isValid('invalid<session>'));
        self::assertFalse(SessionIdValidator::isValid('session with spaces'));
        self::assertFalse(SessionIdValidator::isValid('session.with.dots'));
    }

    public function testIsValidReturnsFalseForTooLongId(): void
    {
        $tooLongId = str_repeat('a', 257);
        self::assertFalse(SessionIdValidator::isValid($tooLongId));
    }

    public function testIsValidReturnsTrueForMaxLengthId(): void
    {
        $maxLengthId = str_repeat('a', 256);
        self::assertTrue(SessionIdValidator::isValid($maxLengthId));
    }

    public function testIsValidWithTrimOption(): void
    {
        self::assertTrue(SessionIdValidator::isValid('  valid_session  ', true));
        self::assertFalse(SessionIdValidator::isValid('  valid_session  ', false));
    }

    public function testValidateThrowsExceptionForEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Session ID cannot be empty');

        SessionIdValidator::validate('');
    }

    public function testValidateThrowsExceptionForInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Session ID contains invalid characters');

        SessionIdValidator::validate('invalid/session');
    }

    public function testValidateThrowsExceptionForTooLongId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Session ID exceeds maximum length');

        $tooLongId = str_repeat('a', 257);
        SessionIdValidator::validate($tooLongId);
    }

    public function testValidateDoesNotThrowForValidId(): void
    {
        SessionIdValidator::validate('valid_session_id');
        SessionIdValidator::validate(str_repeat('a', 256));

        $this->addToAssertionCount(2);
    }

    public function testIsShorterThanRecommended(): void
    {
        self::assertTrue(SessionIdValidator::isShorterThanRecommended('short'));
        self::assertTrue(SessionIdValidator::isShorterThanRecommended('123456789012345')); // 15 chars
        self::assertFalse(SessionIdValidator::isShorterThanRecommended('1234567890123456')); // 16 chars
        self::assertFalse(SessionIdValidator::isShorterThanRecommended('12345678901234567')); // 17 chars
    }

    public function testSanitize(): void
    {
        self::assertSame('valid_session', SessionIdValidator::sanitize('  valid_session  '));
        self::assertSame('session', SessionIdValidator::sanitize("\tsession\n"));
        self::assertSame('session', SessionIdValidator::sanitize('session'));
    }

    public function testConstants(): void
    {
        self::assertSame('/^[a-zA-Z0-9_-]+$/', SessionIdValidator::VALID_PATTERN);
        self::assertSame(256, SessionIdValidator::MAX_LENGTH);
        self::assertSame(16, SessionIdValidator::MIN_RECOMMENDED_LENGTH);
    }
}
