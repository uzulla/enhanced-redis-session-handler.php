<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3互換のテスト用ロガー
 *
 * Monolog 2.x/3.xの違いを吸収し、一貫した形式でログレコードを保存します。
 * 将来的にPHPバージョンが統一され、Monologの型が安定したら、
 * このクラスは削除し、Monolog\Handler\TestHandlerに戻すことができます。
 *
 * MonologのTestHandlerと同様のAPIを提供することで、移行を容易にします。
 *
 * ## Monolog TestHandlerへの移行方法（PHPバージョン統一後） (important-comment)
 *
 * ### 変更前（PsrTestLogger使用） (important-comment)
 * ```php (important-comment)
 * use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger; (important-comment)
 * (important-comment)
 * $logger = new PsrTestLogger(); (important-comment)
 * $handler = new RedisSessionHandler($connection, $options, $logger); (important-comment)
 * (important-comment)
 * // テスト実行 (important-comment)
 * $handler->write('session-id', 'data'); (important-comment)
 * (important-comment)
 * // ログレコード取得 (important-comment)
 * $records = $logger->getRecords(); (important-comment)
 * self::assertCount(1, $records); (important-comment)
 * self::assertSame('INFO', $records[0]['level_name']); (important-comment)
 * ``` (important-comment)
 *
 * ### 変更後（Monolog TestHandler使用） (important-comment)
 * ```php (important-comment)
 * use Monolog\Logger; (important-comment)
 * use Monolog\Handler\TestHandler; (important-comment)
 * (important-comment)
 * $testHandler = new TestHandler(); (important-comment)
 * $logger = new Logger('test'); (important-comment)
 * $logger->pushHandler($testHandler); (important-comment)
 * $handler = new RedisSessionHandler($connection, $options, $logger); (important-comment)
 * (important-comment)
 * // テスト実行 (important-comment)
 * $handler->write('session-id', 'data'); (important-comment)
 * (important-comment)
 * // ログレコード取得（TestHandlerから取得することに注意） (important-comment)
 * $records = $testHandler->getRecords(); (important-comment)
 * self::assertCount(1, $records); (important-comment)
 * self::assertSame('INFO', $records[0]['level_name']); (important-comment)
 * ``` (important-comment)
 *
 * 注意: Monolog 2.x と 3.x では getRecords() の戻り値の形式が異なります。 (important-comment)
 * この移行は、サポート対象のPHPバージョンが統一され、Monologのバージョンも (important-comment)
 * 統一された後に実施してください。 (important-comment)
 */
class PsrTestLogger implements LoggerInterface
{
    /**
     * @var list<array{level: string, level_name: string, message: string, context: array<string,mixed>}>
     */
    private array $records = [];

    /**
     * すべてのログレコードを取得
     *
     * @return list<array{level: string, level_name: string, message: string, context: array<string,mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * 指定されたレベルのログレコードを取得
     *
     * @param string $level ログレベル（例: 'debug', 'info', 'warning', 'error'）
     * @return list<array{level: string, level_name: string, message: string, context: array<string,mixed>}>
     */
    public function getRecordsByLevel(string $level): array
    {
        $levelUpper = strtoupper($level);
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => $record['level_name'] === $levelUpper
        ));
    }

    /**
     * DEBUGレベルのログが存在するか確認
     */
    public function hasDebugRecords(): bool
    {
        return count($this->getRecordsByLevel(LogLevel::DEBUG)) > 0;
    }

    /**
     * INFOレベルのログが存在するか確認
     */
    public function hasInfoRecords(): bool
    {
        return count($this->getRecordsByLevel(LogLevel::INFO)) > 0;
    }

    /**
     * WARNINGレベルのログが存在するか確認
     */
    public function hasWarningRecords(): bool
    {
        return count($this->getRecordsByLevel(LogLevel::WARNING)) > 0;
    }

    /**
     * ERRORレベルのログが存在するか確認
     */
    public function hasErrorRecords(): bool
    {
        return count($this->getRecordsByLevel(LogLevel::ERROR)) > 0;
    }

    /**
     * CRITICALレベルのログが存在するか確認
     */
    public function hasCriticalRecords(): bool
    {
        return count($this->getRecordsByLevel(LogLevel::CRITICAL)) > 0;
    }

    /**
     * すべてのログレコードをクリア
     */
    public function clear(): void
    {
        $this->records = [];
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (is_string($level)) {
            $levelStr = $level;
        } elseif (is_object($level) && method_exists($level, '__toString')) {
            $levelStr = (string)$level;
        } else {
            $levelStr = 'unknown';
        }

        /** @var array<string,mixed> $normalizedContext */
        $normalizedContext = $context;
        $this->records[] = [
            'level' => $levelStr,
            'level_name' => strtoupper($levelStr),
            'message' => (string)$message,
            'context' => $normalizedContext,
        ];
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
