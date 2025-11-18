<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

/**
 * 深度追跡機能付きHookStorageInterfaceのRedisバックエンド実装。
 *
 * このクラスはRedisConnectionをラップし、フックがRedis操作を実行する際の
 * 無限再帰を防ぐために実行深度を追跡します。Redis操作自体がフックをトリガーする
 * 可能性があります。
 *
 * アーキテクチャ:
 * - 実際のRedis操作はRedisConnectionに委譲
 * - HookContextを使用して実行深度を追跡
 * - 深度制限を超過した場合に警告をログ出力
 * - 深度制限超過時は直接実行にフォールバック（graceful degradation）
 *
 * 設計上の決定:
 * - 深度超過時は失敗ではなくgraceful degradation
 * - 深度超過時のみ警告レベルでログ出力（アラート疲労を避けるためエラーレベルではない）
 * - 深度制限値（maxDepth）自体は許容範囲内として扱う
 * - 深度チェックによる最小限のパフォーマンスオーバーヘッド
 * - PSR-12およびPHPStan strict rulesとの互換性
 *
 * 使用例:
 * ```php
 * $context = new HookContext(3);
 * $storage = new HookRedisStorage($redisConnection, $context, $logger);
 *
 * // 深度を追跡し、制限を超過した場合は警告を出力
 * $storage->set('key', 'value', 3600);
 * ```
 */
class HookRedisStorage implements HookStorageInterface
{
    /**
     * ラップするRedis接続。
     *
     * @var RedisConnection
     */
    private RedisConnection $connection;

    /**
     * 実行深度追跡用のコンテキスト。
     *
     * @var HookContext
     */
    private HookContext $context;

    /**
     * 監視とデバッグ用のロガー。
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * 新しいHookRedisStorageインスタンスを作成する。
     *
     * @param RedisConnection $connection ラップするRedis接続
     * @param HookContext $context 深度追跡用のコンテキスト
     * @param LoggerInterface|null $logger オプションのロガー（未指定の場合はNullLoggerを使用）
     */
    public function __construct(
        RedisConnection $connection,
        HookContext $context,
        ?LoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->context = $context;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 深度追跡付きでキーによりRedisから値を取得する。
     *
     * @param string $key ストレージキー
     * @return string|false 値が見つかった場合は文字列、見つからないかエラーの場合はfalse
     */
    public function get(string $key)
    {
        return $this->executeWithDepthTracking('get', fn () => $this->connection->get($key));
    }

    /**
     * 深度追跡付きでTTL（有効期限）付きの値をRedisに設定する。
     *
     * @param string $key ストレージキー
     * @param string $value 保存する値
     * @param int $ttl 有効期限（秒単位、正の値である必要がある）
     * @return bool 操作が成功した場合true、それ以外はfalse
     */
    public function set(string $key, string $value, int $ttl): bool
    {
        return $this->executeWithDepthTracking('set', fn () => $this->connection->set($key, $value, $ttl));
    }

    /**
     * 深度追跡付きでキーによりRedisから値を削除する。
     *
     * @param string $key ストレージキー
     * @return bool キーが削除された場合true、キーが存在しないかエラーの場合false
     */
    public function delete(string $key): bool
    {
        return $this->executeWithDepthTracking('delete', fn () => $this->connection->delete($key));
    }

    /**
     * 深度追跡とログ出力を伴うcallableを実行する。
     *
     * このヘルパーメソッドは以下の共通パターンをカプセル化する:
     * 1. 実行深度をインクリメント
     * 2. 深度制限超過をチェックし、超過している場合は警告をログ出力
     * 3. 操作を実行（深度超過時もgraceful degradationのため実行）
     * 4. finallyブロックで深度をデクリメント
     *
     * @template T
     * @param string $operation ログ出力用の操作名（例: 'get', 'set', 'delete'）
     * @param callable(): T $fn 実行する操作
     * @return T callableの戻り値
     */
    private function executeWithDepthTracking(string $operation, callable $fn)
    {
        $this->context->incrementDepth();

        try {
            if ($this->context->isDepthExceeded()) {
                $this->logger->warning('Hook storage depth limit exceeded for ' . strtoupper($operation) . ' operation', [
                    'current_depth' => $this->context->getDepth(),
                    'max_depth' => $this->context->getMaxDepth(),
                    'operation' => $operation,
                ]);
            }

            // 深度超過時もgraceful degradationのため実行を継続
            return $fn();
        } finally {
            $this->context->decrementDepth();
        }
    }

    /**
     * 現在の実行深度を取得する。
     *
     * このメソッドは主にテストとデバッグ目的を想定している。
     *
     * @return int 現在の実行深度
     */
    public function getDepth(): int
    {
        return $this->context->getDepth();
    }

    /**
     * ラップしているHookContextインスタンスを取得する。
     *
     * このメソッドは主にテスト目的を想定している。
     *
     * @return HookContext コンテキストインスタンス
     */
    public function getContext(): HookContext
    {
        return $this->context;
    }
}
