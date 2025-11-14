<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\SessionId\UserSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

/**
 * ユーザーセッション管理ヘルパークラス
 *
 * UserSessionIdGeneratorと連携して、ユーザー単位のセッション管理機能を提供：
 * - ログイン時のセッションID再生成
 * - 強制ログアウト機能
 * - セッション監査機能
 *
 * 使用例:
 * ```php
 * // ログイン成功後
 * $helper->setUserIdAndRegenerate('123');
 *
 * // 管理機能: 特定ユーザーの全セッションを削除
 * $deletedCount = $helper->forceLogoutUser('123');
 *
 * // セッション監査
 * $sessions = $helper->getUserSessions('123');
 * ```
 */
class UserSessionHelper
{
    private UserSessionIdGenerator $generator;
    private RedisConnection $connection;
    private LoggerInterface $logger;

    /**
     * コンストラクタ
     *
     * @param UserSessionIdGenerator $generator セッションIDジェネレータ
     * @param RedisConnection $connection Redis接続
     * @param LoggerInterface $logger ロガー
     */
    public function __construct(
        UserSessionIdGenerator $generator,
        RedisConnection $connection,
        LoggerInterface $logger
    ) {
        $this->generator = $generator;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * ユーザーIDを設定し、セッションIDを再生成
     *
     * この1つのメソッドで以下を実行：
     * 1. ユーザーIDをジェネレータに設定
     * 2. session_regenerate_id(true)を実行
     * 3. ログ記録
     *
     * 【重要】このメソッドはsession_start()の後に呼び出す必要があります。
     * セッションが開始されていない場合、LogicExceptionが投げられます。
     *
     * @param string $userId ユーザーID
     * @return bool 成功した場合true
     * @throws InvalidArgumentException ユーザーIDが無効な場合
     * @throws \LogicException セッションが開始されていない場合
     */
    public function setUserIdAndRegenerate(string $userId): bool
    {
        // セッションがアクティブかどうかを明示的にチェック
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->logger->error('Session is not active. Call session_start() before this method.', [
                'user_id' => $userId,
                'session_status' => session_status(),
            ]);
            throw new \LogicException('Session is not active. Call session_start() before this method.');
        }

        $oldSessionId = session_id();
        if ($oldSessionId === false || $oldSessionId === '') {
            $this->logger->error('Session ID not available', [
                'user_id' => $userId,
            ]);
            return false;
        }

        // ユーザーIDを設定（バリデーションはジェネレータ内で実施）
        $this->generator->setUserId($userId);

        // セッションIDを再生成（古いセッションを削除）
        if (!session_regenerate_id(true)) {
            $this->logger->error('Failed to regenerate session ID', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $newSessionId = session_id();
        if ($newSessionId === false) {
            $this->logger->error('New session ID not available', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $this->logger->info('User session regenerated', [
            'user_id' => $userId,
            'old_session_id' => SessionIdMasker::mask($oldSessionId),
            'new_session_id' => SessionIdMasker::mask($newSessionId),
        ]);

        return true;
    }

    /**
     * 特定ユーザーの全セッションを強制削除
     *
     * user{userId}_* パターンのRedisキーを全て削除
     *
     * @param string $userId ユーザーID
     * @return int 削除されたセッション数
     */
    public function forceLogoutUser(string $userId): int
    {
        $escapedUserId = $this->escapeRedisPattern($userId);
        $pattern = 'user' . $escapedUserId . '_*';
        $sessionKeys = $this->connection->keys($pattern);

        if (count($sessionKeys) === 0) {
            $this->logger->info('No active sessions found for user', [
                'user_id' => $userId,
            ]);
            return 0;
        }

        $deletedCount = 0;
        foreach ($sessionKeys as $key) {
            if ($this->connection->delete($key)) {
                $deletedCount++;
                $this->logger->debug('Session deleted', [
                    'user_id' => $userId,
                    'session_id' => SessionIdMasker::mask($key),
                ]);
            }
        }

        $this->logger->info('User sessions force logged out', [
            'user_id' => $userId,
            'deleted_count' => $deletedCount,
            'total_found' => count($sessionKeys),
        ]);

        return $deletedCount;
    }

    /**
     * 特定ユーザーのアクティブセッション一覧を取得
     *
     * @param string $userId ユーザーID
     * @return array<string, array{
     *     session_id: string,
     *     data_size: int
     * }>
     */
    public function getUserSessions(string $userId): array
    {
        $escapedUserId = $this->escapeRedisPattern($userId);
        $pattern = 'user' . $escapedUserId . '_*';
        $sessionKeys = $this->connection->keys($pattern);

        $sessions = [];
        foreach ($sessionKeys as $key) {
            $data = $this->connection->get($key);
            if ($data === false) {
                continue;
            }

            $sessions[$key] = [
                'session_id' => SessionIdMasker::mask($key),
                'data_size' => strlen($data),
            ];
        }

        $this->logger->debug('Retrieved user sessions', [
            'user_id' => $userId,
            'session_count' => count($sessions),
        ]);

        return $sessions;
    }

    /**
     * 特定ユーザーのアクティブセッション数を取得
     *
     * @param string $userId ユーザーID
     * @return int セッション数
     */
    public function countUserSessions(string $userId): int
    {
        $escapedUserId = $this->escapeRedisPattern($userId);
        $pattern = 'user' . $escapedUserId . '_*';
        $sessionKeys = $this->connection->keys($pattern);

        return count($sessionKeys);
    }

    /**
     * Redisパターン用に特殊文字をエスケープ
     *
     * Redis KEYS/SCAN コマンドで使用される特殊文字をエスケープします：
     * - * : 任意の文字列にマッチ
     * - ? : 任意の1文字にマッチ
     * - [ ] : 文字クラス
     * - \ : エスケープ文字
     *
     * このメソッドは防御的プログラミングの一環として、バリデーション済みの
     * ユーザーIDに対しても明示的なエスケープを行います。
     *
     * @param string $userId エスケープ対象のユーザーID
     * @return string エスケープ済みのユーザーID
     */
    private function escapeRedisPattern(string $userId): string
    {
        // バックスラッシュを最初にエスケープ（二重エスケープを防ぐため）
        $escaped = str_replace('\\', '\\\\', $userId);

        // Redis特殊文字をエスケープ
        $specialChars = ['*', '?', '[', ']'];
        foreach ($specialChars as $char) {
            $escaped = str_replace($char, '\\' . $char, $escaped);
        }

        return $escaped;
    }
}
