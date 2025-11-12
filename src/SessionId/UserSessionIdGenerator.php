<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

use InvalidArgumentException;

/**
 * ユーザーID Prefix付きセッションID生成クラス
 *
 * ユーザーIDをセッションIDのプレフィックスとして持つことで、以下の機能を実現：
 * - ユーザー単位のセッション管理
 * - 強制ログアウト機能
 * - セキュアなセッション管理（セッションフィクセーション攻撃対策）
 *
 * 使用例:
 * ```php
 * $generator = new UserSessionIdGenerator();
 *
 * // 初期セッション（匿名）
 * $sessionId = $generator->generate(); // "anon_a1b2c3d4..."
 *
 * // ログイン後、ユーザーIDを設定
 * $generator->setUserId('123');
 * session_regenerate_id(true);
 * $sessionId = $generator->generate(); // "user123_e5f6g7h8..."
 * ```
 */
class UserSessionIdGenerator implements SessionIdGeneratorInterface
{
    /**
     * 現在設定されているユーザーID
     */
    private ?string $userId = null;

    /**
     * セッションIDのランダム部分の長さ（16進数文字列）
     */
    private int $randomLength;

    /**
     * 匿名セッションのプレフィックス
     */
    private string $anonymousPrefix;

    /**
     * コンストラクタ
     *
     * @param int $randomLength ランダム部分の長さ（16進数文字列、偶数、デフォルト: 32）
     * @param string $anonymousPrefix 匿名セッションのプレフィックス（デフォルト: 'anon'）
     * @throws InvalidArgumentException バリデーションエラー時
     */
    public function __construct(int $randomLength = 32, string $anonymousPrefix = 'anon')
    {
        if ($randomLength < 16) {
            throw new InvalidArgumentException(
                'Random part length must be at least 16 characters'
            );
        }
        if ($randomLength > 256) {
            throw new InvalidArgumentException(
                'Random part length must be <= 256 characters'
            );
        }
        if ($randomLength % 2 !== 0) {
            throw new InvalidArgumentException(
                'Random part length must be an even number'
            );
        }

        if ($anonymousPrefix === '') {
            throw new InvalidArgumentException('Anonymous prefix cannot be empty');
        }
        if (preg_match('/^[a-zA-Z0-9-]+$/', $anonymousPrefix) !== 1) {
            throw new InvalidArgumentException(
                'Anonymous prefix can only contain alphanumeric characters and hyphens'
            );
        }
        if (strlen($anonymousPrefix) > 64) {
            throw new InvalidArgumentException(
                'Anonymous prefix length must be <= 64 characters'
            );
        }

        $this->randomLength = $randomLength;
        $this->anonymousPrefix = $anonymousPrefix;
    }

    /**
     * セッションIDを生成
     *
     * ユーザーIDが設定されている場合: "user{userId}_{random}"
     * 未設定の場合: "{anonymousPrefix}_{random}"
     *
     * @return string 生成されたセッションID
     */
    public function generate(): string
    {
        $byteLength = (int)($this->randomLength / 2);
        assert($byteLength >= 1);
        $randomPart = bin2hex(random_bytes($byteLength));

        if ($this->userId !== null) {
            return 'user' . $this->userId . '_' . $randomPart;
        }

        return $this->anonymousPrefix . '_' . $randomPart;
    }

    /**
     * ユーザーIDを設定
     *
     * この後、アプリケーション側でsession_regenerate_id(true)を呼び出す必要がある
     *
     * @param string $userId ユーザーID（数字または英数字、64文字以下）
     * @throws InvalidArgumentException ユーザーIDが無効な場合
     * @return void
     */
    public function setUserId(string $userId): void
    {
        $this->validateUserId($userId);
        $this->userId = $userId;
    }

    /**
     * 現在設定されているユーザーIDを取得
     *
     * @return string|null 未設定の場合はnull
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * ユーザーIDが設定されているかチェック
     *
     * @return bool
     */
    public function hasUserId(): bool
    {
        return $this->userId !== null;
    }

    /**
     * ユーザーIDをクリア（ログアウト時に使用）
     *
     * @return void
     */
    public function clearUserId(): void
    {
        $this->userId = null;
    }

    /**
     * ユーザーIDのバリデーション
     *
     * @param string $userId
     * @throws InvalidArgumentException ユーザーIDが無効な場合
     * @return void
     */
    private function validateUserId(string $userId): void
    {
        if ($userId === '') {
            throw new InvalidArgumentException('User ID cannot be empty');
        }

        if (strlen($userId) > 64) {
            throw new InvalidArgumentException('User ID too long (max 64 chars)');
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $userId) !== 1) {
            throw new InvalidArgumentException(
                'Invalid user ID format (alphanumeric, underscore, and hyphen only)'
            );
        }

        if (preg_match('/^(anon|user)/', $userId) === 1) {
            throw new InvalidArgumentException(
                'User ID cannot start with reserved prefix (anon, user)'
            );
        }
    }
}
