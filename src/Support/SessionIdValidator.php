<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Support;

use Uzulla\EnhancedRedisSessionHandler\Exception\InvalidSessionIdException;

/**
 * セッションID検証用のユーティリティクラス。
 *
 * このクラスはライブラリ全体で一貫したセッションID検証を提供します。
 */
class SessionIdValidator
{
    /**
     * 有効なセッションID文字の正規表現パターン。
     * 英数字、ハイフン、アンダースコアを許可。
     */
    public const VALID_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * セッションIDの最大許容長。
     */
    public const MAX_LENGTH = 256;

    /**
     * セッションIDの推奨最小長（セキュリティのため）。
     */
    public const MIN_RECOMMENDED_LENGTH = 16;

    /**
     * 例外をスローせずにセッションIDが有効かチェックする。
     *
     * 重要: このメソッドは入力が既にサニタイズ済みであることを期待します。
     * このメソッドにセッションIDを渡す前にSessionIdValidator::sanitize()を呼び出してください。
     *
     * @param string $sessionId 検証するセッションID（事前にサニタイズ済みである必要がある）
     * @return bool 有効な場合true、それ以外はfalse
     */
    public static function isValid(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        if (preg_match(self::VALID_PATTERN, $sessionId) !== 1) {
            return false;
        }

        if (strlen($sessionId) > self::MAX_LENGTH) {
            return false;
        }

        return true;
    }

    /**
     * セッションIDを検証し、無効な場合は例外をスローする。
     *
     * 重要: このメソッドは入力が既にサニタイズ済みであることを期待します。
     * このメソッドにセッションIDを渡す前にSessionIdValidator::sanitize()を呼び出してください。
     *
     * @param string $sessionId 検証するセッションID（事前にサニタイズ済みである必要がある）
     * @throws InvalidSessionIdException セッションIDが無効な場合
     */
    public static function validate(string $sessionId): void
    {
        if ($sessionId === '') {
            throw new InvalidSessionIdException('Session ID cannot be empty');
        }

        if (preg_match(self::VALID_PATTERN, $sessionId) !== 1) {
            throw new InvalidSessionIdException('Session ID contains invalid characters. Only alphanumeric, hyphen, and underscore allowed.');
        }

        if (strlen($sessionId) > self::MAX_LENGTH) {
            throw new InvalidSessionIdException(
                'Session ID exceeds maximum length of ' . self::MAX_LENGTH . ' characters'
            );
        }
    }

    /**
     * セッションIDが推奨最小長より短いかチェックする。
     *
     * @param string $sessionId チェックするセッションID
     * @return bool 推奨長より短い場合true、それ以外はfalse
     */
    public static function isShorterThanRecommended(string $sessionId): bool
    {
        return strlen($sessionId) < self::MIN_RECOMMENDED_LENGTH;
    }

    /**
     * 空白をトリムしてセッションIDをサニタイズする。
     *
     * @param string $sessionId サニタイズするセッションID
     * @return string サニタイズされたセッションID
     */
    public static function sanitize(string $sessionId): string
    {
        return trim($sessionId);
    }
}
