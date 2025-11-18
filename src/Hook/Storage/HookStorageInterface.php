<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook\Storage;

/**
 * フック内でのストレージ操作用インターフェース。
 *
 * このインターフェースは、フックがRedis操作を実行する際の無限再帰を防ぐため、
 * ストレージ操作（get、set、delete）を抽象化します。深度追跡と組み合わせることで、
 * フックコンテキスト内でストレージ操作を安全に実行し、実行チェーンの可視性を維持できます。
 *
 * 実装要件:
 * - 無限再帰を防ぐため実行深度を追跡すること
 * - 深度制限に達した、または超過した場合に警告をログ出力すること
 * - 深度制限超過時にgraceful degradationを提供すること
 * - PSR-12およびPHPStan strict rulesとの互換性を維持すること
 *
 * @see HookRedisStorage Redisバックエンド実装
 * @see HookContext 実行深度追跡
 */
interface HookStorageInterface
{
    /**
     * キーでストレージから値を取得する。
     *
     * @param string $key ストレージキー
     * @return string|false 値が見つかった場合は文字列、見つからないかエラーの場合はfalse
     */
    public function get(string $key);

    /**
     * TTL（有効期限）付きでストレージに値を設定する。
     *
     * @param string $key ストレージキー
     * @param string $value 保存する値
     * @param int $ttl 有効期限（秒単位、正の値である必要がある）
     * @return bool 操作が成功した場合true、それ以外はfalse
     */
    public function set(string $key, string $value, int $ttl): bool;

    /**
     * キーでストレージから値を削除する。
     *
     * @param string $key ストレージキー
     * @return bool キーが削除された場合true、キーが存在しないかエラーの場合false
     */
    public function delete(string $key): bool;
}
