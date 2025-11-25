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
 * ## Monolog TestHandlerへの移行方法（PHPバージョン統一後）
 *
 * ### 変更前（PsrTestLogger使用）
 * ```php
 * use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;
 *
 * $logger = new PsrTestLogger();
 * $handler = new RedisSessionHandler($connection, $options, $logger);
 *
 * // テスト実行
 * $handler->write('session-id', 'data');
 *
 * // ログレコード取得
 * $records = $logger->getRecords();
 * self::assertCount(1, $records);
 * self::assertSame('INFO', $records[0]['level_name']);
 * ```
 *
 * ### 変更後（Monolog TestHandler使用）
 * ```php
 * use Monolog\Logger;
 * use Monolog\Handler\TestHandler;
 *
 * $testHandler = new TestHandler();
 * $logger = new Logger('test');
 * $logger->pushHandler($testHandler);
 * $handler = new RedisSessionHandler($connection, $options, $logger);
 *
 * // テスト実行
 * $handler->write('session-id', 'data');
 *
 * // ログレコード取得（TestHandlerから取得することに注意）
 * $records = $testHandler->getRecords();
 * self::assertCount(1, $records);
 * self::assertSame('INFO', $records[0]['level_name']);
 * ```
 *
 * 注意: Monolog 2.x と 3.x では getRecords() の戻り値の形式が異なります。
 * この移行は、サポート対象のPHPバージョンが統一され、Monologのバージョンも
 * 統一された後に実施してください。
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
     * 指定されたメッセージを持つログレコードが存在するか確認
     *
     * @param string $message 検索するログメッセージ
     * @param string|null $level ログレベル（オプション）
     * @return bool
     */
    public function hasLogMessage(string $message, ?string $level = null): bool
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                if ($level === null || strtoupper($level) === $record['level_name']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 指定されたメッセージを含むログレコードが存在するか確認
     *
     * @param string $substring 検索する部分文字列
     * @param string|null $level ログレベル（オプション）
     * @return bool
     */
    public function hasLogMessageContaining(string $substring, ?string $level = null): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $substring)) {
                if ($level === null || strtoupper($level) === $record['level_name']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 指定されたメッセージを持つログレコードを取得
     *
     * @param string $message 検索するログメッセージ
     * @return array{level: string, level_name: string, message: string, context: array<string,mixed>}|null
     */
    public function findLogByMessage(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }
        return null;
    }

    /**
     * 指定されたメッセージとコンテキストを持つログレコードが存在するか確認
     *
     * @param string $message ログメッセージ
     * @param array<string, mixed> $expectedContext 期待されるコンテキスト（部分一致）
     * @return bool
     */
    public function hasLogWithContext(string $message, array $expectedContext): bool
    {
        $record = $this->findLogByMessage($message);
        if ($record === null) {
            return false;
        }

        /** @var array<string, mixed> $context */
        $context = $record['context'];

        foreach ($expectedContext as $key => $expectedValue) {
            if (!array_key_exists($key, $context) || $context[$key] !== $expectedValue) {
                return false;
            }
        }

        return true;
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
