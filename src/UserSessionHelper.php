<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
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
     * 2. session_regenerate_id(false)でセッションIDを再生成
     * 3. 古いセッションデータをRedisから手動削除
     * 4. ログ記録
     *
     * 注意: カスタムセッションハンドラー使用時、session_regenerate_id(true)は
     * PHPのバージョンに関係なく「Session object destruction failed」エラーを
     * 引き起こす可能性があるため、falseを指定して手動削除する方式を採用しています。
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

        // セッションIDを再生成（falseを指定して古いセッションを保持）
        // カスタムセッションハンドラー使用時、session_regenerate_id(true)は
        // PHPバージョンに関係なく問題を起こす可能性があるため、手動削除方式を採用
        if (!session_regenerate_id(false)) {
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

        // 古いセッションデータを手動で削除
        // session_regenerate_id(true)の代わりにこの方法を使用することで、
        // カスタムセッションハンドラーと互換性を保つ
        try {
            $this->connection->delete($oldSessionId);
            $this->logger->debug('Old session deleted', [
                'old_session_id' => SessionIdMasker::mask($oldSessionId),
            ]);
        } catch (ConnectionException $e) {
            // Redis接続エラーを記録
            // 新しいセッションは既に作成済みなので処理は継続可能
            // 古いセッションは自動的にGCで削除されるため、warningレベルで記録
            $this->logger->warning('Failed to delete old session due to connection error', [
                'old_session_id' => SessionIdMasker::mask($oldSessionId),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // その他のエラー（期限切れセッションの削除失敗など）
            // 削除できない場合もあるため、処理は継続
            $this->logger->warning('Failed to delete old session', [
                'old_session_id' => SessionIdMasker::mask($oldSessionId),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
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
     * 指定されたユーザーIDに紐づく全てのセッションをRedisから削除します。
     * user{userId}_* パターンにマッチする全てのキーを検索し、順次削除します。
     *
     * 本番環境での安全性:
     * - Redis SCANコマンドを使用（非ブロッキング）
     * - 大量のセッションが存在しても他のRedis操作に影響しない
     * - ユーザーID内のRedis特殊文字（*?[]\）を自動エスケープ
     *
     * 重複除去:
     * Redis SCANは同じキーを複数回返す可能性がありますが、scan()メソッドが
     * 自動的に重複を除去するため、正確な削除件数が取得できます。
     *
     * 使用例:
     * ```php
     * // ユーザー「123」の全セッションを削除（強制ログアウト）
     * $deletedCount = $helper->forceLogoutUser('123');
     * echo "削除されたセッション数: {$deletedCount}";
     * ```
     *
     * @param string $userId ユーザーID（内部で自動的にエスケープされます）
     * @return int 削除されたセッション数（削除に失敗したセッションは含まれません）
     */
    public function forceLogoutUser(string $userId): int
    {
        $escapedUserId = $this->escapeRedisPattern($userId);
        $pattern = 'user' . $escapedUserId . '_*';
        $sessionKeys = $this->connection->scan($pattern);

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
     * 指定されたユーザーIDに紐づく全てのアクティブセッションの情報を取得します。
     * 各セッションのIDとデータサイズを含む配列を返します。
     *
     * 本番環境での安全性:
     * - Redis SCANコマンドを使用（非ブロッキング）
     * - ユーザーID内のRedis特殊文字を自動エスケープ
     * - セッション数が多くても他のRedis操作に影響しない
     *
     * セキュリティ:
     * - セッションIDはマスキングされて返されます（末尾4文字のみ表示）
     * - セッションデータの内容は返されません（データサイズのみ）
     *
     * 監査とデバッグ用途:
     * このメソッドは管理者がユーザーのセッション状況を監視・監査する目的で使用します。
     * - 複数デバイスからのログインチェック
     * - 異常なセッション数の検出
     * - セッションデータサイズの分析
     *
     * 使用例:
     * ```php
     * $sessions = $helper->getUserSessions('123');
     * foreach ($sessions as $key => $info) {
     *     echo "セッションキー: {$key}\n";
     *     echo "  ID: {$info['session_id']}\n";  // マスキング済み
     *     echo "  サイズ: {$info['data_size']} bytes\n";
     * }
     * ```
     *
     * @param string $userId ユーザーID（内部で自動的にエスケープされます）
     * @return array<string, array{
     *     session_id: string,
     *     data_size: int
     * }> セッションキーをキーとする連想配列。データ取得に失敗したセッションは含まれません。
     */
    public function getUserSessions(string $userId): array
    {
        $escapedUserId = $this->escapeRedisPattern($userId);
        $pattern = 'user' . $escapedUserId . '_*';
        $sessionKeys = $this->connection->scan($pattern);

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
     * 指定されたユーザーIDに紐づくアクティブセッションの総数を返します。
     * セッションデータの内容は取得せず、キーの存在のみをカウントします。
     *
     * 本番環境での安全性:
     * - Redis SCANコマンドを使用（非ブロッキング）
     * - ユーザーID内のRedis特殊文字を自動エスケープ
     * - 自動重複除去により正確なカウントを保証
     *
     * 正確性の保証:
     * Redis SCANは反復処理中に同じキーを複数回返す可能性がありますが、
     * scan()メソッドが自動的に重複を除去するため、常に正確なセッション数が取得できます。
     *
     * パフォーマンス特性:
     * - getUserSessions()よりも高速（セッションデータを取得しないため）
     * - 大量のセッションが存在する場合でも効率的
     * - メモリ使用量が少ない
     *
     * 使用例:
     * ```php
     * $count = $helper->countUserSessions('123');
     * if ($count > 5) {
     *     // 異常な同時ログイン数を検出
     *     $logger->warning('Multiple sessions detected', [
     *         'user_id' => '123',
     *         'session_count' => $count
     *     ]);
     * }
     * ```
     *
     * @param string $userId ユーザーID（内部で自動的にエスケープされます）
     * @return int アクティブセッション数（0以上の整数）
     */
    public function countUserSessions(string $userId): int
    {
        $escapedUserId = $this->escapeRedisPattern($userId);
        $pattern = 'user' . $escapedUserId . '_*';
        $sessionKeys = $this->connection->scan($pattern);

        return count($sessionKeys);
    }

    /**
     * Redisパターン用に特殊文字をエスケープ
     *
     * Redis SCAN/KEYSコマンドで使用されるglob-styleパターンの特殊文字をエスケープします。
     * これにより、ユーザーIDにRedis特殊文字が含まれる場合でも、意図しないパターンマッチを防ぎます。
     *
     * エスケープ対象の特殊文字:
     * - * : 任意の文字列にマッチ → \* にエスケープ
     * - ? : 任意の1文字にマッチ → \? にエスケープ
     * - [ ] : 文字クラス（例: [a-z]） → \[ \] にエスケープ
     * - \ : エスケープ文字自体 → \\ にエスケープ（最優先で処理）
     *
     * セキュリティ上の重要性:
     * このメソッドはパターンインジェクション攻撃を防ぐために重要です。
     * 例えば、ユーザーID「admin*」が「admin1」「admin2」等にもマッチしてしまう
     * 問題を防ぎます。
     *
     * 防御的プログラミング:
     * UserSessionIdGeneratorでユーザーIDのバリデーションを行っていますが、
     * 多層防御の観点から、このメソッドでも明示的なエスケープを実施します。
     *
     * エスケープ例:
     * - "user*test" → "user\*test"
     * - "user?test" → "user\?test"
     * - "user[123]" → "user\[123\]"
     * - "user\test" → "user\\test"
     * - "user*?[test]" → "user\*\?\[test\]"
     *
     * @param string $userId エスケープ対象のユーザーID
     * @return string エスケープ済みのユーザーID（Redisパターンとして安全に使用可能）
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
