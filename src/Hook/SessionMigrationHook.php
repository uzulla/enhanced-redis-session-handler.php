<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\Exception\InvalidSessionIdException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdValidator;

/**
 * 書き込み時にセッションデータを新しいセッションIDに移行するフック実装。
 *
 * このフックは以下の手順でセッションをプログラマティックに移行できます：
 * 1. setMigrationTarget()でターゲットセッションIDを設定
 * 2. 次の書き込み操作時に、データが新しいセッションIDに書き込まれる
 * 3. 必要に応じて古いセッションを削除可能
 *
 * 注意: このフックはRedis側の移行のみを処理します。移行を完了するには、
 * ブラウザのセッションクッキーを別途更新する必要があります（例：次のリクエスト時に
 * session_start()の前にsession_id($newId)を呼び出す）。
 *
 * セッションクッキーも処理する完全な移行ソリューションが必要な場合は、
 * SessionMigrationServiceを使用してください。
 *
 * 使用例:
 * ```php
 * $hook = new SessionMigrationHook($connection, $ttl);
 * $hook->setMigrationTarget($newSessionId, true);
 * // 次のセッション書き込み時に、データが新しいセッションIDにコピーされる
 * ```
 */
class SessionMigrationHook implements WriteHookInterface
{
    private RedisConnection $connection;
    private SessionSerializerInterface $serializer;
    private LoggerInterface $logger;
    private int $ttl;
    private bool $failOnMigrationError;

    private ?string $targetSessionId = null;
    private bool $deleteOldSession = true;

    /** @var array<string, array<string, mixed>> */
    private array $pendingWrites = [];

    /**
     * @param RedisConnection $connection セッションストレージ用のRedis接続
     * @param int $ttl セッションデータの生存時間（秒）
     * @param bool $failOnMigrationError trueの場合、移行失敗時に例外をスロー
     * @param LoggerInterface|null $logger オプションのロガー（デバッグ用）
     * @param SessionSerializerInterface|null $serializer オプションのシリアライザ（デフォルトはPhpSerializeSerializer）
     */
    public function __construct(
        RedisConnection $connection,
        int $ttl = 1440,
        bool $failOnMigrationError = false,
        ?LoggerInterface $logger = null,
        ?SessionSerializerInterface $serializer = null
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }
        $this->connection = $connection;
        $this->ttl = $ttl;
        $this->failOnMigrationError = $failOnMigrationError;
        $this->logger = $logger ?? new NullLogger();
        $this->serializer = $serializer ?? new PhpSerializeSerializer();
    }

    /**
     * 移行先のターゲットセッションIDを設定する。
     *
     * このメソッド呼び出し後、次の書き込み操作では以下が実行されます：
     * 1. セッションデータをターゲットセッションIDに書き込む
     * 2. 必要に応じて古いセッションを削除する
     *
     * @param string $targetSessionId 移行先の新しいセッションID（内部でサニタイズされる）
     * @param bool $deleteOldSession 移行後に古いセッションを削除するか（デフォルト: true）
     * @throws InvalidSessionIdException セッションIDが無効な場合
     */
    public function setMigrationTarget(string $targetSessionId, bool $deleteOldSession = true): void
    {
        // 入力を最初にサニタイズ（SessionIdValidatorはサニタイズ済み入力を要求）
        $targetSessionId = SessionIdValidator::sanitize($targetSessionId);

        // 一貫した検証のため共有バリデータを使用
        SessionIdValidator::validate($targetSessionId);

        $this->targetSessionId = $targetSessionId;
        $this->deleteOldSession = $deleteOldSession;

        $this->logger->debug('Migration target set', [
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
            'delete_old_session' => $deleteOldSession,
        ]);
    }

    /**
     * 移行ターゲットをクリアする（保留中の移行をキャンセル）。
     */
    public function clearMigrationTarget(): void
    {
        $this->targetSessionId = null;
        $this->deleteOldSession = true;

        $this->logger->debug('Migration target cleared');
    }

    /**
     * 移行が保留中であるかチェックする。
     *
     * @return bool 移行ターゲットが設定されている場合true
     */
    public function hasPendingMigration(): bool
    {
        return $this->targetSessionId !== null;
    }

    /**
     * 現在の移行ターゲットセッションIDを取得する。
     *
     * @return string|null ターゲットセッションID、または移行が保留中でない場合null
     */
    public function getMigrationTarget(): ?string
    {
        return $this->targetSessionId;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // afterWriteで使用するためデータを保存
        $this->pendingWrites[$sessionId] = $data;
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        if (!$success) {
            $this->logger->warning('Primary write failed, skipping migration', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        // 移行が要求されているかチェック
        if ($this->targetSessionId === null) {
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        // ターゲットが現在のセッションと同じ場合はスキップ
        if ($this->targetSessionId === $sessionId) {
            $this->logger->debug('Migration skipped: target session ID is same as current', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            $this->targetSessionId = null;
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        if (!isset($this->pendingWrites[$sessionId])) {
            $this->logger->warning('No pending write data found for session migration', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            return;
        }

        try {
            $this->performMigration($sessionId);
        } catch (Throwable $e) {
            $this->logger->error('Exception during session migration', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'exception' => $e,
            ]);

            if ($this->failOnMigrationError) {
                throw $e;
            }
        } finally {
            // 試行後に移行ターゲットをクリア（ワンショット）
            $this->targetSessionId = null;
            unset($this->pendingWrites[$sessionId]);
        }
    }

    /**
     * セッションデータをターゲットセッションIDに実際に移行する。
     *
     * @param string $sessionId 現在のセッションID
     * @throws RuntimeException 移行が失敗し、failOnMigrationErrorがtrueの場合
     */
    private function performMigration(string $sessionId): void
    {
        // このメソッドが呼ばれる時点でtargetSessionIdは非nullであることが保証されている
        // （performMigrationを呼び出す前にafterWriteでチェック済み）
        $targetId = $this->targetSessionId;
        if ($targetId === null) {
            return;
        }

        $data = $this->pendingWrites[$sessionId];
        $serializedData = $this->serializer->encode($data);

        $this->logger->info('Starting session migration via hook', [
            'old_session_id' => SessionIdMasker::mask($sessionId),
            'new_session_id' => SessionIdMasker::mask($targetId),
        ]);

        // ターゲットセッションIDが既に存在していないかチェックし、他ユーザーのセッション上書きを防止
        if ($this->connection->exists($targetId)) {
            $message = 'Target session ID already exists';
            $this->logger->error($message, [
                'old_session_id' => SessionIdMasker::mask($sessionId),
                'new_session_id' => SessionIdMasker::mask($targetId),
            ]);

            if ($this->failOnMigrationError) {
                throw new RuntimeException($message);
            }
            return;
        }

        // 新しいセッションIDに書き込む
        $migrationSuccess = $this->connection->set($targetId, $serializedData, $this->ttl);

        if (!$migrationSuccess) {
            $message = 'Failed to write session data to migration target';
            $this->logger->error($message, [
                'old_session_id' => SessionIdMasker::mask($sessionId),
                'new_session_id' => SessionIdMasker::mask($targetId),
            ]);

            if ($this->failOnMigrationError) {
                throw new RuntimeException($message);
            }
            return;
        }

        $this->logger->debug('Session data written to migration target', [
            'new_session_id' => SessionIdMasker::mask($targetId),
        ]);

        // 要求された場合、古いセッションを削除
        if ($this->deleteOldSession) {
            $this->deleteOldSessionData($sessionId);
        }

        $this->logger->info('Session migration via hook completed successfully', [
            'old_session_id' => SessionIdMasker::mask($sessionId),
            'new_session_id' => SessionIdMasker::mask($targetId),
            'old_session_deleted' => $this->deleteOldSession,
        ]);
    }

    /**
     * 古いセッションデータをRedisから削除する。
     *
     * @param string $sessionId 削除するセッションID
     */
    private function deleteOldSessionData(string $sessionId): void
    {
        $deleted = $this->connection->delete($sessionId);
        if ($deleted) {
            $this->logger->debug('Old session deleted after migration', [
                'old_session_id' => SessionIdMasker::mask($sessionId),
            ]);
        } else {
            $this->logger->warning('Failed to delete old session after migration', [
                'old_session_id' => SessionIdMasker::mask($sessionId),
            ]);
        }
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        $this->logger->error('Primary write error, migration skipped', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'exception' => $exception,
        ]);

        // 保留中の状態をクリア
        $this->targetSessionId = null;
        unset($this->pendingWrites[$sessionId]);
    }
}
