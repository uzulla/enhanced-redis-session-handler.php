# コードレビュー結果

## レビュー観点
- セキュリティ的に問題はないか？
- コードで可読性が悪い箇所はないか？
- なにかバグを引き起こしやすい箇所はないか？
- PHPの型を活用できてない箇所はないか？
- テストが不足しているケースがないか？

## トリアージ分類
- **致命的**: セキュリティ脆弱性やデータ損失につながる問題
- **問題有り**: バグや重大な品質問題につながる可能性が高い
- **憂慮**: 将来的に問題となる可能性がある
- **お勧め**: より良い実装への改善提案

---

## 1. src/Config/RedisConnectionConfig.php

### 【問題有り】バリデーション不足

コンストラクタで設定値の妥当性チェックが行われていません。不正な値が渡された場合、実行時にRedis接続時まで問題が検出されません。

**指摘箇所**: src/Config/RedisConnectionConfig.php:19-41

**問題点**:
- ポート番号が1-65535の範囲外
- タイムアウト値が負数
- データベース番号が負数
- maxRetriesが負数
- retryIntervalが負数

**Before**:
```php
public function __construct(
    string $host = 'localhost',
    int $port = 6379,
    float $timeout = 2.5,
    // ... 省略
) {
    $this->host = $host;
    $this->port = $port;
    $this->timeout = $timeout;
    // ... 省略
}
```

**After**:
```php
public function __construct(
    string $host = 'localhost',
    int $port = 6379,
    float $timeout = 2.5,
    ?string $password = null,
    int $database = 0,
    string $prefix = 'session:',
    bool $persistent = false,
    int $retryInterval = 100,
    float $readTimeout = 2.5,
    int $maxRetries = 3
) {
    if ($host === '') {
        throw new \InvalidArgumentException('Host cannot be empty');
    }
    if ($port < 1 || $port > 65535) {
        throw new \InvalidArgumentException('Port must be between 1 and 65535');
    }
    if ($timeout < 0) {
        throw new \InvalidArgumentException('Timeout must be non-negative');
    }
    if ($readTimeout < 0) {
        throw new \InvalidArgumentException('Read timeout must be non-negative');
    }
    if ($database < 0) {
        throw new \InvalidArgumentException('Database must be non-negative');
    }
    if ($maxRetries < 0) {
        throw new \InvalidArgumentException('Max retries must be non-negative');
    }
    if ($retryInterval < 0) {
        throw new \InvalidArgumentException('Retry interval must be non-negative');
    }

    $this->host = $host;
    $this->port = $port;
    // ... 残りの代入
}
```

---

## 2. src/Config/RedisSessionHandlerOptions.php

### 【問題有り】maxLifetimeのバリデーション不足

`maxLifetime`に負数やゼロが渡される可能性があり、セッションの有効期限が意図しない動作になる可能性があります。

**指摘箇所**: src/Config/RedisSessionHandlerOptions.php:17-25

**Before**:
```php
public function __construct(
    ?SessionIdGeneratorInterface $idGenerator = null,
    ?int $maxLifetime = null,
    ?LoggerInterface $logger = null
) {
    $this->idGenerator = $idGenerator ?? new DefaultSessionIdGenerator();
    $this->maxLifetime = $maxLifetime ?? (int)ini_get('session.gc_maxlifetime');
    $this->logger = $logger ?? new NullLogger();
}
```

**After**:
```php
public function __construct(
    ?SessionIdGeneratorInterface $idGenerator = null,
    ?int $maxLifetime = null,
    ?LoggerInterface $logger = null
) {
    $this->idGenerator = $idGenerator ?? new DefaultSessionIdGenerator();

    $lifetime = $maxLifetime ?? (int)ini_get('session.gc_maxlifetime');
    if ($lifetime <= 0) {
        throw new \InvalidArgumentException('Max lifetime must be positive');
    }
    $this->maxLifetime = $lifetime;

    $this->logger = $logger ?? new NullLogger();
}
```

### 【問題有り】ini_getの戻り値の型安全性

`ini_get()`は`string|false`を返すため、設定が存在しない場合に`(int)false`が0になってしまいます。

**指摘箇所**: src/Config/RedisSessionHandlerOptions.php:23

**After**:
```php
$iniValue = ini_get('session.gc_maxlifetime');
$lifetime = $maxLifetime ?? ($iniValue !== false ? (int)$iniValue : 1440); // デフォルト24分
if ($lifetime <= 0) {
    throw new \InvalidArgumentException('Max lifetime must be positive');
}
$this->maxLifetime = $lifetime;
```

---

## 3. src/Config/SessionConfig.php

### 【問題有り】設定オブジェクトがミュータブル

設定オブジェクトにsetterメソッドが存在し、構築後も変更可能です。複数のコンポーネントから参照される設定オブジェクトがミュータブルであることは、予期しない動作やスレッドセーフティの問題を引き起こす可能性があります。

**指摘箇所**: src/Config/SessionConfig.php:104-129

**問題点**:
- `setConnectionConfig()`, `setIdGenerator()`, `setMaxLifetime()`, `setLogger()`メソッドの存在
- Fluent interfaceパターン（return $this）により、チェーンメソッドで変更が可能
- 複数のリクエストから同じインスタンスが参照される場合、競合状態が発生する可能性

**推奨方法**:
1. setterメソッドを削除し、イミュータブルにする
2. または、withXxx()メソッドを提供し、新しいインスタンスを返すイミュータブルパターンを採用する

**After** (イミュータブルパターン):
```php
public function withConnectionConfig(RedisConnectionConfig $config): self
{
    $new = clone $this;
    $new->connectionConfig = $config;
    return $new;
}

public function withMaxLifetime(int $maxLifetime): self
{
    if ($maxLifetime <= 0) {
        throw new ConfigurationException('maxLifetime must be greater than 0');
    }
    $new = clone $this;
    $new->maxLifetime = $maxLifetime;
    return $new;
}

// 他のsetterも同様に変更
```

### 【憂慮】配列型アノテーションが不正確

PHPDocの配列型アノテーションが不完全です。キーの型も指定すべきです。

**指摘箇所**: src/Config/SessionConfig.php:21-26

**Before**:
```php
/** @var array<ReadHookInterface> */
private array $readHooks = [];
/** @var array<WriteHookInterface> */
private array $writeHooks = [];
/** @var array<WriteFilterInterface> */
private array $writeFilters = [];
```

**After**:
```php
/** @var list<ReadHookInterface> */
private array $readHooks = [];
/** @var list<WriteHookInterface> */
private array $writeHooks = [];
/** @var list<WriteFilterInterface> */
private array $writeFilters = [];
```

または：
```php
/** @var array<int, ReadHookInterface> */
private array $readHooks = [];
```

### 【お勧め】バリデーションの重複

`validate()`メソッドと`setMaxLifetime()`で同じバリデーションが重複しています。

**指摘箇所**: src/Config/SessionConfig.php:116-136

**推奨**: イミュータブルパターンを採用する場合、setterメソッドを削除し、バリデーションをコンストラクタとvalidate()メソッドのみに集約する。

---

## 5. src/Hook/DoubleWriteHook.php

### 【問題有り】TTLのバリデーション不足

コンストラクタでTTLのバリデーションが行われていません。負数やゼロが渡される可能性があります。

**指摘箇所**: src/Hook/DoubleWriteHook.php:33-43

**Before**:
```php
public function __construct(
    RedisConnection $secondaryConnection,
    int $ttl = 1440,
    bool $failOnSecondaryError = false,
    ?LoggerInterface $logger = null
) {
    $this->secondaryConnection = $secondaryConnection;
    $this->ttl = $ttl;
    // ...
}
```

**After**:
```php
public function __construct(
    RedisConnection $secondaryConnection,
    int $ttl = 1440,
    bool $failOnSecondaryError = false,
    ?LoggerInterface $logger = null
) {
    if ($ttl <= 0) {
        throw new \InvalidArgumentException('TTL must be positive');
    }
    $this->secondaryConnection = $secondaryConnection;
    $this->ttl = $ttl;
    // ...
}
```

---

## 6. src/Hook/FallbackReadHook.php

### 【問題有り】配列要素の型検証不足

コンストラクタで`$fallbackConnections`配列の各要素が`RedisConnection`インスタンスであることを検証していません。

**指摘箇所**: src/Hook/FallbackReadHook.php:25-29

**Before**:
```php
public function __construct(array $fallbackConnections, LoggerInterface $logger)
{
    $this->fallbackConnections = $fallbackConnections;
    $this->logger = $logger;
}
```

**After**:
```php
/**
 * @param array<RedisConnection> $fallbackConnections
 * @param LoggerInterface $logger
 */
public function __construct(array $fallbackConnections, LoggerInterface $logger)
{
    if (empty($fallbackConnections)) {
        throw new \InvalidArgumentException('At least one fallback connection is required');
    }

    foreach ($fallbackConnections as $connection) {
        if (!$connection instanceof RedisConnection) {
            throw new \InvalidArgumentException(
                'All fallback connections must be instances of RedisConnection'
            );
        }
    }

    $this->fallbackConnections = $fallbackConnections;
    $this->logger = $logger;
}
```

### 【憂慮】配列型アノテーションが不正確

PHPDocの配列型アノテーションで、キーの型が明示されていません。

**指摘箇所**: src/Hook/FallbackReadHook.php:18

**Before**:
```php
/** @var array<RedisConnection> */
private array $fallbackConnections;
```

**After**:
```php
/** @var list<RedisConnection> */
private array $fallbackConnections;
```

または：
```php
/** @var array<int, RedisConnection> */
private array $fallbackConnections;
```

---

## 7. src/Hook/LoggingHook.php

### 【憂慮】ログレベルのバリデーション不足

コンストラクタでログレベル文字列の妥当性が検証されていません。無効なログレベルが渡されても実行時まで検出されません。

**指摘箇所**: src/Hook/LoggingHook.php:32-44

**推奨**: PSR-3のLogLevelクラスで定義された定数以外を受け付けないようにバリデーションを追加するか、またはログレベルのenum型（PHP 8.1+）を使用する。

**After**:
```php
public function __construct(
    LoggerInterface $logger,
    string $beforeWriteLevel = LogLevel::DEBUG,
    string $afterWriteLevel = LogLevel::DEBUG,
    string $errorLevel = LogLevel::ERROR,
    bool $logData = false
) {
    $validLevels = [
        LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE,
        LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL,
        LogLevel::ALERT, LogLevel::EMERGENCY,
    ];

    if (!in_array($beforeWriteLevel, $validLevels, true)) {
        throw new \InvalidArgumentException('Invalid log level for beforeWrite');
    }
    if (!in_array($afterWriteLevel, $validLevels, true)) {
        throw new \InvalidArgumentException('Invalid log level for afterWrite');
    }
    if (!in_array($errorLevel, $validLevels, true)) {
        throw new \InvalidArgumentException('Invalid log level for error');
    }

    $this->logger = $logger;
    $this->beforeWriteLevel = $beforeWriteLevel;
    $this->afterWriteLevel = $afterWriteLevel;
    $this->errorLevel = $errorLevel;
    $this->logData = $logData;
}
```

### 【お勧め】セキュリティ警告の強化

`$logData`フラグがtrueの場合、セッションデータがログに記録されます。PHPDocには記載されていますが、より明示的な警告を追加することを推奨します。

**指摘箇所**: src/Hook/LoggingHook.php:30

現在のドキュメントは適切ですが、実装箇所でも警告コメントを追加することを推奨します。

---

## 9. src/Hook/ReadTimestampHook.php

### 【問題有り】パラメータのバリデーション不足

コンストラクタで`timestampTtl`や`timestampKeyPrefix`のバリデーションが行われていません。

**指摘箇所**: src/Hook/ReadTimestampHook.php:28-38

**Before**:
```php
public function __construct(
    RedisConnection $connection,
    LoggerInterface $logger,
    string $timestampKeyPrefix = 'session:read_at:',
    int $timestampTtl = 86400
) {
    $this->connection = $connection;
    $this->logger = $logger;
    $this->timestampKeyPrefix = $timestampKeyPrefix;
    $this->timestampTtl = $timestampTtl;
}
```

**After**:
```php
public function __construct(
    RedisConnection $connection,
    LoggerInterface $logger,
    string $timestampKeyPrefix = 'session:read_at:',
    int $timestampTtl = 86400
) {
    if ($timestampKeyPrefix === '') {
        throw new \InvalidArgumentException('Timestamp key prefix cannot be empty');
    }
    if ($timestampTtl <= 0) {
        throw new \InvalidArgumentException('Timestamp TTL must be positive');
    }

    $this->connection = $connection;
    $this->logger = $logger;
    $this->timestampKeyPrefix = $timestampKeyPrefix;
    $this->timestampTtl = $timestampTtl;
}
```

---

## 11. src/RedisConnection.php

### 【問題有り】delete()メソッドの戻り値が不正確

`Redis::del()`は削除された要素数（int）を返しますが、このメソッドは常に`true`を返しています。キーが存在しない場合でも`true`を返すため、呼び出し元で削除が成功したかを正確に判断できません。

**指摘箇所**: src/RedisConnection.php:173-187

**Before**:
```php
public function delete(string $key): bool
{
    $this->connect();

    try {
        $this->redis->del($key);
        return true;
    } catch (RedisException $e) {
        // エラー処理
        return false;
    }
}
```

**After**:
```php
public function delete(string $key): bool
{
    $this->connect();

    try {
        $result = $this->redis->del($key);
        // del()は削除された要素数を返す。0より大きければ削除成功
        return $result > 0;
    } catch (RedisException $e) {
        $this->logger->error('Redis DELETE operation failed', [
            'exception' => $e,
            'key' => $key,
        ]);
        return false;
    }
}
```

### 【問題有り】set()メソッドのTTLバリデーション不足

TTLが0以下の値が渡された場合、setex()の動作が未定義または予期しない結果になる可能性があります。

**指摘箇所**: src/RedisConnection.php:157-171

**After**:
```php
public function set(string $key, string $value, int $ttl): bool
{
    if ($ttl <= 0) {
        throw new \InvalidArgumentException('TTL must be positive');
    }

    $this->connect();

    try {
        $result = $this->redis->setex($key, $ttl, $value);
        return $result !== false;
    } catch (RedisException $e) {
        // エラー処理
        return false;
    }
}
```

### 【憂慮】keys()メソッドのSCAN実装

`scan()`の戻り値が`false`の場合に終了するロジックですが、空配列が返された場合の扱いが明確ではありません。

**指摘箇所**: src/RedisConnection.php:230-255

**現在の実装**:
```php
while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
    foreach ($scanKeys as $key) {
        $keys[] = str_replace($prefix, '', $key);
    }
}
```

**推奨**: この実装は概ね正しいですが、コメントを追加して意図を明確にすることを推奨します。

```php
// SCAN は完了時にiteratorをnullに設定し、空配列を返す
// falseが返されることはほぼないが、エラー時のフォールバックとして判定
while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
    foreach ($scanKeys as $key) {
        $keys[] = str_replace($prefix, '', $key);
    }
}
```

### 【憂慮】接続状態の競合状態の可能性

`$connected` フラグがミュータブルで、マルチスレッド環境やFPM環境で複数のリクエストから同時に呼び出された場合、競合状態が発生する可能性があります。ただし、PHPはリクエストごとにプロセスが分離されているため、実質的な問題になるケースは限定的です。

**指摘箇所**: src/RedisConnection.php:19, 28-120

**推奨**: 現状のPHPの実行モデル（リクエストごとにプロセス分離）では問題ありませんが、将来的にReactPHPやSwooleなどの並行実行環境で使用する場合は、接続管理の見直しが必要です。

---

## 12. src/RedisSessionHandler.php

### 【問題有り】write()メソッドでのunserializeエラー抑制

エラー抑制演算子`@`を使用してunserializeのエラーを隠蔽しています。これにより、デシリアライゼーションエラーが検出されにくくなります。

**指摘箇所**: src/RedisSessionHandler.php:175

**Before**:
```php
if ($data !== '') {
    $unserialized = @unserialize($data);
    if ($unserialized !== false || $data === 'b:0;') {
        if (is_array($unserialized)) {
            /** @var array<string, mixed> $unserialized */
            $unserializedData = $unserialized;
        }
    }
}
```

**After**:
```php
if ($data !== '') {
    set_error_handler(function() {});
    $unserialized = unserialize($data);
    restore_error_handler();

    if ($unserialized !== false || $data === serialize(false)) {
        if (is_array($unserialized)) {
            /** @var array<string, mixed> $unserialized */
            $unserializedData = $unserialized;
        } else {
            // セッションデータが配列でない場合のログ記録
            $this->logger->warning('Session data is not an array', [
                'session_id' => $id,
                'type' => gettype($unserialized),
            ]);
        }
    } else {
        // デシリアライゼーション失敗時のログ記録
        $this->logger->warning('Failed to unserialize session data', [
            'session_id' => $id,
        ]);
    }
}
```

### 【問題有り】create_sid()メソッドの無限ループの可能性

セッションIDが衝突し続ける場合、無限ループに陥る可能性があります。

**指摘箇所**: src/RedisSessionHandler.php:264-271

**Before**:
```php
public function create_sid(): string
{
    do {
        $sessionId = $this->idGenerator->generate();
    } while ($this->connection->exists($sessionId));

    return $sessionId;
}
```

**After**:
```php
public function create_sid(): string
{
    $maxAttempts = 10;
    $attempt = 0;

    do {
        $sessionId = $this->idGenerator->generate();
        $attempt++;

        if ($attempt >= $maxAttempts) {
            $this->logger->critical('Failed to generate unique session ID after maximum attempts', [
                'attempts' => $maxAttempts,
            ]);
            throw new OperationException('Failed to generate unique session ID');
        }
    } while ($this->connection->exists($sessionId));

    if ($attempt > 1) {
        $this->logger->warning('Session ID collision occurred', [
            'attempts' => $attempt,
        ]);
    }

    return $sessionId;
}
```

### 【問題有り】read()メソッドがfalseの代わりに空文字列を返す

Redis::get()が`false`を返した場合、空文字列を返しています。これはセッションが存在しない場合と、空のセッションデータの場合を区別できなくなります。

**指摘箇所**: src/RedisSessionHandler.php:117-119

**考察**: SessionHandlerInterfaceの仕様上、存在しないセッションには空文字列を返すのが正しい実装です。ただし、ログ記録を追加することで問題を明確にできます。

**After**:
```php
if ($data === false) {
    $this->logger->debug('Session not found in Redis', [
        'session_id' => $id,
    ]);
    return '';
}
```

### 【憂慮】配列型アノテーションが不正確

**指摘箇所**: src/RedisSessionHandler.php:22-27

**Before**:
```php
/** @var array<ReadHookInterface> */
private array $readHooks = [];
```

**After**:
```php
/** @var list<ReadHookInterface> */
private array $readHooks = [];
```

---

## 13. src/SessionHandlerFactory.php

### 【憂慮】build()メソッドが毎回新しいインスタンスを作成

`build()`メソッドを複数回呼び出すと、毎回新しいRedis接続とハンドラインスタンスが作成されます。これは意図的な設計かもしれませんが、ドキュメント化されていません。

**指摘箇所**: src/SessionHandlerFactory.php:18-48

ドキュメントコメントで、毎回新しいインスタンスが作成されることを明記する

**After** (ドキュメント追加の場合):
```php
/**
 * Build a new RedisSessionHandler instance.
 *
 * Note: This method creates a new instance every time it is called.
 * Each call will create a new Redis connection and handler instance.
 *
 * @return RedisSessionHandler
 */
public function build(): RedisSessionHandler
{
    // ...
}
```

---

## 14. src/SessionId/ 配下の全ジェネレータクラス

以下のファイルをレビュー：
- SessionIdGeneratorInterface.php
- DefaultSessionIdGenerator.php
- SecureSessionIdGenerator.php
- PrefixedSessionIdGenerator.php
- TimestampPrefixedSessionIdGenerator.php

### 【憂慮】PrefixedSessionIdGeneratorのプレフィックスバリデーション不足

プレフィックスにアンダースコアが含まれる場合、生成されるセッションIDのパースが困難になります。また、特殊文字が含まれるとセキュリティ上の問題が発生する可能性があります。

**指摘箇所**: src/SessionId/PrefixedSessionIdGenerator.php:81-98

**Before**:
```php
public function __construct(string $prefix = 'app', int $randomLength = 32)
{
    if ($prefix === '') {
        throw new \InvalidArgumentException('Prefix cannot be empty');
    }
    // ...
}
```

**After**:
```php
public function __construct(string $prefix = 'app', int $randomLength = 32)
{
    if ($prefix === '') {
        throw new \InvalidArgumentException('Prefix cannot be empty');
    }
    // プレフィックスに使用できる文字を英数字とハイフンのみに制限
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $prefix)) {
        throw new \InvalidArgumentException(
            'Prefix can only contain alphanumeric characters and hyphens'
        );
    }
    // アンダースコアを禁止（区切り文字として使用されるため）
    if (str_contains($prefix, '_')) {
        throw new \InvalidArgumentException(
            'Prefix cannot contain underscores (reserved as delimiter)'
        );
    }
    // ...
}
```

---

## 15. tests/Config/SessionConfigTest.php

### 【問題有り】ミュータブルな設計を前提としたテスト

現在のテストは`SessionConfig`のsetterメソッドをテストしていますが、レビューで指摘したように、設定オブジェクトはイミュータブルであるべきです。イミュータブルパターンを採用する場合、これらのテストは変更が必要です。

**指摘箇所**: tests/Config/SessionConfigTest.php:48-230

**推奨**: `SessionConfig`をイミュータブルにする場合、以下のようにテストを変更してください：

**After** (イミュータブルパターンの場合):
```php
public function testWithConnectionConfig(): void
{
    $config = $this->createDefaultConfig();
    $newConnectionConfig = new RedisConnectionConfig('redis.example.com', 6380);

    $newConfig = $config->withConnectionConfig($newConnectionConfig);

    // 元のオブジェクトは変更されていないことを確認
    self::assertNotSame($config, $newConfig);
    self::assertNotSame($newConnectionConfig, $config->getConnectionConfig());

    // 新しいオブジェクトは変更されていることを確認
    self::assertSame($newConnectionConfig, $newConfig->getConnectionConfig());
}
```

### 【テスト不足】設定クラスのバリデーションテストが不足

以下のクラスに対するバリデーションテストが不足しています：

**不足しているテスト**:

1. **RedisConnectionConfigTest** (存在しない可能性):
   - ポート番号の範囲外チェック（0, 65536など）
   - タイムアウト値の負数チェック
   - データベース番号の負数チェック
   - maxRetriesの負数チェック
   - ホスト名が空文字列の場合

2. **RedisSessionHandlerOptionsTest** (存在しない可能性):
   - maxLifetimeが0以下の場合
   - ini_getが失敗した場合のフォールバック

3. **各Hookクラスのテスト** (存在しない可能性):
   - DoubleWriteHookのTTLバリデーション
   - FallbackReadHookの空配列チェック
   - LoggingHookのログレベルバリデーション
   - ReadTimestampHookのパラメータバリデーション

4. **SessionIdGeneratorのテスト** (存在しない可能性):
   - SecureSessionIdGeneratorの長さバリデーション
   - PrefixedSessionIdGeneratorのプレフィックスバリデーション
   - 各ジェネレータが一意のIDを生成することの確認

5. **RedisConnectionのテスト**:
   - delete()メソッドが正しく削除数を返すか
   - set()メソッドのTTLバリデーション
   - create_sid()の無限ループ防止

6. **RedisSessionHandlerのテスト**:
   - write()メソッドのunserializeエラーハンドリング

**推奨**: これらのクラスに対するテストファイルを作成し、バリデーションロジックや境界値のテストを追加してください。

---

## 全体的な総評

### 致命的な問題
該当なし

### 問題有りの項目（早急な対応推奨）
1. 各設定クラスのバリデーション不足（入力値チェックがない）
2. RedisConnection::delete()の戻り値が不正確
3. RedisConnection::set()のTTLバリデーション不足
4. RedisSessionHandler::write()のエラー抑制
5. RedisSessionHandler::create_sid()の無限ループの可能性
6. 配列要素の型検証不足（FallbackReadHook等）
7. SessionConfigのミュータビリティ問題

### 憂慮すべき項目
1. 配列型アノテーションの不正確さ（多数のファイル）
2. クラスがfinalでない（ほぼ全てのクラス）
3. メモリリークの可能性（DoubleWriteHook）
4. ログレベルのバリデーション不足
5. PrefixedSessionIdGeneratorのプレフィックスバリデーション不足

### お勧めの改善
1. イミュータブルパターンの採用（設定クラス）
2. テストカバレッジの向上
3. PHPDocの改善

---

