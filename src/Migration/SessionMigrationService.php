<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Migration;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Exception\InvalidSessionIdException;
use Uzulla\EnhancedRedisSessionHandler\Exception\MigrationException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdValidator;

/**
 * セッションデータを新しいセッションIDに移行するサービス。
 *
 * このサービスは以下の機能を提供します：
 * - セッションデータを別のセッションIDにコピー
 * - ブラウザのセッションクッキーを新しいIDに更新
 * - 古いセッションを削除（他のブラウザを実質的にログアウト）
 *
 * 使用例:
 * ```php
 * $migrator = new SessionMigrationService($redisConnection, $ttl);
 * $migrator->migrate($newSessionId, $deleteOldSession);
 * ```
 */
class SessionMigrationService
{
    private RedisConnection $connection;
    private int $ttl;
    private LoggerInterface $logger;
    private SessionSerializerInterface $serializer;

    /**
     * @param RedisConnection $connection セッションストレージ用のRedis接続
     * @param int $ttl セッションデータの生存時間（秒）
     * @param SessionSerializerInterface|null $serializer オプションのシリアライザ（デフォルトはPhpSerializeSerializer）
     * @param LoggerInterface|null $logger オプションのロガー（デバッグ用）
     */
    public function __construct(
        RedisConnection $connection,
        int $ttl,
        ?SessionSerializerInterface $serializer = null,
        ?LoggerInterface $logger = null
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }

        $this->connection = $connection;
        $this->ttl = $ttl;
        $this->serializer = $serializer ?? new PhpSerializeSerializer();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 現在のセッションを新しいセッションIDに移行する。
     *
     * このメソッドは以下の処理を実行します：
     * 1. $_SESSIONから現在のセッションデータを読み取る
     * 2. データを新しいセッションIDでRedisに書き込む
     * 3. PHPのセッションIDとクッキーを更新する
     * 4. 必要に応じて古いセッションを削除する
     *
     * 重要: このメソッドはセッションがアクティブな状態（session_start()後）で呼び出す必要があります。
     * このメソッド呼び出し後、ブラウザには新しいセッションクッキーが設定され、
     * 古いセッションIDを使用している他のブラウザはログアウトされます。
     *
     * @param string $newSessionId 移行先のセッションID
     * @param bool $deleteOldSession 古いセッションデータを削除するか（デフォルト: true）
     * @throws MigrationException 移行に失敗した場合
     * @throws InvalidSessionIdException セッションIDが無効な場合
     */
    public function migrate(string $newSessionId, bool $deleteOldSession = true): void
    {
        $this->validateSessionId($newSessionId);

        // ターゲットセッションIDが既に存在していないかチェックし、他ユーザーのセッション上書きを防止
        if ($this->connection->exists($newSessionId)) {
            throw new MigrationException('Target session ID already exists');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new MigrationException('Session must be active before migration. Call session_start() first.');
        }

        $oldSessionId = session_id();
        if ($oldSessionId === false || $oldSessionId === '') {
            throw new MigrationException('Could not get current session ID');
        }

        if ($oldSessionId === $newSessionId) {
            $this->logger->debug('Migration skipped: new session ID is same as current', [
                'session_id' => SessionIdMasker::mask($newSessionId),
            ]);
            return;
        }

        $this->logger->info('Starting session migration', [
            'old_session_id' => SessionIdMasker::mask($oldSessionId),
            'new_session_id' => SessionIdMasker::mask($newSessionId),
        ]);

        // $_SESSIONから現在のセッションデータを取得
        /** @var array<string, mixed> $sessionData */
        $sessionData = $_SESSION;

        // セッションデータを新しいセッションIDでRedisに書き込む
        $serializedData = $this->serializer->encode($sessionData);
        $writeSuccess = $this->connection->set($newSessionId, $serializedData, $this->ttl);

        if (!$writeSuccess) {
            throw new MigrationException('Failed to write session data to new session ID');
        }

        $this->logger->debug('Session data written to new session ID', [
            'new_session_id' => SessionIdMasker::mask($newSessionId),
        ]);

        // 現在のセッションを閉じて（セッションデータを書き込んでクローズ）、セッションIDを変更可能にする
        session_write_close();

        // 新しいセッションIDを設定
        session_id($newSessionId);

        // 新しいIDでセッションを再開
        if (!session_start()) {
            throw new MigrationException('Failed to restart session with new ID');
        }

        // セッションデータが保持されているか検証
        // シリアライズ形式での比較により、オブジェクトを正しく扱える堅牢なバイト単位チェックを実施。
        // これにより、同一性比較（===）で発生する誤検知（同じデータを含む異なるオブジェクトインスタンスで失敗する）を回避。
        /** @var array<string, mixed> $currentSessionData */
        $currentSessionData = $_SESSION;
        if (serialize($currentSessionData) !== serialize($sessionData)) {
            // データ不一致の場合、バックアップから復元
            $_SESSION = $sessionData;
            $this->logger->warning('Session data mismatch after migration, restored from backup', [
                'new_session_id' => SessionIdMasker::mask($newSessionId),
            ]);
        }

        // 要求された場合、古いセッションを削除
        if ($deleteOldSession) {
            $this->deleteOldSession($oldSessionId);
        }

        $this->logger->info('Session migration completed successfully', [
            'old_session_id' => SessionIdMasker::mask($oldSessionId),
            'new_session_id' => SessionIdMasker::mask($newSessionId),
            'old_session_deleted' => $deleteOldSession,
        ]);
    }

    /**
     * 現在のセッションを変更せずに、セッションデータを別のセッションIDにコピーする。
     *
     * 新しいセッションを準備しつつ、すぐには切り替えないシナリオで有用です。
     *
     * @param string $sourceSessionId コピー元のセッションID
     * @param string $targetSessionId コピー先のセッションID
     * @param bool $deleteSource コピー後にコピー元のセッションを削除するか（デフォルト: false）
     * @throws InvalidArgumentException コピー元とコピー先のセッションIDが同じ場合
     * @throws MigrationException コピーに失敗した場合
     * @throws InvalidSessionIdException セッションIDが無効な場合
     */
    public function copy(string $sourceSessionId, string $targetSessionId, bool $deleteSource = false): void
    {
        $this->validateSessionId($sourceSessionId);
        $this->validateSessionId($targetSessionId);

        if ($sourceSessionId === $targetSessionId) {
            throw new InvalidArgumentException('Source and target session IDs must be different');
        }

        // ターゲットセッションIDが既に存在していないかチェックし、他ユーザーのセッション上書きを防止
        if ($this->connection->exists($targetSessionId)) {
            throw new MigrationException('Target session ID already exists');
        }

        $this->logger->info('Starting session copy', [
            'source_session_id' => SessionIdMasker::mask($sourceSessionId),
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
        ]);

        // コピー元からセッションデータを読み取る
        $sessionData = $this->connection->get($sourceSessionId);

        if ($sessionData === false) {
            throw new MigrationException('Source session not found or could not be read');
        }

        // コピー先に書き込む
        $writeSuccess = $this->connection->set($targetSessionId, $sessionData, $this->ttl);

        if (!$writeSuccess) {
            throw new MigrationException('Failed to write session data to target session ID');
        }

        $this->logger->debug('Session data copied successfully', [
            'source_session_id' => SessionIdMasker::mask($sourceSessionId),
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
        ]);

        // 要求された場合、コピー元を削除
        if ($deleteSource) {
            $this->deleteOldSession($sourceSessionId);
        }

        $this->logger->info('Session copy completed', [
            'source_session_id' => SessionIdMasker::mask($sourceSessionId),
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
            'source_deleted' => $deleteSource,
        ]);
    }

    /**
     * セッションIDがRedisに存在するかチェックする。
     *
     * @param string $sessionId チェックするセッションID
     * @return bool セッションが存在する場合true、存在しないか無効なIDの場合false
     */
    public function sessionExists(string $sessionId): bool
    {
        // 共有バリデータを使用してサニタイズと検証を実施
        $sessionId = SessionIdValidator::sanitize($sessionId);

        if (!SessionIdValidator::isValid($sessionId)) {
            return false;
        }

        return $this->connection->exists($sessionId);
    }

    /**
     * 古いセッションをRedisから削除する。
     *
     * @param string $sessionId 削除するセッションID
     */
    private function deleteOldSession(string $sessionId): void
    {
        $deleted = $this->connection->delete($sessionId);

        if ($deleted) {
            $this->logger->debug('Old session deleted', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
        } else {
            $this->logger->warning('Failed to delete old session', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
        }
    }

    /**
     * セッションIDが期待される形式であるか検証する。
     *
     * @param string $sessionId 検証するセッションID（内部でサニタイズされる）
     * @throws InvalidSessionIdException セッションIDが無効な場合
     */
    private function validateSessionId(string $sessionId): void
    {
        // 入力を最初にサニタイズ（SessionIdValidatorはサニタイズ済み入力を要求）
        $sessionId = SessionIdValidator::sanitize($sessionId);

        // 一貫した検証のため共有バリデータを使用
        SessionIdValidator::validate($sessionId);

        // セッションIDが短すぎる場合は警告（セキュリティ上の懸念）
        if (SessionIdValidator::isShorterThanRecommended($sessionId)) {
            $this->logger->warning('Session ID is shorter than recommended minimum of 16 characters', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'length' => strlen($sessionId),
            ]);
        }
    }
}
