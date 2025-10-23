<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

/**
 * Timestamp-prefixed session ID generator (Example Implementation)
 *
 * このクラスは、SessionIdGeneratorInterfaceの実装例として提供されています。
 * タイムスタンプをプレフィックスとして持つセッションIDを生成します。
 *
 * This class is provided as an example implementation of SessionIdGeneratorInterface.
 * It generates session IDs with a timestamp prefix.
 *
 * 使用例 / Usage Example:
 * ```php
 * $generator = new TimestampPrefixedSessionIdGenerator();
 * $sessionId = $generator->generate();
 * // 例: "1761216000_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
 * ```
 *
 * 注意事項 / Notes:
 * - このジェネレータは、セッションIDの作成時刻を簡単に識別できるようにします
 * - デバッグやログ分析に便利ですが、セキュリティ上の理由で本番環境での使用は推奨されません
 * - タイムスタンプから情報が漏洩する可能性があるため、セキュリティが重要な場合は使用しないでください
 *
 * - This generator makes it easy to identify when a session ID was created
 * - Useful for debugging and log analysis, but not recommended for production use for security reasons
 * - Do not use if security is important, as the timestamp may leak information
 *
 * @see SessionIdGeneratorInterface
 * @see DefaultSessionIdGenerator より安全な実装例
 * @see SecureSessionIdGenerator より安全で長いセッションIDが必要な場合
 */
class TimestampPrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
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
     * @param int $randomLength セッションIDのランダム部分の長さ（16進数文字列、偶数である必要があります）
     *                          Length of the random part (hexadecimal string, must be even)
     *
     * 例 / Example:
     * ```php
     * // デフォルト（32文字のランダム部分）
     * $generator = new TimestampPrefixedSessionIdGenerator();
     *
     * // カスタム長（64文字のランダム部分）
     * $generator = new TimestampPrefixedSessionIdGenerator(64);
     * ```
     */
    public function __construct(int $randomLength = 32)
    {
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
        $this->randomLength = $randomLength;
    }

    /**
     * タイムスタンプをプレフィックスとして持つセッションIDを生成します
     * Generate a session ID with a timestamp prefix
     *
     * 生成されるセッションIDの形式 / Generated session ID format:
     * - {timestamp}_{random_hex_string}
     * - 例 / Example: "1761216000_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
     *
     * タイムスタンプ部分 / Timestamp part:
     * - Unix タイムスタンプ（秒単位）
     * - Unix timestamp (in seconds)
     * - 例 / Example: 1761216000 = 2025-10-23 10:40:00 UTC
     *
     * ランダム部分 / Random part:
     * - 暗号学的に安全な乱数生成器（random_bytes）を使用
     * - Uses cryptographically secure random number generator (random_bytes)
     * - 16進数文字列に変換
     * - Converted to hexadecimal string
     *
     * @return string タイムスタンプ付きセッションID / Session ID with timestamp prefix
     *
     * 使用例 / Usage Example:
     * ```php
     * $generator = new TimestampPrefixedSessionIdGenerator();
     * $sessionId = $generator->generate();
     * echo $sessionId; // "1761216000_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
     *
     * // タイムスタンプ部分を抽出 / Extract timestamp part
     * $parts = explode('_', $sessionId);
     * $timestamp = (int)$parts[0];
     * echo date('Y-m-d H:i:s', $timestamp); // "2025-10-23 10:40:00"
     * ```
     */
    public function generate(): string
    {
        $timestamp = time();

        $byteLength = (int)($this->randomLength / 2);
        assert($byteLength >= 1);
        $randomPart = bin2hex(random_bytes($byteLength));

        return $timestamp . '_' . $randomPart;
    }
}
