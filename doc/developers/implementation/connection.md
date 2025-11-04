# RedisConnection実装詳細

## 概要

`RedisConnection`は、ext-redisを使用したRedis/ValKeyへの接続管理を担当するクラスです。接続の確立、リトライ戦略、エラーハンドリング、そしてRedis操作の抽象化を提供します。

## クラス構造

```php
class RedisConnection implements LoggerAwareInterface
{
    private Redis $redis;
    private RedisConnectionConfig $config;
    private LoggerInterface $logger;
    private bool $connected = false;
}
```

### 依存コンポーネント

1. **Redis**: ext-redisの`Redis`クラス（依存性注入される）
2. **RedisConnectionConfig**: 接続設定（ホスト、ポート、認証情報等）
3. **LoggerInterface**: PSR-3ロガー

## 接続管理

### connect(): bool

Redis/ValKeyへの接続を確立します。最も重要なメソッド。

**処理フロー**:
```
1. 既に接続済みなら true を返す（冪等性）
   ↓
2. リトライループ開始（最大 maxRetries 回）
   ↓
3. 永続接続/非永続接続を選択
   ↓
4. Redis::connect() または Redis::pconnect() を呼び出し
   ↓
5. 認証（パスワードが設定されている場合）
   ↓
6. データベース選択（database != 0 の場合）
   ↓
7. オプション設定（READ_TIMEOUT, PREFIX）
   ↓
8. 接続成功フラグを設定
   ↓
9. 失敗時はリトライ（exponential backoff）
   ↓
10. 全リトライ失敗時は ConnectionException をスロー
```

**実装詳細**:

```php
public function connect(): bool
{
    if ($this->connected) {
        return true; // 既に接続済み
    }

    $maxRetries = $this->config->getMaxRetries();
    $retryInterval = $this->config->getRetryInterval();
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            // 接続タイプの選択
            if ($isPersistent) {
                $result = $this->redis->pconnect($host, $port, $timeout, null, $retryInterval);
            } else {
                $result = $this->redis->connect($host, $port, $timeout, null, $retryInterval);
            }

            // 認証
            if ($password !== null) {
                if (!$this->redis->auth($password)) {
                    throw new ConnectionException('Redis authentication failed');
                }
            }

            // データベース選択
            if ($database !== 0) {
                if (!$this->redis->select($database)) {
                    throw new ConnectionException('Failed to select Redis database');
                }
            }

            // オプション設定
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config->getReadTimeout());
            $this->redis->setOption(Redis::OPT_PREFIX, $this->config->getPrefix());

            $this->connected = true;
            return true;

        } catch (RedisException | ConnectionException $e) {
            // リトライ処理
            if ($attempt < $maxRetries) {
                $sleepMs = $retryInterval * $attempt; // exponential backoff
                usleep($sleepMs * 1000);
            }
        }
    }

    // 全リトライ失敗
    throw new ConnectionException(
        'Failed to connect to Redis after ' . $maxRetries . ' attempts: ' . $errorMessage
    );
}
```

**リトライ戦略**:

1. **Exponential Backoff**:
```php
$sleepMs = $retryInterval * $attempt;
// 例: retryInterval=100ms
// 1回目失敗: 100ms待機
// 2回目失敗: 200ms待機
// 3回目失敗: 300ms待機
```

2. **最大リトライ回数**:
```php
// デフォルト: maxRetries = 3
// 設定可能範囲: 1-10回
```

3. **詳細なログ出力**:
```php
$this->logger->warning('Redis connection attempt failed', [
    'attempt' => $attempt,
    'max_retries' => $maxRetries,
    'exception' => $e,
    'host' => $this->config->getHost(),
    'port' => $this->config->getPort(),
]);
```

**永続接続 vs 非永続接続**:

```
┌──────────────────────────────────────────┐
│ 永続接続 (pconnect)                        │
├──────────────────────────────────────────┤
│ - プロセス間で接続を共有                    │
│ - 接続オーバーヘッドを削減                  │
│ - FastCGI/FPM環境で有効                   │
│ - メモリ使用量が増加する可能性               │
└──────────────────────────────────────────┘

┌──────────────────────────────────────────┐
│ 非永続接続 (connect)                       │
├──────────────────────────────────────────┤
│ - リクエストごとに新しい接続                │
│ - メモリ使用量が予測しやすい                │
│ - 接続オーバーヘッドが発生                  │
│ - CLI環境や短命なプロセス向け               │
└──────────────────────────────────────────┘
```

### disconnect(): void

接続を切断します。

```php
public function disconnect(): void
{
    if ($this->connected && !$this->config->isPersistent()) {
        $this->redis->close();
    }
    $this->connected = false;
}
```

**重要なポイント**:
- **永続接続の場合は切断しない** - プロセス間で共有される接続を維持
- **非永続接続のみ明示的にクローズ**
- `$connected`フラグは必ずリセット

### isConnected(): bool

接続状態を確認します。

```php
public function isConnected(): bool
{
    if (!$this->connected) {
        return false;
    }

    try {
        return $this->redis->ping() === '+PONG';
    } catch (RedisException $e) {
        return false;
    }
}
```

**確認方法**:
1. `$connected`フラグをチェック
2. `PING`コマンドでRedisサーバーの応答を確認
3. `+PONG`が返れば接続中

## Redis操作

### get(string $key): string|false

Redisから値を取得します。

```php
public function get(string $key)
{
    $this->connect(); // 自動接続

    try {
        $value = $this->redis->get($key);
        if (is_string($value)) {
            return $value;
        }
        return false;
    } catch (RedisException $e) {
        $this->logger->error('Redis GET operation failed', [
            'error' => $e->getMessage(),
            'key' => $key,
        ]);
        return false;
    }
}
```

**設計ポイント**:
- **`SessionHandlerInterface::read()`との互換性** - `string|false`を返す
- **自動接続** - 呼び出し時に自動で`connect()`を実行
- **例外をキャッチ** - エラー時は`false`を返す
- **ログ出力** - エラー時は詳細をログに記録

### set(string $key, string $value, int $ttl): bool

Redisに値を設定します（TTL付き）。

```php
public function set(string $key, string $value, int $ttl): bool
{
    if ($ttl <= 0) {
        throw new InvalidArgumentException('TTL must be positive');
    }

    $this->connect();

    try {
        $result = $this->redis->setex($key, $ttl, $value);
        return $result !== false;
    } catch (RedisException $e) {
        $this->logger->error('Redis SET operation failed', [
            'error' => $e->getMessage(),
            'key' => $key,
        ]);
        return false;
    }
}
```

**重要**:
- **`SETEX`コマンドを使用** - `SET`+`EXPIRE`のアトミックな操作
- **TTLの検証** - 0以下の場合は`InvalidArgumentException`
- セッション有効期限を自動管理

**なぜSETEXなのか？**:
```
SET + EXPIRE の問題:
1. SET key value
2. (サーバークラッシュ)
3. EXPIRE key ttl ← 実行されない
→ TTL無しのキーが残る（メモリリーク）

SETEX の利点:
1. SETEX key ttl value ← アトミック操作
→ 常にTTL付きで設定される
```

### delete(string $key): bool

Redisからキーを削除します。

```php
public function delete(string $key): bool
{
    $this->connect();

    try {
        $result = $this->redis->del($key);
        // del()は削除された要素数を返す
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

**戻り値の判定**:
- `del()`は削除された要素数（int）を返す
- `> 0`なら削除成功
- `0`ならキーが存在しなかった

### exists(string $key): bool

キーの存在を確認します。

```php
public function exists(string $key): bool
{
    $this->connect();

    try {
        $result = $this->redis->exists($key);
        if (is_int($result)) {
            return $result > 0;
        }
        if (is_bool($result)) {
            return $result;
        }
        return false;
    } catch (RedisException $e) {
        $this->logger->error('Redis EXISTS operation failed', [
            'error' => $e->getMessage(),
            'key' => $key,
        ]);
        return false;
    }
}
```

**ext-redisのバージョン差異**:
- **古いバージョン**: `bool`を返す
- **新しいバージョン**: `int`（存在する数）を返す
- **両方に対応** - 型チェックで判定

### expire(string $key, int $ttl): bool

キーのTTLを更新します。

```php
public function expire(string $key, int $ttl): bool
{
    $this->connect();

    try {
        $result = $this->redis->expire($key, $ttl);
        return $result === true;
    } catch (RedisException $e) {
        $this->logger->error('Redis EXPIRE operation failed', [
            'error' => $e->getMessage(),
            'key' => $key,
        ]);
        return false;
    }
}
```

**用途**:
- `SessionUpdateTimestampHandlerInterface::updateTimestamp()`で使用
- セッションデータを書き換えずにTTLのみ更新
- `session.lazy_write=1`設定時に有効

### keys(string $pattern): array

パターンに一致するキーを取得します。

```php
public function keys(string $pattern): array
{
    $this->connect();

    $prefix = $this->config->getPrefix();
    $fullPattern = $prefix . $pattern;

    $keys = [];
    $iterator = null;

    try {
        while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
            foreach ($scanKeys as $key) {
                $keys[] = str_replace($prefix, '', $key);
            }
        }

        return $keys;
    } catch (RedisException $e) {
        $this->logger->error('Redis SCAN operation failed', [
            'error' => $e->getMessage(),
            'pattern' => $pattern,
        ]);
        return [];
    }
}
```

**重要な実装詳細**:

1. **`KEYS`ではなく`SCAN`を使用**:
```
KEYS の問題:
- ブロッキング操作（全キーをスキャン）
- 大量のキーがある場合にRedisをブロック
- 本番環境では非推奨

SCAN の利点:
- 非ブロッキング（カーソルベース）
- 少しずつキーを取得
- 本番環境でも安全
```

2. **プレフィックスの自動付与と除去**:
```php
// 設定: prefix = "session:"
// 引数: pattern = "user_*"
// Redis検索: "session:user_*"
// 戻り値: ["user_123", "user_456"] (プレフィックス除去済み)
```

3. **カーソルベースのイテレーション**:
```php
$iterator = null;
while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
    // 100件ずつ取得
}
```

## エラーハンドリング

### 例外の種類

1. **ConnectionException**:
   - 接続失敗時
   - 認証失敗時
   - データベース選択失敗時

2. **RedisException** (ext-redis):
   - Redis操作中のエラー
   - ネットワークエラー
   - プロトコルエラー

3. **InvalidArgumentException**:
   - 不正な引数（例: TTL <= 0）

### エラーハンドリングパターン

```php
try {
    $result = $this->redis->someOperation();
    return $result;
} catch (RedisException $e) {
    $this->logger->error('Operation failed', [
        'error' => $e->getMessage(),
        'key' => $key,
    ]);
    return false; // または適切なデフォルト値
}
```

**原則**:
- **例外をスロー しない** - 上位レイヤー（SessionHandler）が`false`で処理
- **必ずログに記録** - デバッグ用の情報を残す
- **接続エラーのみ例外** - `connect()`は失敗時に`ConnectionException`

## 設定オプション

### Redis::OPT_READ_TIMEOUT

```php
$this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config->getReadTimeout());
```

読み込みタイムアウト（秒）。デフォルト: 2.5秒。

### Redis::OPT_PREFIX

```php
$this->redis->setOption(Redis::OPT_PREFIX, $this->config->getPrefix());
```

**キープレフィックスの自動付与**:
```php
// 設定: prefix = "session:"
$this->redis->get('abc123');
// 実際のRedisコマンド: GET session:abc123
```

**利点**:
- アプリケーション間でキー名前空間を分離
- 同一Redisインスタンスを複数アプリで共有可能
- 手動でのプレフィックス付与が不要

## パフォーマンス最適化

### 1. 永続接続の活用

```php
// 設定
$config = new RedisConnectionConfig(
    persistent: true // 永続接続を有効化
);
```

**効果**:
- 接続確立のオーバーヘッド削減
- FastCGI/FPM環境で特に有効
- 1プロセスあたり1接続を維持

### 2. 接続の遅延確立

```php
public function get(string $key)
{
    $this->connect(); // ここで初めて接続
    // ...
}
```

**利点**:
- インスタンス生成時には接続しない
- 実際に必要になるまで接続を遅延
- 使用されないハンドラの接続コスト削減

### 3. SCANの使用

```php
// KEYS を使わない（ブロッキング）
// $keys = $this->redis->keys($pattern);

// SCAN を使う（非ブロッキング）
while (false !== ($scanKeys = $this->redis->scan($iterator, $pattern, 100))) {
    // 処理
}
```

## セキュリティ考慮事項

### 1. 認証情報の保護

```php
// ログに認証情報を出力しない
$this->logger->warning('Redis connection attempt failed', [
    'host' => $this->config->getHost(),
    'port' => $this->config->getPort(),
    // パスワードは含めない
]);
```

### 2. 入力検証

```php
if ($ttl <= 0) {
    throw new InvalidArgumentException('TTL must be positive');
}
```

不正な値でRedis操作が失敗するのを事前に防ぐ。

### 3. プレフィックスによる名前空間分離

```php
$this->redis->setOption(Redis::OPT_PREFIX, 'myapp:session:');
```

他のアプリケーションや用途のキーと衝突を防ぐ。

## テスト

### ユニットテスト

モックRedisを使用したテスト：

```php
public function testConnectSuccess(): void
{
    $redis = $this->createMock(Redis::class);
    $redis->expects($this->once())
        ->method('connect')
        ->willReturn(true);

    $connection = new RedisConnection($redis, $config, $logger);
    $result = $connection->connect();

    $this->assertTrue($result);
}
```

### 統合テスト

実際のRedisを使用したテスト：

```php
public function testSetAndGet(): void
{
    $connection = new RedisConnection(
        new Redis(),
        $config,
        new NullLogger()
    );

    $connection->set('test_key', 'test_value', 60);
    $value = $connection->get('test_key');

    $this->assertEquals('test_value', $value);
}
```

### リトライのテスト

```php
public function testConnectRetriesOnFailure(): void
{
    $redis = $this->createMock(Redis::class);
    $redis->expects($this->exactly(3))
        ->method('connect')
        ->will($this->onConsecutiveCalls(false, false, true));

    $connection = new RedisConnection($redis, $config, $logger);
    $result = $connection->connect();

    $this->assertTrue($result);
}
```

## トラブルシューティング

### 接続失敗

**症状**: `ConnectionException: Failed to connect to Redis after N attempts`

**確認ポイント**:
1. Redisサーバーが起動しているか
2. ホスト/ポートが正しいか
3. ファイアウォールでブロックされていないか
4. 認証情報が正しいか

### タイムアウト

**症状**: Redis操作が遅い、またはタイムアウト

**対策**:
```php
$config = new RedisConnectionConfig(
    timeout: 5.0,        // 接続タイムアウトを延長
    readTimeout: 5.0,    // 読み込みタイムアウトを延長
);
```

### 永続接続の問題

**症状**: 接続が増え続ける、メモリリーク

**対策**:
```php
$config = new RedisConnectionConfig(
    persistent: false // 永続接続を無効化
);
```

または、FPM/FastCGIのプロセス数を調整。

## まとめ

`RedisConnection`の主な特徴：

1. **堅牢なリトライ戦略**: Exponential backoffで一時的な障害に対応
2. **自動接続管理**: 遅延接続、永続接続サポート
3. **安全なエラーハンドリング**: 例外をキャッチし、適切なデフォルト値を返す
4. **パフォーマンス最適化**: SCAN使用、永続接続サポート
5. **柔軟な設定**: タイムアウト、リトライ回数、プレフィックス等

## 関連ドキュメント

- [session-handler.md](session-handler.md) - RedisSessionHandler実装詳細
- [../architecture.md](../architecture.md) - システムアーキテクチャ
- [../../users/redis-integration.md](../../users/redis-integration.md) - Redis/ValKey統合仕様
