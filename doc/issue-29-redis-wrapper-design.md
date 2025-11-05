# Issue #29: RedisConnection Wrapper設計案

## 問題の概要

### 現状の問題

現在のアーキテクチャでは、Hook内部でRedis操作を行う場合、その操作は他のHookの影響を受けません。

**具体例:**

```php
// ReadTimestampHook.php (71行目)
$this->connection->set($timestampKey, $timestamp, $this->timestampTtl);

// DoubleWriteHook.php (80行目)
$secondarySuccess = $this->secondaryConnection->set($sessionId, $serializedData, $this->ttl);
```

これらのHook内部のRedis操作は、`FallbackReadHook`などの他のHookのロジックを経由しません。

### 問題のシナリオ

**シナリオ1: FallbackReadHookとReadTimestampHookの組み合わせ**

```
構成:
- プライマリRedis: 192.168.1.1
- フォールバックRedis: 192.168.1.2
- 使用Hook: FallbackReadHook, ReadTimestampHook

動作:
1. プライマリRedisがダウン
2. セッション読み込み → FallbackReadHookがフォールバックRedisから正常に読み込み ✓
3. タイムスタンプ記録 → ReadTimestampHookは常にプライマリに書き込み → 失敗 ✗

期待動作:
- タイムスタンプもフォールバックRedisに書き込まれるべき
```

**シナリオ2: 複数Redisへのダブルライト**

```
構成:
- プライマリRedis: A
- セカンダリRedis: B, C (ダブルライト用)
- 使用Hook: DoubleWriteHook (B向け)

問題:
- セッションデータはA + Bに書き込まれる
- しかし、ReadTimestampHookのタイムスタンプはAにのみ書き込まれる
- Bにフェイルオーバーした場合、タイムスタンプ情報が欠落
```

### 根本原因

- **RedisSessionHandler** は単一の`RedisConnection`を持ち、Hookパイプラインを通して操作する
- **Hook内部** で直接`RedisConnection`を使用する場合、Hookパイプラインを経由しない
- 結果として、Hook内のRedis操作は他のHook（フォールバック、ダブルライトなど）の恩恵を受けられない

## 設計案

### オプション1: HookAwareRedisConnection

**概要:**
`RedisConnection`をラップして、内部の操作も同じHookパイプラインを通すようにする。

**設計:**

```php
class HookAwareRedisConnection extends RedisConnection
{
    private RedisConnection $innerConnection;
    /** @var array<ReadHookInterface> */
    private array $readHooks;
    /** @var array<WriteHookInterface> */
    private array $writeHooks;

    public function get(string $key)
    {
        // beforeRead hookを実行
        $result = $this->innerConnection->get($key);
        // afterRead hookを実行
        return $result;
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        // beforeWrite hookを実行
        $result = $this->innerConnection->set($key, $value, $ttl);
        // afterWrite hookを実行
        return $result;
    }
}
```

**メリット:**
- Hook内部の操作も自動的にHookパイプラインを通る
- 既存のHookコードを変更不要

**デメリット:**
- 循環依存のリスク（HookがHookを呼ぶ）
- デバッグが困難（Hookの呼び出しが深くネストする）
- 無限ループの可能性

**評価:** ❌ 推奨しない（循環依存リスクが高い）

---

### オプション2: RedisConnectionPool

**概要:**
複数のRedisConnectionを管理するプールを導入し、自動的に適切なRedisへルーティングする。

**設計:**

```php
class RedisConnectionPool
{
    private RedisConnection $primary;
    /** @var array<RedisConnection> */
    private array $secondaries;
    private PoolPolicy $policy; // FAILOVER, DOUBLE_WRITE, etc.

    public function get(string $key)
    {
        if ($this->policy === PoolPolicy::FAILOVER) {
            try {
                return $this->primary->get($key);
            } catch (Throwable $e) {
                return $this->trySecondaries('get', [$key]);
            }
        }
        // ... other policies
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        if ($this->policy === PoolPolicy::DOUBLE_WRITE) {
            $results = [];
            $results[] = $this->primary->set($key, $value, $ttl);
            foreach ($this->secondaries as $secondary) {
                $results[] = $secondary->set($key, $value, $ttl);
            }
            return !in_array(false, $results, true);
        }
        // ... other policies
    }
}
```

**メリット:**
- 複数Redisの管理を一元化
- ポリシーベースで動作を切り替え可能

**デメリット:**
- 既存のHookロジック（FallbackReadHook, DoubleWriteHookなど）と重複
- Hookの存在意義が薄れる
- アーキテクチャの大幅な変更が必要

**評価:** △ 可能だが、既存設計と相反する

---

### オプション3: CompositeRedisConnection (推奨)

**概要:**
複数のRedisConnectionを組み合わせて、単一のRedisConnectionインターフェースとして扱う。
Decorator/Compositeパターンを使用。

**設計:**

```php
/**
 * Interface for Redis connection operations.
 * Both RedisConnection and CompositeRedisConnection implement this.
 */
interface RedisConnectionInterface
{
    public function connect(): bool;
    public function disconnect(): void;
    public function get(string $key);
    public function set(string $key, string $value, int $ttl): bool;
    public function delete(string $key): bool;
    public function exists(string $key): bool;
    public function expire(string $key, int $ttl): bool;
    public function keys(string $pattern): array;
    public function isConnected(): bool;
}

/**
 * Existing RedisConnection implements the interface.
 */
class RedisConnection implements RedisConnectionInterface, LoggerAwareInterface
{
    // ... existing implementation
}

/**
 * Composite that delegates to multiple Redis connections.
 */
abstract class CompositeRedisConnection implements RedisConnectionInterface
{
    /** @var array<RedisConnectionInterface> */
    protected array $connections;

    /**
     * @param array<RedisConnectionInterface> $connections
     */
    public function __construct(array $connections)
    {
        if (count($connections) === 0) {
            throw new InvalidArgumentException('At least one connection is required');
        }
        $this->connections = $connections;
    }

    // 各操作は具象クラスで実装
    abstract public function get(string $key);
    abstract public function set(string $key, string $value, int $ttl): bool;
    // ... other operations
}

/**
 * Failover: Primary優先、失敗時に順次フォールバック.
 */
class FailoverRedisConnection extends CompositeRedisConnection
{
    public function get(string $key)
    {
        foreach ($this->connections as $connection) {
            try {
                $result = $connection->get($key);
                if ($result !== false) {
                    return $result;
                }
            } catch (Throwable $e) {
                // Try next connection
                continue;
            }
        }
        return false;
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        foreach ($this->connections as $connection) {
            try {
                return $connection->set($key, $value, $ttl);
            } catch (Throwable $e) {
                // Try next connection
                continue;
            }
        }
        return false;
    }

    // ... other operations with failover logic
}

/**
 * MultiWrite: 全てのRedisに書き込み、読み込みはPrimaryから.
 */
class MultiWriteRedisConnection extends CompositeRedisConnection
{
    public function get(string $key)
    {
        // Read from primary (first connection)
        return $this->connections[0]->get($key);
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        $results = [];
        foreach ($this->connections as $connection) {
            try {
                $results[] = $connection->set($key, $value, $ttl);
            } catch (Throwable $e) {
                $results[] = false;
            }
        }
        // All writes must succeed
        return !in_array(false, $results, true);
    }

    // ... other operations with multi-write logic
}
```

**使用例:**

```php
// 従来のシングルRedis構成
$primary = new RedisConnection($redis1, $config1, $logger);

// フォールバック構成
$failover = new FailoverRedisConnection([
    new RedisConnection($redis1, $config1, $logger),  // Primary
    new RedisConnection($redis2, $config2, $logger),  // Fallback
]);

// ダブルライト構成
$multiWrite = new MultiWriteRedisConnection([
    new RedisConnection($redis1, $config1, $logger),  // Primary
    new RedisConnection($redis2, $config2, $logger),  // Secondary
]);

// Hookに渡す場合
$hook = new ReadTimestampHook($failover, $logger);
// → タイムスタンプもフォールバック可能に！

// セッションハンドラに渡す場合
$handler = new RedisSessionHandler($failover, $serializer, $options);
// → セッション本体もタイムスタンプも同じフォールバック戦略を使用
```

**メリット:**

1. **既存コードとの互換性**: `RedisConnectionInterface`を実装するため、既存のHookやハンドラをそのまま使用可能
2. **Hookの変更不要**: `ReadTimestampHook`などのコードを一切変更せずに、複数Redis対応が可能
3. **柔軟な組み合わせ**: Failover、MultiWrite、カスタム戦略など、用途に応じて選択可能
4. **循環依存なし**: Composite自体はHookを意識しないため、安全
5. **テスタビリティ**: 各Compositeを独立してテスト可能
6. **段階的導入**: 既存コードはそのまま（単一RedisConnection使用）、必要な箇所だけCompositeを使用

**デメリット:**

1. **新しいインターフェース導入**: `RedisConnectionInterface`の追加が必要
2. **既存コードの型変更**: `RedisConnection`型を使っている箇所を`RedisConnectionInterface`型に変更
3. **複雑性の増加**: Composite層が追加されることで、アーキテクチャが若干複雑化

**評価:** ✅ **推奨** （柔軟性と既存コードとの互換性のバランスが最良）

---

## 推奨設計の詳細

### アーキテクチャ図

```
┌─────────────────────────────────────────┐
│    RedisSessionHandler                  │
│    (既存コード - 変更最小)              │
└────────────────┬────────────────────────┘
                 │ RedisConnectionInterface
                 ↓
        ┌────────┴────────┐
        │                 │
┌───────┴──────┐  ┌──────┴─────────────┐
│ RedisConn    │  │ CompositeRedisConn │
│ (既存)       │  │ (新規・抽象)       │
└──────────────┘  └──────┬─────────────┘
                         │
         ┌───────────────┼───────────────┐
         │               │               │
  ┌──────┴──────┐ ┌─────┴──────┐ ┌─────┴────────┐
  │ Failover    │ │ MultiWrite │ │ Custom...    │
  │ RedisCon    │ │ RedisCon   │ │ RedisCon     │
  └─────────────┘ └────────────┘ └──────────────┘
```

### 既存Hookとの統合

**Before (現状):**

```php
// FallbackReadHookが必要
$handler = new RedisSessionHandler($primary, $serializer);
$handler->addReadHook(new FallbackReadHook([$fallback1, $fallback2], $logger));
$handler->addReadHook(new ReadTimestampHook($primary, $logger));  // ← primaryにしか書けない！
```

**After (提案設計):**

```php
// Composite層でフォールバック管理
$failover = new FailoverRedisConnection([$primary, $fallback1, $fallback2]);

$handler = new RedisSessionHandler($failover, $serializer);
// FallbackReadHook不要（Composite層で対応）
$handler->addReadHook(new ReadTimestampHook($failover, $logger));  // ← フォールバック対応！
```

または、**既存のHookをそのまま使う場合:**

```php
// Hook用に専用のCompositeを用意
$sessionFailover = new FailoverRedisConnection([$primary, $fallback1, $fallback2]);
$timestampFailover = new FailoverRedisConnection([$primary, $fallback1, $fallback2]);

$handler = new RedisSessionHandler($sessionFailover, $serializer);
$handler->addReadHook(new FallbackReadHook([$fallback1, $fallback2], $logger));  // 既存Hook
$handler->addReadHook(new ReadTimestampHook($timestampFailover, $logger));  // フォールバック対応！
```

### 段階的な移行パス

**Phase 1: インターフェース導入**
- `RedisConnectionInterface`を定義
- `RedisConnection`に`implements RedisConnectionInterface`を追加
- 既存コードの型ヒント変更（`RedisConnection` → `RedisConnectionInterface`）
- **テストを実行して既存機能の動作確認**

**Phase 2: Composite基底クラス実装**
- `CompositeRedisConnection`抽象クラスを実装
- 基本的なヘルパーメソッド（connect all, disconnect allなど）を実装
- **テストケース作成**

**Phase 3: 具象Composite実装**
- `FailoverRedisConnection`実装
- `MultiWriteRedisConnection`実装
- **統合テストで動作確認**

**Phase 4: ドキュメントとサンプル**
- 使用例のドキュメント作成
- `examples/`配下にサンプルコード追加
- CLAUDE.mdに設計パターンを追加

**Phase 5: 既存Hookの非推奨化（オプション）**
- `FallbackReadHook`: Composite層で代替可能
- `DoubleWriteHook`: Composite層で代替可能
- ただし、後方互換性のため残しておくことも検討

## 代替案の比較

| 観点 | Option 1: HookAware | Option 2: Pool | Option 3: Composite |
|------|---------------------|----------------|---------------------|
| 既存コードとの互換性 | △ | ✗ | ✅ |
| 循環依存リスク | ✗ | ○ | ✅ |
| 実装の複雑さ | △ | ✗ | ○ |
| テスタビリティ | △ | ○ | ✅ |
| 柔軟性 | △ | ○ | ✅ |
| 段階的導入の可否 | △ | ✗ | ✅ |
| **総合評価** | ❌ | △ | ✅ |

## 実装上の注意点

### 1. connect/disconnect操作

Compositeの場合、複数のRedisConnectionを管理するため：

```php
class CompositeRedisConnection implements RedisConnectionInterface
{
    public function connect(): bool
    {
        $results = [];
        foreach ($this->connections as $connection) {
            try {
                $results[] = $connection->connect();
            } catch (Throwable $e) {
                $results[] = false;
            }
        }
        // 少なくとも1つ成功すればOK
        return in_array(true, $results, true);
    }

    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            try {
                $connection->disconnect();
            } catch (Throwable $e) {
                // Log and continue
            }
        }
    }

    public function isConnected(): bool
    {
        // 少なくとも1つが接続中ならtrue
        foreach ($this->connections as $connection) {
            if ($connection->isConnected()) {
                return true;
            }
        }
        return false;
    }
}
```

### 2. keys()操作のマージ

複数Redisから`keys()`を呼ぶ場合、結果のマージが必要：

```php
class FailoverRedisConnection extends CompositeRedisConnection
{
    public function keys(string $pattern): array
    {
        // Primary優先、失敗時にフォールバック
        foreach ($this->connections as $connection) {
            try {
                return $connection->keys($pattern);
            } catch (Throwable $e) {
                continue;
            }
        }
        return [];
    }
}

class MultiWriteRedisConnection extends CompositeRedisConnection
{
    public function keys(string $pattern): array
    {
        // 全Redisから取得してマージ（重複削除）
        $allKeys = [];
        foreach ($this->connections as $connection) {
            try {
                $keys = $connection->keys($pattern);
                $allKeys = array_merge($allKeys, $keys);
            } catch (Throwable $e) {
                // Log and continue
            }
        }
        return array_unique($allKeys);
    }
}
```

### 3. LoggerAwareInterfaceの扱い

```php
class CompositeRedisConnection implements RedisConnectionInterface, LoggerAwareInterface
{
    private ?LoggerInterface $logger = null;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;

        // 全てのRedisConnectionにもloggerを設定
        foreach ($this->connections as $connection) {
            if ($connection instanceof LoggerAwareInterface) {
                $connection->setLogger($logger);
            }
        }
    }
}
```

### 4. エラーハンドリング戦略

```php
class FailoverRedisConnection extends CompositeRedisConnection
{
    private LoggerInterface $logger;

    public function set(string $key, string $value, int $ttl): bool
    {
        foreach ($this->connections as $index => $connection) {
            try {
                $result = $connection->set($key, $value, $ttl);

                if ($result && $index > 0) {
                    // フォールバック成功時にログ
                    $this->logger->warning('Used fallback Redis for SET operation', [
                        'key' => $key,
                        'fallback_index' => $index,
                    ]);
                }

                if ($result) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logger->error('Redis SET failed, trying next connection', [
                    'key' => $key,
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
                continue;
            }
        }

        // All failed
        $this->logger->critical('All Redis connections failed for SET operation', [
            'key' => $key,
        ]);

        return false;
    }
}
```

## セキュリティ考慮事項

### セッションIDのマスキング

Composite内でもセッションIDをログ出力する際は必ずマスキング：

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$this->logger->debug('Failover operation', [
    'session_id' => SessionIdMasker::mask($sessionId),
    'fallback_index' => $index,
]);
```

### 接続情報のログ出力

複数Redis構成の場合、どのRedisへ接続したかの情報は有用だが、パスワードなどは出力しない：

```php
$this->logger->info('Connected to Redis', [
    'host' => $config->getHost(),
    'port' => $config->getPort(),
    'database' => $config->getDatabase(),
    // password は出力しない
]);
```

## テスト戦略

### ユニットテスト

各Compositeクラスを独立してテスト：

```php
class FailoverRedisConnectionTest extends TestCase
{
    public function testFailoverOnPrimaryFailure(): void
    {
        $primary = $this->createMock(RedisConnectionInterface::class);
        $fallback = $this->createMock(RedisConnectionInterface::class);

        $primary->expects($this->once())
            ->method('get')
            ->willThrowException(new ConnectionException());

        $fallback->expects($this->once())
            ->method('get')
            ->willReturn('session_data');

        $composite = new FailoverRedisConnection([$primary, $fallback]);
        $result = $composite->get('session_id');

        $this->assertSame('session_data', $result);
    }
}
```

### 統合テスト

実際のRedis/ValKeyを使った統合テスト：

```php
class CompositeRedisConnectionIntegrationTest extends TestCase
{
    public function testMultiWriteToTwoRedisInstances(): void
    {
        $redis1 = new Redis();
        $redis1->connect('localhost', 6379);

        $redis2 = new Redis();
        $redis2->connect('localhost', 6380);

        $conn1 = new RedisConnection($redis1, $config1, $logger);
        $conn2 = new RedisConnection($redis2, $config2, $logger);

        $multiWrite = new MultiWriteRedisConnection([$conn1, $conn2]);

        $result = $multiWrite->set('test_key', 'test_value', 100);
        $this->assertTrue($result);

        // 両方のRedisに書き込まれていることを確認
        $this->assertSame('test_value', $redis1->get('test_key'));
        $this->assertSame('test_value', $redis2->get('test_key'));
    }
}
```

## パフォーマンス考慮事項

### MultiWriteのオーバーヘッド

複数Redisへの書き込みは直列実行されるため、レイテンシが増加：

```
単一Redis: 1ms
2台へのMultiWrite: 2ms (2倍)
3台へのMultiWrite: 3ms (3倍)
```

**対策案（将来的な拡張）:**
- 並列書き込みの実装（非同期・マルチプロセス）
- Write-behindキャッシュパターン

### Failoverのレイテンシ

Primary失敗時のフォールバックには遅延が発生：

```
Primary成功: 1ms
Primary失敗 → Fallback成功: タイムアウト + リトライ + 1ms = 数秒
```

**対策案:**
- ヘルスチェック機能の追加
- Circuit Breakerパターンの導入

## まとめ

### 推奨事項

**Option 3: CompositeRedisConnection** を推奨します。

**理由:**
1. 既存のHookコードを変更せずに、複数Redis対応が可能
2. 段階的に導入できる（既存コードとの互換性維持）
3. テストが容易で、保守性が高い
4. 循環依存のリスクがない
5. 柔軟な拡張が可能（カスタムComposite実装）

### 実装の優先度

Issue #29は「低優先度」とマークされているため、以下の順序で検討を推奨：

1. **設計レビュー**: この設計案をレビューし、フィードバックを収集
2. **PoC実装**: `RedisConnectionInterface`と1つのComposite実装でPoC
3. **ドキュメント整備**: 設計パターンと使用例のドキュメント作成
4. **段階的実装**: Phase 1〜4を順次実装
5. **実運用でのフィードバック**: 実際の使用事例からのフィードバック収集

### 次のステップ

1. このドキュメントをチーム/コミュニティでレビュー
2. 設計の承認
3. Issue #29に設計案をコメント
4. 実装の開始（低優先度のため、他の重要なタスク後）

## 参考資料

- [Composite Pattern (GoF Design Patterns)](https://en.wikipedia.org/wiki/Composite_pattern)
- [Decorator Pattern (GoF Design Patterns)](https://en.wikipedia.org/wiki/Decorator_pattern)
- [Redis Sentinel (High Availability)](https://redis.io/docs/management/sentinel/)
- [Redis Cluster Specification](https://redis.io/docs/reference/cluster-spec/)
