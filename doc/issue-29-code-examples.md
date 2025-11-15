# Issue #29: コード例とクイックリファレンス

このドキュメントでは、CompositeRedisConnection設計の最小限のコード例を示します。

詳細な設計思想や比較分析は `issue-29-redis-wrapper-design.md` を参照してください。

---

## 1. 核心となるインターフェース

### RedisConnectionInterface (新規)

```php
namespace Uzulla\EnhancedRedisSessionHandler;

/**
 * Redis操作の共通インターフェース
 * 単一Redis (RedisConnection) と複数Redis (Composite) の両方が実装
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
```

### 既存クラスの変更

```php
// RedisConnection.php
// Before:
class RedisConnection implements LoggerAwareInterface
{...}

// After:
class RedisConnection implements RedisConnectionInterface, LoggerAwareInterface
{...}
```

---

## 2. クラス継承関係の明確化

### 重要: RedisConnectionを継承しない！

```
RedisConnectionInterface ← 共通インターフェース
  │
  ├─ RedisConnection          (既存、単一Redis管理)
  │   └─ $redis: Redis       ← PHP Redis extension instance
  │
  └─ CompositeRedisConnection (新規、複数Redis管理)
      └─ $connections: array<RedisConnectionInterface>
           │
           ├─ FailoverRedisConnection   ← extends CompositeRedisConnection
           └─ MultiWriteRedisConnection ← extends CompositeRedisConnection
```

**なぜRedisConnectionを継承しないのか:**

```php
// ✗ こうはしない（間違い）
class FailoverRedisConnection extends RedisConnection
{
    // RedisConnectionは $redis (単一) を持つ
    // 継承すると単一Redisの制約を引き継ぐ
}

// ✓ こうする（正しい）
class FailoverRedisConnection extends CompositeRedisConnection
{
    // CompositeRedisConnectionは $connections (複数) を持つ
    // 複数のRedisを管理できる
}
```

---

## 3. Composite基底クラス (概念)

```php
namespace Uzulla\EnhancedRedisSessionHandler\Composite;

/**
 * 複数RedisConnectionを管理する基底クラス
 * RedisConnectionとは兄弟関係（両方ともinterfaceを実装）
 */
abstract class CompositeRedisConnection implements RedisConnectionInterface
{
    /** @var array<RedisConnectionInterface> */
    protected array $connections;

    public function __construct(array $connections)
    {
        $this->connections = $connections;
    }

    // connect/disconnect/isConnectedは共通実装
    // get/set/delete等は具象クラスで実装
}
```

**ポイント:**
- RedisConnectionを継承しない（兄弟関係）
- 複数の`RedisConnectionInterface`を保持
- 接続管理などの共通処理を実装
- 具体的な操作（get/set等）は具象クラスに委譲

---

## 4. Failover実装 (概念)

```php
namespace Uzulla\EnhancedRedisSessionHandler\Composite;

/**
 * フォールバック機能を提供
 * Primary失敗時に順次Fallbackを試行
 */
class FailoverRedisConnection extends CompositeRedisConnection
{
    public function get(string $key)
    {
        foreach ($this->connections as $connection) {
            $result = $connection->get($key);
            if ($result !== false) {
                return $result;  // 最初に成功した結果を返す
            }
        }
        return false;
    }

    // set, delete等も同様のロジック
}
```

**動作フロー:**
```
get(key):
  Redis A → 失敗 → Redis B → 成功 → 返却
```

---

## 5. MultiWrite実装 (概念)

```php
namespace Uzulla\EnhancedRedisSessionHandler\Composite;

/**
 * 複数Redisへの同時書き込み
 * 読み取りはPrimaryのみ
 */
class MultiWriteRedisConnection extends CompositeRedisConnection
{
    private bool $requireAllWrites;

    public function get(string $key)
    {
        // Primaryからのみ読み取り
        return $this->connections[0]->get($key);
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        $results = [];
        foreach ($this->connections as $connection) {
            $results[] = $connection->set($key, $value, $ttl);
        }

        // requireAllWrites設定に応じて判定
        if ($this->requireAllWrites) {
            return !in_array(false, $results, true);  // 全て成功
        } else {
            return in_array(true, $results, true);   // 1つでも成功
        }
    }
}
```

**動作フロー:**
```
get(key):
  Redis A のみ → 返却

set(key, val):
  Redis A → 書き込み
  Redis B → 書き込み
  Redis C → 書き込み
  → 全て成功 or 一部成功で判定
```

---

## 6. 使用例

### 6-1. 基本的なFailover構成

```php
use Uzulla\EnhancedRedisSessionHandler\Composite\FailoverRedisConnection;

// Primary Redis (RedisConnection = 単一Redis管理)
$primary = new RedisConnection($redis1, $config1, $logger);

// Fallback Redis (RedisConnection = 単一Redis管理)
$fallback = new RedisConnection($redis2, $config2, $logger);

// Failover Composite作成 (CompositeRedisConnection = 複数Redis管理)
// $primary と $fallback を「配列」で渡す ← ここが重要！
$failover = new FailoverRedisConnection([$primary, $fallback], $logger);

// オブジェクト構造:
// $failover (FailoverRedisConnection)
//   └─ $connections = [
//        $primary (RedisConnection),   ← 単一Redisを管理
//        $fallback (RedisConnection)   ← 単一Redisを管理
//      ]

// セッションハンドラに渡す
$handler = new RedisSessionHandler($failover, $serializer, $options);

// Hookにも同じCompositeを渡す
$handler->addReadHook(new ReadTimestampHook($failover, $logger));

// これで、セッションデータもタイムスタンプも同じフォールバック戦略を使用！
```

### 6-2. MultiWrite構成

```php
use Uzulla\EnhancedRedisSessionHandler\Composite\MultiWriteRedisConnection;

// 3台のRedis
$conn1 = new RedisConnection($redis1, $config1, $logger);
$conn2 = new RedisConnection($redis2, $config2, $logger);
$conn3 = new RedisConnection($redis3, $config3, $logger);

// MultiWrite Composite作成
$multiWrite = new MultiWriteRedisConnection(
    [$conn1, $conn2, $conn3],
    requireAllWrites: false,  // 1台でも成功すればOK
    logger: $logger
);

// セッションハンドラに渡す
$handler = new RedisSessionHandler($multiWrite, $serializer, $options);

// Hookにも同じCompositeを渡す
$handler->addWriteHook(new DoubleWriteHook($secondaryConn, 1440, false, $logger));

// これで、全てのデータが3台のRedisに書き込まれる！
```

### 6-3. Compositeのネスト (高度な構成)

```php
// DC1: Primary + Fallback
$dc1Primary = new RedisConnection($redis1a, $config1a, $logger);
$dc1Fallback = new RedisConnection($redis1b, $config1b, $logger);
$dc1 = new FailoverRedisConnection([$dc1Primary, $dc1Fallback], $logger);

// DC2: Primary + Fallback
$dc2Primary = new RedisConnection($redis2a, $config2a, $logger);
$dc2Fallback = new RedisConnection($redis2b, $config2b, $logger);
$dc2 = new FailoverRedisConnection([$dc2Primary, $dc2Fallback], $logger);

// Multi-DC構成
$multiDC = new MultiWriteRedisConnection(
    [$dc1, $dc2],
    requireAllWrites: false,
    logger: $logger
);

// これで:
// - 両DCに書き込み
// - 各DC内ではフォールバック
// - 読み取りはDC1優先
```

---

## 7. 移行パターン

### パターン1: 既存コードをそのまま使う

```php
// 変更前
$primary = new RedisConnection($redis1, $config1, $logger);
$handler = new RedisSessionHandler($primary, $serializer);

// 変更後 (Compositeを使わない場合)
$primary = new RedisConnection($redis1, $config1, $logger);
$handler = new RedisSessionHandler($primary, $serializer);
// ↑ 既存コードはそのまま動作（RedisConnectionもRedisConnectionInterfaceを実装）
```

### パターン2: Failoverを追加

```php
// 変更前
$primary = new RedisConnection($redis1, $config1, $logger);
$handler = new RedisSessionHandler($primary, $serializer);
$handler->addReadHook(new FallbackReadHook([$fallback], $logger));  // Hookでフォールバック
$handler->addReadHook(new ReadTimestampHook($primary, $logger));    // ← primaryにしか書けない

// 変更後
$failover = new FailoverRedisConnection([$primary, $fallback], $logger);
$handler = new RedisSessionHandler($failover, $serializer);
// FallbackReadHook不要（Composite層で対応）
$handler->addReadHook(new ReadTimestampHook($failover, $logger));  // ← フォールバック対応！
```

---

## 8. テストの書き方

### ユニットテスト (モック使用)

```php
class FailoverRedisConnectionTest extends TestCase
{
    public function testFailoverOnPrimaryFailure(): void
    {
        $primary = $this->createMock(RedisConnectionInterface::class);
        $primary->method('get')->willReturn(false);  // Primary失敗

        $fallback = $this->createMock(RedisConnectionInterface::class);
        $fallback->method('get')->willReturn('data');  // Fallback成功

        $composite = new FailoverRedisConnection([$primary, $fallback]);

        $this->assertSame('data', $composite->get('key'));
    }
}
```

### 統合テスト (実Redis使用)

```php
class FailoverRedisConnectionIntegrationTest extends TestCase
{
    public function testRealRedisFailover(): void
    {
        $redis1 = new Redis();
        $redis1->connect('localhost', 6379);

        $redis2 = new Redis();
        $redis2->connect('localhost', 6380);

        $conn1 = new RedisConnection($redis1, $config1, new NullLogger());
        $conn2 = new RedisConnection($redis2, $config2, new NullLogger());

        $failover = new FailoverRedisConnection([$conn1, $conn2]);

        // データ書き込み
        $this->assertTrue($failover->set('test', 'value', 100));

        // 両方から読める
        $this->assertSame('value', $failover->get('test'));
    }
}
```

---

## 9. よくある質問

### Q1: 既存のFallbackReadHookはどうなる？

**A:** 後方互換性のため残します。ただし、Composite使用時は不要になります。

```
推奨: Composite層でフォールバック管理
代替: 既存のFallbackReadHookも引き続き使用可能
```

### Q2: パフォーマンスへの影響は？

**A:**
- **Failover**: Primary正常時はオーバーヘッドほぼなし
- **MultiWrite**: 台数分のレイテンシ増加（直列実行のため）

```
単一Redis:     1ms
2台MultiWrite: 2ms (2倍)
3台MultiWrite: 3ms (3倍)
```

### Q3: 循環依存は発生しない？

**A:** 発生しません。Composite自体はHookを意識せず、単にRedis操作を委譲するだけです。

```
✗ HookAware案: Hook → Composite → Hook → ... (循環)
✓ Composite案: Hook → Composite → Redis (循環なし)
```

### Q4: カスタムCompositeは作れる？

**A:** はい、CompositeRedisConnectionを継承してカスタム実装が可能です。

```php
class CustomRedisConnection extends CompositeRedisConnection
{
    // 独自のロジックを実装
    public function get(string $key) { ... }
    public function set(string $key, string $value, int $ttl): bool { ... }
}
```

---

## 10. まとめ

### 最小限の変更で実現

```
必要な変更:
  1. RedisConnectionInterface の追加
  2. RedisConnection implements RedisConnectionInterface
  3. CompositeRedisConnection 実装
  4. 具象Composite (Failover, MultiWrite) 実装

既存コードへの影響:
  - Hookのコード変更: 不要
  - RedisSessionHandlerの変更: 不要
  - 既存のRedisConnection使用箇所: そのまま動作
```

### 期待される効果

```
Before:
  セッションデータ    → フォールバック ✓
  タイムスタンプ      → フォールバック ✗
  カスタムHookデータ  → フォールバック ✗

After:
  セッションデータ    → フォールバック ✓
  タイムスタンプ      → フォールバック ✓
  カスタムHookデータ  → フォールバック ✓
```

### 次のステップ

1. `issue-29-redis-wrapper-design.md` で設計思想を理解
2. このドキュメントでコード構造を把握
3. PoC実装で動作確認
4. 段階的に本実装へ

---

**関連ドキュメント:**
- `doc/issue-29-redis-wrapper-design.md` - 詳細設計と比較分析
- `doc/architecture.md` - プロジェクト全体のアーキテクチャ
- `doc/specification.md` - 機能仕様書
