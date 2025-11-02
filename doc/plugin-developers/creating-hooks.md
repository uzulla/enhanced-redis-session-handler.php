# Hook作成ガイド

## 概要

Hookは、セッションの読み込み・書き込み処理に独自の処理を挿入できる仕組みです。このガイドでは、ReadHookとWriteHookの作成方法を説明します。

## ReadHookInterface

### インターフェース

```php
namespace Uzulla\EnhancedRedisSessionHandler\Hook;

interface ReadHookInterface
{
    /**
     * セッションデータ読み込み前に呼ばれる
     */
    public function beforeRead(string $sessionId): void;

    /**
     * セッションデータ読み込み後に呼ばれる
     * データを変換して返すことができる
     */
    public function afterRead(string $sessionId, string $data): string;
}
```

### 実装例1: アクセスログ記録

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Psr\Log\LoggerInterface;

class AccessLogReadHook implements ReadHookInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function beforeRead(string $sessionId): void
    {
        // セッション読み込み開始をログに記録
        $this->logger->info('Session read started', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'timestamp' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }

    public function afterRead(string $sessionId, string $data): string
    {
        // セッション読み込み完了をログに記録
        $this->logger->info('Session read completed', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'data_size' => strlen($data),
        ]);

        // データはそのまま返す
        return $data;
    }
}
```

### 実装例2: データ検証

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadHookInterface;

class DataValidationReadHook implements ReadHookInterface
{
    public function beforeRead(string $sessionId): void
    {
        // 読み込み前の処理は不要
    }

    public function afterRead(string $sessionId, string $data): string
    {
        if ($data === '') {
            return $data; // 空データはそのまま
        }

        // データの整合性チェック
        // 注: afterReadは文字列を受け取るため、デシリアライズが必要
        $decoded = $this->tryUnserialize($data);
        if ($decoded === false) {
            // データが破損している場合は空を返す
            error_log("Session data corrupted for ID: {$sessionId}");
            return '';
        }

        return $data;
    }

    private function tryUnserialize(string $data)
    {
        set_error_handler(function() { return true; });
        try {
            return unserialize($data);
        } finally {
            restore_error_handler();
        }
    }
}
```

### 使用方法

```php
$handler = new RedisSessionHandler(/* ... */);

// Hookを追加
$handler->addReadHook(new AccessLogReadHook($logger));
$handler->addReadHook(new DataValidationReadHook());

session_set_save_handler($handler, true);
session_start();
```

## WriteHookInterface

### インターフェース

```php
namespace Uzulla\EnhancedRedisSessionHandler\Hook;

interface WriteHookInterface
{
    /**
     * セッションデータ書き込み前に呼ばれる
     * データを変換して返すことができる
     *
     * @param string $sessionId
     * @param array<string, mixed> $data デシリアライズされたセッションデータ
     * @return array<string, mixed> 変換後のデータ
     */
    public function beforeWrite(string $sessionId, array $data): array;

    /**
     * セッションデータ書き込み後に呼ばれる
     */
    public function afterWrite(string $sessionId, bool $success): void;

    /**
     * 書き込み中にエラーが発生した場合に呼ばれる
     */
    public function onWriteError(string $sessionId, \Throwable $exception): void;
}
```

**重要**: `beforeWrite()`は**配列**を受け取り、**配列**を返します（Serializer導入により文字列→配列変換が自動的に行われます）。

### 実装例1: タイムスタンプ追加

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;

class TimestampWriteHook implements WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array
    {
        // 最終更新時刻を追加
        $data['_last_modified'] = time();
        $data['_last_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        // 書き込み後の処理（必要なら）
    }

    public function onWriteError(string $sessionId, \Throwable $exception): void
    {
        // エラー処理（必要なら）
        error_log("Session write error: {$exception->getMessage()}");
    }
}
```

### 実装例2: データ圧縮

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;

class CompressionWriteHook implements WriteHookInterface
{
    private int $threshold;

    public function __construct(int $threshold = 1024)
    {
        $this->threshold = $threshold;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // データサイズをチェック
        $serialized = serialize($data);

        if (strlen($serialized) < $this->threshold) {
            // 小さいデータは圧縮しない
            return $data;
        }

        // 大きいデータは圧縮フラグを立てる
        // 注: 実際の圧縮はSerializerレベルで行うべき
        $data['_compressed'] = true;

        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        // 必要なら統計情報を記録
    }

    public function onWriteError(string $sessionId, \Throwable $exception): void
    {
        error_log("Compression write error: {$exception->getMessage()}");
    }
}
```

### 実装例3: 監査ログ

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Psr\Log\LoggerInterface;

class AuditWriteHook implements WriteHookInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // 監査ログを記録
        $this->logger->info('Session write attempt', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'user_id' => $data['user_id'] ?? null,
            'data_keys' => array_keys($data),
        ]);

        // データはそのまま返す
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        $this->logger->info('Session write result', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'success' => $success,
        ]);
    }

    public function onWriteError(string $sessionId, \Throwable $exception): void
    {
        $this->logger->error('Session write failed', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### 使用方法

```php
$handler = new RedisSessionHandler(/* ... */);

// 複数のHookを追加（登録順に実行される）
$handler->addWriteHook(new TimestampWriteHook());
$handler->addWriteHook(new CompressionWriteHook(2048));
$handler->addWriteHook(new AuditWriteHook($logger));

session_set_save_handler($handler, true);
session_start();
```

## ベストプラクティス

### 1. セッションIDのマスキング

ログに記録する際は必ず`SessionIdMasker`を使用：

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

// ✓ 正しい
$this->logger->info('Session operation', [
    'session_id' => SessionIdMasker::mask($sessionId),
]);

// ✗ 間違い（セキュリティリスク）
$this->logger->info('Session operation', [
    'session_id' => $sessionId,
]);
```

### 2. 軽量な実装

Hookは頻繁に呼ばれるため、処理は最小限に：

```php
// ✓ 良い例
public function beforeWrite(string $sessionId, array $data): array
{
    $data['timestamp'] = time();
    return $data;
}

// ✗ 悪い例（重いDB操作）
public function beforeWrite(string $sessionId, array $data): array
{
    $user = $this->db->query('SELECT * FROM users WHERE id = ?', [$data['user_id']]);
    $data['user_info'] = $user;
    return $data;
}
```

### 3. エラーハンドリング

例外をスローせず、安全に処理：

```php
public function beforeWrite(string $sessionId, array $data): array
{
    try {
        // 何らかの処理
        $data['processed'] = $this->process($data);
    } catch (\Exception $e) {
        // ログに記録して処理を継続
        error_log("Hook processing error: {$e->getMessage()}");
        // データはそのまま返す
    }

    return $data;
}
```

### 4. データは必ず返す

`beforeWrite()`は必ずデータを返す：

```php
// ✓ 正しい
public function beforeWrite(string $sessionId, array $data): array
{
    $data['modified'] = true;
    return $data; // 必ず返す
}

// ✗ 間違い
public function beforeWrite(string $sessionId, array $data): array
{
    $data['modified'] = true;
    // return忘れ → Fatal Error
}
```

## 実行順序

複数のHookが登録されている場合：

```php
$handler->addWriteHook($hook1);
$handler->addWriteHook($hook2);
$handler->addWriteHook($hook3);
```

**beforeWrite**: 登録順にチェーン実行
```
入力データ → hook1 → hook2 → hook3 → Redisに保存
```

**afterWrite**: 登録順に実行
```
hook1.afterWrite() → hook2.afterWrite() → hook3.afterWrite()
```

**onWriteError**: エラー時、登録順に実行
```
hook1.onWriteError() → hook2.onWriteError() → hook3.onWriteError()
```

## テスト

### Hookのユニットテスト例

```php
use PHPUnit\Framework\TestCase;

class TimestampWriteHookTest extends TestCase
{
    public function testBeforeWriteAddsTimestamp(): void
    {
        $hook = new TimestampWriteHook();

        $data = ['user_id' => 123];
        $result = $hook->beforeWrite('session123', $data);

        $this->assertArrayHasKey('_last_modified', $result);
        $this->assertIsInt($result['_last_modified']);
        $this->assertEquals(123, $result['user_id']);
    }

    public function testAfterWriteDoesNotThrow(): void
    {
        $hook = new TimestampWriteHook();

        // 例外が発生しないことを確認
        $hook->afterWrite('session123', true);
        $hook->afterWrite('session123', false);

        $this->assertTrue(true);
    }
}
```

## 標準実装の参考

以下の標準実装が参考になります：

- `src/Hook/LoggingHook.php` - ロギングの実装例
- `src/Hook/DoubleWriteHook.php` - セカンダリRedisへの書き込み例
- `src/Hook/ReadTimestampHook.php` - ReadHookの実装例

## トラブルシューティング

### Hookが実行されない

**確認ポイント**:
1. `addReadHook()` / `addWriteHook()`で登録したか？
2. `session_set_save_handler()`を呼んだか？
3. Hookのメソッドで例外が発生していないか？

### データが変更されない

**確認ポイント**:
1. `beforeWrite()`で`return $data`しているか？
2. 他のHookで上書きされていないか？
3. WriteFilterで書き込みがキャンセルされていないか？

## まとめ

- **ReadHook**: セッション読み込み時の処理を追加
- **WriteHook**: セッション書き込み時のデータ変換や監査
- **軽量**: 処理は最小限に
- **安全**: 例外をスローせず、エラーハンドリング
- **セキュリティ**: SessionIdMaskerでログ保護

## 関連ドキュメント

- [creating-filters.md](creating-filters.md) - WriteFilter作成ガイド
- [../developers/implementation/hooks-and-filters.md](../developers/implementation/hooks-and-filters.md) - Hook/Filter機構の詳細
