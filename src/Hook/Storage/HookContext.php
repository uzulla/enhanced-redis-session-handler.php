<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook\Storage;

use InvalidArgumentException;

/**
 * フックストレージ操作の実行深度追跡を管理する。
 *
 * このクラスは、フック操作のネストの深さを追跡することで無限再帰を防ぎます。
 * フックがRedis操作を実行し、それ自体がフックをトリガーする可能性がある場合、
 * この深度を追跡して暴走再帰を防ぐ必要があります。
 *
 * 設計上の決定:
 * - デフォルトの最大深度3レベルは、一般的なユースケースに十分
 * - シングルスレッドPHP環境でスレッドセーフ（インスタンス状態を使用）
 * - 単純な整数のインクリメント/デクリメントによる最小限のパフォーマンスオーバーヘッド
 *
 * 使用例:
 * ```php
 * $context = new HookContext(3);
 * $context->incrementDepth();
 * try {
 *     // 操作を実行
 *     if ($context->isDepthExceeded()) {
 *         // 深度制限超過の処理
 *     }
 * } finally {
 *     $context->decrementDepth();
 * }
 * ```
 */
class HookContext
{
    /**
     * フック実行チェーンのデフォルト最大深度。
     */
    private const DEFAULT_MAX_DEPTH = 3;

    /**
     * 現在の実行深度カウンター。
     *
     * @var int
     */
    private int $depth = 0;

    /**
     * 許可される最大実行深度。
     *
     * @var int
     */
    private int $maxDepth;

    /**
     * 新しいフックコンテキストを作成する。
     *
     * @param int $maxDepth 許可される最大実行深度（正の値である必要がある）
     * @throws InvalidArgumentException maxDepthが正の値でない場合
     */
    public function __construct(int $maxDepth = self::DEFAULT_MAX_DEPTH)
    {
        if ($maxDepth <= 0) {
            throw new InvalidArgumentException('Max depth must be positive, got: ' . $maxDepth);
        }

        $this->maxDepth = $maxDepth;
    }

    /**
     * 実行深度カウンターをインクリメントする。
     *
     * フックストレージ操作に入る際にこのメソッドを呼び出す。
     *
     * @return void
     */
    public function incrementDepth(): void
    {
        $this->depth++;
    }

    /**
     * 実行深度カウンターをデクリメントする。
     *
     * フックストレージ操作から出る際にこのメソッドを呼び出す。
     * 深度は0を下回ることはない。
     *
     * @return void
     */
    public function decrementDepth(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    /**
     * 現在の実行深度を取得する。
     *
     * @return int 現在の深度（0以上）
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    /**
     * 許可される最大実行深度を取得する。
     *
     * @return int 最大深度
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * 現在の深度が最大許容深度を超えているかチェックする。
     *
     * @return bool 深度制限を超えている場合true、それ以外はfalse
     */
    public function isDepthExceeded(): bool
    {
        return $this->depth > $this->maxDepth;
    }

    /**
     * 実行深度カウンターをゼロにリセットする。
     *
     * このメソッドは注意して使用すること。主にテストや
     * コンテキスト状態をリセットする必要がある特定のエッジケースを想定している。
     *
     * @return void
     */
    public function reset(): void
    {
        $this->depth = 0;
    }
}
