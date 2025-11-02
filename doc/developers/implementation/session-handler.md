# RedisSessionHandler実装詳細

## 概要

`RedisSessionHandler`は、PHPの`SessionHandlerInterface`と`SessionUpdateTimestampHandlerInterface`を実装したクラスです。Redis/ValKeyをバックエンドストレージとして使用し、セッション管理機能を提供します。

## 実装するインターフェース

### SessionHandlerInterface

PHPの標準セッションハンドラインターフェース：

```php
interface SessionHandlerInterface
{
    public function open($path, $name): bool;
    public function close(): bool;
    public function read($id): string|false;
    public function write($id, $data): bool;
    public function destroy($id): bool;
    public function gc($max_lifetime): int|false;
}
```

### SessionUpdateTimestampHandlerInterface

セッションタイムスタンプ更新用インターフェース（PHP 7.0+）：

```php
interface SessionUpdateTimestampHandlerInterface
{
    public function validateId($id): bool;
    public function updateTimestamp($id, $data): bool;
}
```

## クラス構造

```php
class RedisSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface,
    LoggerAwareInterface
{
    private RedisConnection $connection;
    private SessionIdGeneratorInterface $idGenerator;
    private LoggerInterface $logger;
    private array $readHooks = [];
    private array $writeHooks = [];
    private array $writeFilters = [];
    private int $maxLifetime;
    private SessionSerializerInterface $serializer;
}
```

### 依存コンポーネント

1. **RedisConnection**: Redis/ValKeyへの接続管理
2. **SessionIdGeneratorInterface**: セッションID生成ロジック
3. **SessionSerializerInterface**: シリアライズ/デシリアライズ
4. **ReadHookInterface**: 読み込みフック
5. **WriteHookInterface**: 書き込みフック
6. **WriteFilterInterface**: 書き込みフィルター
7. **LoggerInterface**: PSR-3ロガー

## メソッド実装詳細

### open($path, $name): bool

セッション初期化処理。

**処理フロー**:
```
1. session.serialize_handler を取得
   ↓
2. 注入されたSerializerと一致するか検証
   ↓
3. 一致しない場合 ConfigurationException をスロー
   ↓
4. RedisConnection::connect() を呼び出し
   ↓
5. 接続成功時は true、失敗時は false を返す
```

**重要な検証**:
```php
$serializeHandler = ini_get('session.serialize_handler');
$serializerName = $this->serializer->getName();

if ($serializerName !== $serializeHandler) {
    throw new ConfigurationException(
        sprintf(
            'Serializer mismatch: injected serializer is "%s" but ' .
            'session.serialize_handler is "%s".',
            $serializerName,
            $serializeHandler
        )
    );
}
```

この検証により、PHPのセッション拡張とSerializerの不一致を防ぎます。

### close(): bool

セッションクローズ処理。

**実装**:
```php
public function close(): bool
{
    return true;
}
```

**設計理由**:
- Redis接続は`RedisConnection`クラスが管理
- 永続接続の場合は接続を維持
- 非永続接続の場合も明示的なクローズは不要
- 常に`true`を返して成功を示す

### read($id): string|false

セッションデータの読み込み。

**処理フロー**:
```
1. ReadHook::beforeRead() を全て実行
   ↓
2. RedisConnection::get($id) でデータ取得
   ↓
3. データが無い場合は空文字列を返す
   ↓
4. ReadHook::afterRead() を全て実行（データ変換可能）
   ↓
5. 変換後のデータを返す
   ↓
6. エラー発生時は ReadHook::onReadError() を実行
   ↓
7. フォールバックデータがあればそれを返す
   ↓
8. 無ければ空文字列を返す
```

**データフロー図**:
```
Redis → 文字列データ → ReadHook (afterRead) → 文字列データ → PHP Session拡張
```

**エラーハンドリング**:
```php
try {
    $data = $this->connection->get($id);
    // ... フック実行 ...
    return $data;
} catch (Throwable $e) {
    $this->logger->error('Error during session read', [
        'session_id' => SessionIdMasker::mask($id),
        'exception' => $e,
    ]);

    // フォールバックデータを試行
    foreach ($this->readHooks as $hook) {
        $fallbackData = $hook->onReadError($id, $e);
        if ($fallbackData !== null) {
            return $fallbackData;
        }
    }

    return '';
}
```

### write($id, $data): bool

セッションデータの書き込み。最も複雑な処理。

**処理フロー**:
```
1. データをデシリアライズ（Serializer::decode）
   ↓
2. WriteHook::beforeWrite() を全て実行（配列変換）
   ↓
3. WriteFilter::shouldWrite() を全て実行
   ↓
4. いずれかが false を返したら書き込みキャンセル（true を返す）
   ↓
5. データをシリアライズ（Serializer::encode）
   ↓
6. RedisConnection::set($id, $data, $ttl) で保存
   ↓
7. WriteHook::afterWrite($id, $success) を全て実行
   ↓
8. 成功/失敗を返す
   ↓
9. エラー発生時は WriteHook::onWriteError() を実行
```

**データフロー図**:
```
PHP Session拡張
    ↓ (シリアライズされた文字列)
Serializer::decode()
    ↓ (配列)
WriteHook::beforeWrite()
    ↓ (変換された配列)
WriteFilter::shouldWrite()
    ↓ (書き込み可否判断)
Serializer::encode()
    ↓ (シリアライズされた文字列)
RedisConnection::set()
    ↓
Redis
```

**重要な実装ポイント**:

1. **デシリアライズ→フック→シリアライズのパターン**:
```php
// 文字列 → 配列に変換
$unserializedData = $this->serializer->decode($data);

// フックは配列を受け取り、配列を返す
foreach ($this->writeHooks as $hook) {
    $unserializedData = $hook->beforeWrite($id, $unserializedData);
}

// 配列 → 文字列に変換
$serializedData = $this->serializer->encode($unserializedData);
```

2. **WriteFilterによる書き込みキャンセル**:
```php
foreach ($this->writeFilters as $filter) {
    if (!$filter->shouldWrite($id, $unserializedData)) {
        $this->logger->debug('Write operation cancelled by filter', [
            'session_id' => SessionIdMasker::mask($id),
            'filter' => get_class($filter),
        ]);
        return true; // キャンセルはエラーではないので true を返す
    }
}
```

3. **TTLの設定**:
```php
$ttl = $this->getTTL(); // 最小60秒を保証
$success = $this->connection->set($id, $serializedData, $ttl);
```

### destroy($id): bool

セッションの削除。

**実装**:
```php
public function destroy($id): bool
{
    assert(is_string($id));
    return $this->connection->delete($id);
}
```

シンプルに`RedisConnection`に委譲します。

### gc($max_lifetime): int|false

ガベージコレクション。

**実装**:
```php
public function gc($max_lifetime)
{
    return 0;
}
```

**設計理由**:
- RedisのTTL機能により自動的にガベージコレクションが実行される
- 各セッションキーに有効期限が設定されているため、手動GCは不要
- 常に`0`を返す（削除されたセッション数は0）

### validateId($id): bool

セッションIDの検証（SessionUpdateTimestampHandlerInterface）。

**実装**:
```php
public function validateId($id): bool
{
    assert(is_string($id));
    return $this->connection->exists($id);
}
```

Redisにキーが存在するかチェックします。

### updateTimestamp($id, $data): bool

セッションのタイムスタンプ更新（SessionUpdateTimestampHandlerInterface）。

**実装**:
```php
public function updateTimestamp($id, $data): bool
{
    assert(is_string($id));
    $ttl = $this->getTTL();
    return $this->connection->expire($id, $ttl);
}
```

**目的**:
- セッションデータを書き換えずにTTLだけを更新
- `session.lazy_write=1`設定時に有用
- データが変更されていない場合の書き込みを避ける

### create_sid(): string

セッションIDの生成。

**処理フロー**:
```
最大10回まで試行
  ↓
1. SessionIdGenerator::generate() で生成
   ↓
2. RedisConnection::exists() で衝突チェック
   ↓
3. 衝突していなければIDを返す
   ↓
4. 衝突していたら次の試行へ
   ↓
10回全て衝突 → OperationException をスロー
```

**実装**:
```php
public function create_sid(): string
{
    $maxAttempts = 10;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $sessionId = $this->idGenerator->generate();
        if (!$this->connection->exists($sessionId)) {
            if ($attempt > 1) {
                $this->logger->warning('Session ID collision occurred', [
                    'attempts' => $attempt,
                ]);
            }
            return $sessionId;
        }
    }

    $this->logger->critical('Failed to generate unique session ID after maximum attempts', [
        'attempts' => $maxAttempts,
    ]);
    throw new OperationException('Failed to generate unique session ID');
}
```

**設計理由**:
- セッションIDの衝突を検出し、リトライ
- 10回試行しても衝突する場合は異常として例外をスロー
- 衝突発生時は警告ログを出力

## プライベートメソッド

### getTTL(): int

RedisキーのTTL（有効期限）を取得。

**実装**:
```php
private function getTTL(): int
{
    return max(60, $this->maxLifetime);
}
```

**最小TTLの保証**:
- 最低60秒のTTLを保証
- `session.gc_maxlifetime`が極端に短い値（例: 1秒）に設定されている場合の保護
- セッションが即座に期限切れになるのを防ぐ

## フック・フィルター機構

### フックの追加

```php
public function addReadHook(ReadHookInterface $hook): void
{
    $this->readHooks[] = $hook;
}

public function addWriteHook(WriteHookInterface $hook): void
{
    $this->writeHooks[] = $hook;
}

public function addWriteFilter(WriteFilterInterface $filter): void
{
    $this->writeFilters[] = $filter;
}
```

### 実行順序

**read()でのフック実行**:
```
1. ReadHook::beforeRead() (登録順)
2. Redis から読み込み
3. ReadHook::afterRead() (登録順、データ変換可能)
4. エラー時: ReadHook::onReadError() (登録順)
```

**write()でのフック・フィルター実行**:
```
1. Serializer::decode()
2. WriteHook::beforeWrite() (登録順、データ変換)
3. WriteFilter::shouldWrite() (登録順、早期終了可能)
4. Serializer::encode()
5. Redis へ書き込み
6. WriteHook::afterWrite() (登録順)
7. エラー時: WriteHook::onWriteError() (登録順)
```

## エラーハンドリング

### 例外の種類

1. **ConfigurationException**:
   - Serializer不一致時
   - `open()`メソッドでスロー
   - 再スローされる（上位に伝播）

2. **OperationException**:
   - セッションID生成失敗時
   - `create_sid()`メソッドでスロー

3. **その他の例外**:
   - `read()`/`write()`では例外をキャッチし、`false`や空文字列を返す
   - ログに記録

### ログ出力

全てのログでセッションIDをマスキング：

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$this->logger->debug('Session operation', [
    'session_id' => SessionIdMasker::mask($id), // "...a1b2"
]);
```

## パフォーマンス最適化

### 1. TTLによる自動GC

```php
public function gc($max_lifetime)
{
    return 0; // Redis TTL に任せる
}
```

RedisのTTL機能を活用することで、手動GCのオーバーヘッドを排除。

### 2. 最小限のデシリアライズ

`read()`メソッドではデシリアライズを行わず、文字列をそのまま返します。デシリアライズはPHPのセッション拡張が行います。

### 3. WriteFilterによる早期終了

```php
foreach ($this->writeFilters as $filter) {
    if (!$filter->shouldWrite($id, $unserializedData)) {
        return true; // 以降の処理をスキップ
    }
}
```

空セッションなど、書き込み不要なケースで早期に処理を終了。

## セキュリティ考慮事項

### 1. セッションIDのマスキング

```php
'session_id' => SessionIdMasker::mask($id)
```

ログ出力時は必ずマスキングし、セッションハイジャックのリスクを軽減。

### 2. assert()による型チェック

```php
public function read($id)
{
    assert(is_string($id));
    // ...
}
```

PHP 7.4の`mixed`型引数に対する実行時型チェック。

### 3. ConfigurationExceptionの再スロー

```php
if ($e instanceof Exception\ConfigurationException) {
    throw $e; // 設定エラーは隠蔽しない
}
```

設定ミスは早期に検出し、開発者に通知。

## テストカバレッジ

### 対象テスト

- `tests/RedisSessionHandlerTest.php`: ユニットテスト
- `tests/Integration/`: 統合テスト
- `tests/E2E/`: エンドツーエンドテスト

### 重要なテストケース

1. **Serializer不一致の検出**:
```php
public function testOpenThrowsExceptionWhenSerializerMismatch(): void
{
    // PhpSerializeSerializer を注入
    // session.serialize_handler = 'php' に設定
    // ConfigurationException が発生することを確認
}
```

2. **WriteFilterによるキャンセル**:
```php
public function testWriteIsCancelledByFilter(): void
{
    $filter = new class implements WriteFilterInterface {
        public function shouldWrite(string $sessionId, array $data): bool {
            return false; // 常にキャンセル
        }
    };
    $handler->addWriteFilter($filter);

    $result = $handler->write('id', 'data');
    $this->assertTrue($result); // キャンセルは成功
}
```

3. **セッションID衝突リトライ**:
```php
public function testCreateSidRetriesOnCollision(): void
{
    // Redis に既存のIDを設定
    // create_sid() が別のIDを生成することを確認
}
```

## まとめ

`RedisSessionHandler`の主な特徴：

1. **標準準拠**: PHPの`SessionHandlerInterface`と`SessionUpdateTimestampHandlerInterface`を完全実装
2. **拡張性**: Hook/Filterによる柔軟なカスタマイズ
3. **安全性**: Serializer検証、セッションIDマスキング、エラーハンドリング
4. **効率性**: Redis TTLによる自動GC、WriteFilterによる早期終了
5. **柔軟性**: 複数のSerializerサポート、プラグイン可能な設計

## 関連ドキュメント

- [serializer.md](serializer.md) - Serializer機構の詳細
- [hooks-and-filters.md](hooks-and-filters.md) - Hook/Filter機構の詳細
- [connection.md](connection.md) - RedisConnection実装詳細
- [../architecture.md](../architecture.md) - システムアーキテクチャ
