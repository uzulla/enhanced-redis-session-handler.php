<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

/**
 * Prefixed session ID generator (Example Implementation)
 *
 * このクラスは、SessionIdGeneratorInterfaceの実装例として提供されています。
 * カスタムプレフィックスを持つセッションIDを生成します。
 *
 * This class is provided as an example implementation of SessionIdGeneratorInterface.
 * It generates session IDs with a custom prefix.
 *
 * 使用例 / Usage Example:
 * ```php
 * $generator = new PrefixedSessionIdGenerator('myapp');
 * $sessionId = $generator->generate();
 * // 例: "myapp_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
 * ```
 *
 * 用途 / Use Cases:
 * - 複数のアプリケーションが同じRedisインスタンスを共有する場合の名前空間分離
 * - セッションIDの識別を容易にするため
 * - デバッグやログ分析の簡素化
 *
 * - Namespace separation when multiple applications share the same Redis instance
 * - To make session IDs easier to identify
 * - Simplify debugging and log analysis
 *
 * 注意事項 / Notes:
 * - このジェネレータは例示用として提供されています
 * - プレフィックスは短く保つことを推奨します（セッションIDの長さに影響するため）
 * - プレフィックスに機密情報を含めないでください
 *
 * - This generator is provided as an example
 * - It is recommended to keep the prefix short (as it affects session ID length)
 * - Do not include sensitive information in the prefix
 *
 * @see SessionIdGeneratorInterface
 * @see DefaultSessionIdGenerator より安全な実装例
 * @see SecureSessionIdGenerator より安全で長いセッションIDが必要な場合
 */
class PrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
    /**
     * セッションIDのプレフィックス
     * Prefix for session IDs
     */
    private string $prefix;

    /**
     * セッションIDのランダム部分の長さ（16進数文字列）
     * Length of the random part of the session ID (hexadecimal string)
     *
     * デフォルトは32文字（16バイトのランダムデータ）
     * Default is 32 characters (16 bytes of random data)
     */
    private int $randomLength;

    /**
     * コンストラクタ / Constructor
     *
     * @param string $prefix セッションIDのプレフィックス（デフォルト: 'app'）
     *                       Prefix for session IDs (default: 'app')
     * @param int $randomLength セッションIDのランダム部分の長さ（16進数文字列、偶数である必要があります）
     *                          Length of the random part (hexadecimal string, must be even)
     *
     * 例 / Example:
     * ```php
     * // デフォルトプレフィックス（'app'）と32文字のランダム部分
     * $generator = new PrefixedSessionIdGenerator();
     *
     * // カスタムプレフィックス
     * $generator = new PrefixedSessionIdGenerator('myapp');
     *
     * // カスタムプレフィックスとランダム部分の長さ
     * $generator = new PrefixedSessionIdGenerator('myapp', 64);
     * ```
     */
    public function __construct(string $prefix = 'app', int $randomLength = 32)
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Prefix cannot be empty');
        }
        if ($randomLength < 16) {
            throw new \InvalidArgumentException(
                'Random part length must be at least 16 characters'
            );
        }
        if ($randomLength % 2 !== 0) {
            throw new \InvalidArgumentException(
                'Random part length must be an even number'
            );
        }
        $this->prefix = $prefix;
        $this->randomLength = $randomLength;
    }

    /**
     * プレフィックス付きセッションIDを生成します
     * Generate a session ID with a prefix
     *
     * 生成されるセッションIDの形式 / Generated session ID format:
     * - {prefix}_{random_hex_string}
     * - 例 / Example: "myapp_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
     *
     * プレフィックス部分 / Prefix part:
     * - コンストラクタで指定されたカスタムプレフィックス
     * - Custom prefix specified in the constructor
     * - 例 / Example: "myapp", "api", "admin"
     *
     * ランダム部分 / Random part:
     * - 暗号学的に安全な乱数生成器（random_bytes）を使用
     * - Uses cryptographically secure random number generator (random_bytes)
     * - 16進数文字列に変換
     * - Converted to hexadecimal string
     *
     * @return string プレフィックス付きセッションID / Session ID with prefix
     *
     * 使用例 / Usage Example:
     * ```php
     * $generator = new PrefixedSessionIdGenerator('myapp');
     * $sessionId = $generator->generate();
     * echo $sessionId; // "myapp_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
     *
     * // プレフィックス部分を抽出 / Extract prefix part
     * $parts = explode('_', $sessionId);
     * $prefix = $parts[0];
     * echo $prefix; // "myapp"
     * ```
     */
    public function generate(): string
    {
        $byteLength = (int)($this->randomLength / 2);
        assert($byteLength >= 1);
        $randomPart = bin2hex(random_bytes($byteLength));

        return $this->prefix . '_' . $randomPart;
    }
}
