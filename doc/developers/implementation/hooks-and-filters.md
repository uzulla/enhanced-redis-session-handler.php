# Hook/Filter機構 - 実装詳細

## 概要

Hook/Filter機構は、セッションの読み込み・書き込み処理に任意の処理を挿入できる拡張ポイントです。この機構により、暗号化、ロギング、フォールバック、書き込み制御など、様々な機能を柔軟に追加できます。

## 3つのインターフェース

### ReadHookInterface - 読み込み時の処理

```php
interface ReadHookInterface
{
    public function beforeRead(string $sessionId): void;
    public function afterRead(string $sessionId, string $data): string;
}
```

**用途**: セッション読み込みの前後に処理を挿入

### WriteHookInterface - 書き込み時の処理とエラーハンドリング

```php
interface WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array;
    public function afterWrite(string $sessionId, bool $success): void;
    public function onWriteError(string $sessionId, Throwable $exception): void;
}
```

**用途**: セッション書き込みの前後に処理を挿入、データ変換

### WriteFilterInterface - 書き込み可否の判断

```php
interface WriteFilterInterface
{
    public function shouldWrite(string $sessionId, array $data): bool;
}
```

**用途**: 書き込み操作自体をキャンセルする判断

## WriteHookとWriteFilterの違い

これは重要な設計上の区別です：

```
┌────────────────────────────────────────┐
│ WriteFilter: 書き込みするか？           │
│ - shouldWrite()がfalseを返す            │
│ - 書き込み処理全体をスキップ            │
│ - 例: 空セッションは書き込まない        │
└────────────────────────────────────────┘
                  ↓ (true)
┌────────────────────────────────────────┐
│ WriteHook: データをどう変換する？       │
│ - beforeWrite()でデータを変換           │
│ - 例: 暗号化、圧縮、検証                │
└────────────────────────────────────────┘
```

### 具体例

**WriteFilter: 書き込みの可否を判断**

```php
class EmptySessionFilter implements WriteFilterInterface
{
    public function shouldWrite(string $sessionId, array $data): bool
    {
        // 空セッションなら書き込みをキャンセル
        return count($data) > 0;
    }
}
```

**WriteHook: データを変換**

```php
class EncryptionHook implements WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array
    {
        // データを暗号化（必ず書き込みは実行される）
        $data['__encrypted'] = true;
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        // 書き込み後の処理
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        // エラーハンドリング
    }
}
```

## 実行フロー

### 読み込みフロー

```
RedisSessionHandler::read($sessionId)
    ↓
[全ReadHook::beforeRead()] (前処理)
    ↓
RedisConnection::get(key) → 文字列データ
    ↓
Serializer::decode(文字列) → 配列
    ↓
[全ReadHook::afterRead()] (後処理・データ変換)
    ↓
Serializer::encode(配列) → 文字列
    ↓
return 文字列
```

**実装コード**:

```php
public function read(string $id): string|false
{
    // beforeReadフック実行
    foreach ($this->readHooks as $hook) {
        $hook->beforeRead($id);
    }

    // Redisから取得
    $stringData = $this->connection->get($id);
    if ($stringData === false) {
        return '';
    }

    // 文字列→配列変換
    $arrayData = $this->serializer->decode($stringData);

    // afterReadフック実行（文字列を返すので配列→文字列変換が必要）
    // 注: 現在の実装では文字列のまま渡している
    foreach ($this->readHooks as $hook) {
        $stringData = $hook->afterRead($id, $stringData);
    }

    return $stringData;
}
```

### 書き込みフロー

```
RedisSessionHandler::write($sessionId, $data)
    ↓
Serializer::decode($data文字列) → 配列
    ↓
[全WriteFilter::shouldWrite()] ← 新機能！
    ↓ (falseなら即座にreturn false)
    ↓ (trueなら続行)
[全WriteHook::beforeWrite()] (データ変換)
    ↓
Serializer::encode(配列) → 文字列
    ↓
RedisConnection::set(key, 文字列, ttl)
    ↓
[全WriteHook::afterWrite()] (後処理)
    ↓
return true/false
```

**実装コード**:

```php
public function write(string $id, string $data): bool
{
    try {
        // 文字列→配列変換
        $arrayData = $this->serializer->decode($data);

        // WriteFilter実行（書き込みの可否判断）
        foreach ($this->writeFilters as $filter) {
            if (!$filter->shouldWrite($id, $arrayData)) {
                $this->logger->debug('Write operation cancelled by filter', [
                    'session_id' => SessionIdMasker::mask($id),
                ]);

                // afterWriteを呼ぶ（success=false）
                foreach ($this->writeHooks as $hook) {
                    $hook->afterWrite($id, false);
                }

                return false; // 書き込みキャンセル
            }
        }

        // WriteHook::beforeWrite実行（データ変換）
        foreach ($this->writeHooks as $hook) {
            $arrayData = $hook->beforeWrite($id, $arrayData);
        }

        // 配列→文字列変換
        $stringData = $this->serializer->encode($arrayData);

        // Redisに書き込み
        $success = $this->connection->set($id, $stringData, $this->getTTL());

        // WriteHook::afterWrite実行
        foreach ($this->writeHooks as $hook) {
            $hook->afterWrite($id, $success);
        }

        return $success;

    } catch (Throwable $e) {
        // WriteHook::onWriteError実行
        foreach ($this->writeHooks as $hook) {
            $hook->onWriteError($id, $e);
        }

        $this->logger->error('Write operation failed', [
            'session_id' => SessionIdMasker::mask($id),
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}
```

## Hook/Filterの登録と実行順序

### 登録

```php
$handler = new RedisSessionHandler(/*...*/);

// ReadHookを追加
$handler->addReadHook($readHook1);
$handler->addReadHook($readHook2);

// WriteHookを追加
$handler->addWriteHook($writeHook1);
$handler->addWriteHook($writeHook2);

// WriteFilterを追加
$handler->addWriteFilter($filter1);
$handler->addWriteFilter($filter2);
```

### 実行順序

**ReadHook**:
1. `beforeRead`: 登録順に実行（$readHook1 → $readHook2）
2. `afterRead`: 登録順に実行（$readHook1 → $readHook2）

**WriteFilter**:
- `shouldWrite`: 登録順に実行
- **いずれか1つでも`false`を返したら即座に書き込みキャンセル**

**WriteHook**:
1. `beforeWrite`: 登録順に実行（チェーン化）
   - Hook1の出力 → Hook2の入力
   - Hook2の出力 → Hook3の入力
2. `afterWrite`: 登録順に実行
3. `onWriteError`: 登録順に実行（エラー時のみ）

### チェーン化の例

```php
// Hook1: 検証を追加
class ValidationHook implements WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array
    {
        $data['validated'] = true;
        return $data;
    }
}

// Hook2: タイムスタンプを追加
class TimestampHook implements WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array
    {
        $data['last_modified'] = time();
        return $data;
    }
}

// 実行結果
$handler->addWriteHook(new ValidationHook());
$handler->addWriteHook(new TimestampHook());

// 入力: ['user_id' => 123]
// Hook1後: ['user_id' => 123, 'validated' => true]
// Hook2後: ['user_id' => 123, 'validated' => true, 'last_modified' => 1234567890]
```

## 標準実装

### EmptySessionFilter

空セッションの書き込みをスキップするFilter。

```php
class EmptySessionFilter implements WriteFilterInterface
{
    private LoggerInterface $logger;
    private bool $lastWriteWasEmpty = false;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        $isEmpty = count($data) === 0;
        $this->lastWriteWasEmpty = $isEmpty;

        if ($isEmpty) {
            $this->logger->debug(
                'Empty session detected, write operation cancelled',
                ['session_id' => SessionIdMasker::mask($sessionId)]
            );
            return false;
        }

        $this->logger->debug(
            'Session has data, write operation allowed',
            [
                'session_id' => SessionIdMasker::mask($sessionId),
                'data' => $data,
            ]
        );
        return true;
    }

    /**
     * 最後の書き込みが空だったかチェック
     * PreventEmptySessionCookieで使用
     */
    public function wasLastWriteEmpty(): bool
    {
        return $this->lastWriteWasEmpty;
    }
}
```

**使用例**:

```php
$filter = new EmptySessionFilter($logger);
$handler->addWriteFilter($filter);

// セッションが空の場合
$_SESSION = [];
session_write_close();
// → shouldWrite()がfalseを返し、Redisへの書き込みをスキップ

// セッションにデータがある場合
$_SESSION = ['user_id' => 123];
session_write_close();
// → shouldWrite()がtrueを返し、Redisに書き込み
```

### LoggingHook

セッション操作をログに記録するHook。

```php
class LoggingHook implements WriteHookInterface
{
    private LoggerInterface $logger;
    private string $beforeWriteLevel;
    private string $afterWriteLevel;
    private string $onWriteErrorLevel;
    private bool $logData;

    public function __construct(
        LoggerInterface $logger,
        string $beforeWriteLevel = LogLevel::INFO,
        string $afterWriteLevel = LogLevel::INFO,
        string $onWriteErrorLevel = LogLevel::ERROR,
        bool $logData = false
    ) {
        $this->logger = $logger;
        $this->beforeWriteLevel = $beforeWriteLevel;
        $this->afterWriteLevel = $afterWriteLevel;
        $this->onWriteErrorLevel = $onWriteErrorLevel;
        $this->logData = $logData;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        $context = [
            'session_id' => SessionIdMasker::mask($sessionId),
        ];

        if ($this->logData) {
            $context['data'] = $data;
        }

        $this->logger->log(
            $this->beforeWriteLevel,
            'Session write started',
            $context
        );

        return $data; // データはそのまま返す
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        $this->logger->log(
            $this->afterWriteLevel,
            $success ? 'Session write succeeded' : 'Session write failed',
            [
                'session_id' => SessionIdMasker::mask($sessionId),
                'success' => $success,
            ]
        );
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        $this->logger->log(
            $this->onWriteErrorLevel,
            'Session write error',
            [
                'session_id' => SessionIdMasker::mask($sessionId),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]
        );
    }
}
```

### DoubleWriteHook

セカンダリRedisへの二重書き込みを行うHook。

```php
class DoubleWriteHook implements WriteHookInterface
{
    private RedisConnection $secondaryConnection;
    private int $ttl;
    private bool $failOnSecondaryError;
    private LoggerInterface $logger;

    public function __construct(
        RedisConnection $secondaryConnection,
        int $ttl,
        bool $failOnSecondaryError,
        LoggerInterface $logger
    ) {
        $this->secondaryConnection = $secondaryConnection;
        $this->ttl = $ttl;
        $this->failOnSecondaryError = $failOnSecondaryError;
        $this->logger = $logger;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // データ変換はしない、そのまま返す
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        if (!$success) {
            // プライマリ書き込みが失敗したらスキップ
            return;
        }

        // セカンダリRedisに書き込み
        // （実装詳細は省略）
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        $this->logger->error('Primary write error, secondary write skipped', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### ReadTimestampHook

読み込み時にタイムスタンプメタデータを更新するHook。

```php
class ReadTimestampHook implements ReadHookInterface
{
    public function beforeRead(string $sessionId): void
    {
        // 読み込み前の処理（何もしない）
    }

    public function afterRead(string $sessionId, string $data): string
    {
        // メタデータを更新する処理
        // （実装は省略）
        return $data;
    }
}
```

### FallbackReadHook

フォールバックRedisからの読み込みを行うHook。

```php
class FallbackReadHook implements ReadHookInterface
{
    private RedisConnection $fallbackConnection;
    private LoggerInterface $logger;

    public function beforeRead(string $sessionId): void
    {
        // 読み込み前の処理（何もしない）
    }

    public function afterRead(string $sessionId, string $data): string
    {
        if ($data !== '') {
            // データがあればそのまま返す
            return $data;
        }

        // データが空ならフォールバックから読み込み
        // （実装は省略）
        return $data;
    }
}
```

## セキュリティ考慮事項

### セッションIDのマスキング

すべてのHook/Filterで、ログ出力時は必ず`SessionIdMasker`を使用：

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$this->logger->info('Session operation', [
    'session_id' => SessionIdMasker::mask($sessionId), // ✓ 正しい
    // 'session_id' => $sessionId, // ✗ 間違い（セキュリティリスク）
]);
```

### データロギングの制御

セッションデータには機密情報が含まれる可能性があるため、本番環境ではデータロギングを無効化：

```php
// 開発環境
$loggingHook = new LoggingHook($logger, logData: true);

// 本番環境
$loggingHook = new LoggingHook($logger, logData: false); // データをログに記録しない
```

## パフォーマンス考慮事項

### Hook/Filterの軽量化

Hook/Filterはセッション操作ごとに実行されるため、処理を最小限に：

```php
// ✓ 良い例：軽量な処理
public function beforeWrite(string $sessionId, array $data): array
{
    $data['timestamp'] = time();
    return $data;
}

// ✗ 悪い例：重い処理
public function beforeWrite(string $sessionId, array $data): array
{
    // データベースクエリ（遅い）
    $user = $this->db->query('SELECT * FROM users WHERE session_id = ?', [$sessionId]);
    $data['user'] = $user;
    return $data;
}
```

### Filter早期終了の活用

WriteFilterで早期に`false`を返すことで、不要な処理をスキップ：

```php
// 最初のFilterで空セッションを検出してスキップ
$handler->addWriteFilter(new EmptySessionFilter($logger));
// ↑ ここでfalseが返れば、以降の処理は全てスキップされる

$handler->addWriteHook(new ExpensiveEncryptionHook()); // 実行されない
$handler->addWriteHook(new DoubleWriteHook($secondary)); // 実行されない
```

## テスト

### WriteFilterのテスト

```php
public function testEmptySessionFilterCancelsWrite(): void
{
    $logger = new NullLogger();
    $filter = new EmptySessionFilter($logger);

    // 空セッション
    $result = $filter->shouldWrite('session123', []);
    $this->assertFalse($result);
    $this->assertTrue($filter->wasLastWriteEmpty());

    // データがあるセッション
    $result = $filter->shouldWrite('session123', ['user_id' => 123]);
    $this->assertTrue($result);
    $this->assertFalse($filter->wasLastWriteEmpty());
}
```

### WriteHookのテスト

```php
public function testLoggingHookLogsOperations(): void
{
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
        ->method('log')
        ->with(LogLevel::INFO, 'Session write started');

    $hook = new LoggingHook($logger);
    $data = $hook->beforeWrite('session123', ['user_id' => 123]);

    $this->assertEquals(['user_id' => 123], $data);
}
```

### 統合テスト

```php
public function testFilterAndHookIntegration(): void
{
    $handler = new RedisSessionHandler(/*...*/);

    // Filter: 空セッションをスキップ
    $filter = new EmptySessionFilter(new NullLogger());
    $handler->addWriteFilter($filter);

    // Hook: ログ記録
    $logger = $this->createMock(LoggerInterface::class);
    $hook = new LoggingHook($logger);
    $handler->addWriteHook($hook);

    // 空セッションを書き込もうとする
    $result = $handler->write('session123', ''); // 空文字列

    // Filterによりキャンセルされる
    $this->assertFalse($result);
}
```

## まとめ

Hook/Filter機構により、以下が実現できます：

1. **柔軟な拡張**: コア機能を変更せずに機能追加
2. **明確な責務分離**:
   - ReadHook: 読み込み時の処理
   - WriteHook: データ変換
   - WriteFilter: 書き込み可否の判断
3. **チェーン化**: 複数のHook/Filterを組み合わせて複雑な処理を構築
4. **セキュリティ**: SessionIdMaskerによるログ保護
5. **パフォーマンス**: Filter早期終了による最適化

## 関連ドキュメント

- [serializer.md](serializer.md) - Serializerによる配列⇔文字列変換
- [prevent-empty-cookie.md](prevent-empty-cookie.md) - EmptySessionFilterの使用例
- [../architecture.md](../architecture.md) - 全体設計
- [../../plugin-developers/creating-hooks.md](../../plugin-developers/creating-hooks.md) - Hook作成ガイド（プラグイン開発者向け）
- [../../plugin-developers/creating-filters.md](../../plugin-developers/creating-filters.md) - Filter作成ガイド（プラグイン開発者向け）
