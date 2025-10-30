# Redis/ValKey統合仕様

## 1. 概要

本ドキュメントでは、enhanced-redis-session-handler.phpにおけるRedis/ValKeyとの統合方法、ext-redisの使用方法、キー命名規則、TTL管理、接続管理について詳細に説明します。

## 2. ext-redis拡張の使用

### 2.1 ext-redisについて

ext-redisは、PHPからRedisサーバーにアクセスするための公式拡張機能です。C言語で実装されており、高速な動作が特徴です。

**主な特徴:**
- 高速なパフォーマンス
- 豊富なRedisコマンドのサポート
- 永続的接続のサポート
- パイプライン処理のサポート
- Redis Clusterのサポート

### 2.2 必要なバージョン

- **PHP**: 7.4以上
- **ext-redis**: 5.0以上
- **Redis**: 5.0以上（公式サポート）
- **ValKey**: 7.2.5以上（テストはValKey 9.0.0で実施）

**互換性に関する備考**: 本ライブラリはGET/SETEX/DEL/EXPIRE/EXISTS/SCANなどの基本コマンドのみを使用します。これらのコマンドはRedis 2.8.0以降で利用可能ですが、公式サポートはRedis 5.0以上としています。ValKeyはRedis 7.2.4からフォークされ（初版7.2.5）、現在は独自のバージョニング（9.0.0など）を採用しています。

### 2.3 ext-redisのインストール

#### Ubuntuの場合:
```bash
sudo apt-get install php-redis
```

#### macOSの場合:
```bash
pecl install redis
```

#### ソースからのインストール:
```bash
git clone https://github.com/phpredis/phpredis.git
cd phpredis
phpize
./configure
make && make install
```

### 2.4 インストール確認

```php
<?php
if (extension_loaded('redis')) {
    echo "ext-redis is installed\n";
    echo "Version: " . phpversion('redis') . "\n";
} else {
    echo "ext-redis is NOT installed\n";
}
```

## 3. RedisConnection実装

### 3.1 基本的な接続

```php
class RedisConnection
{
    private Redis $redis;
    private array $config;
    private bool $connected = false;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 6379,
            'timeout' => 2.5,
            'password' => null,
            'database' => 0,
            'prefix' => 'session:',
            'persistent' => false,
            'retry_interval' => 100,
            'read_timeout' => 2.5,
        ], $config);

        $this->redis = new Redis();
    }

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        try {
            $connectMethod = $this->config['persistent'] ? 'pconnect' : 'connect';
            
            $result = $this->redis->$connectMethod(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout'],
                null,
                $this->config['retry_interval']
            );

            if (!$result) {
                throw new RuntimeException('Failed to connect to Redis');
            }

            if ($this->config['password'] !== null) {
                if (!$this->redis->auth($this->config['password'])) {
                    throw new RuntimeException('Redis authentication failed');
                }
            }

            if ($this->config['database'] !== 0) {
                if (!$this->redis->select($this->config['database'])) {
                    throw new RuntimeException('Failed to select Redis database');
                }
            }

            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config['read_timeout']);
            $this->redis->setOption(Redis::OPT_PREFIX, $this->config['prefix']);

            $this->connected = true;
            return true;

        } catch (Exception $e) {
            error_log(sprintf(
                '[CRITICAL] Redis connection failed: %s (host: %s, port: %d)',
                $e->getMessage(),
                $this->config['host'],
                $this->config['port']
            ));
            throw $e;
        }
    }

    public function disconnect(): void
    {
        if ($this->connected && !$this->config['persistent']) {
            $this->redis->close();
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->redis->ping() === '+PONG';
    }
}
```

### 3.2 接続オプション

| オプション | 説明 | デフォルト値 |
|-----------|------|-------------|
| Redis::OPT_PREFIX | キープレフィックスの自動付与 | 'session:' |
| Redis::OPT_READ_TIMEOUT | 読み取りタイムアウト（秒） | 2.5 |
| Redis::OPT_SERIALIZER | シリアライザの選択 | Redis::SERIALIZER_NONE |
| Redis::OPT_SCAN | SCANコマンドの動作モード | Redis::SCAN_RETRY |

### 3.3 永続的接続

永続的接続を使用すると、リクエスト間でRedis接続が再利用されます。

**メリット:**
- 接続のオーバーヘッドを削減
- パフォーマンスの向上

**デメリット:**
- 接続数の管理が必要
- メモリ使用量の増加

**使用例:**
```php
$connection = new RedisConnection([
    'host' => 'localhost',
    'port' => 6379,
    'persistent' => true,
]);
```

## 4. キー命名規則

### 4.1 基本的なキー構造

```
{prefix}{session_id}
```

**例:**
```
session:abc123def456
myapp:session:xyz789
```

### 4.2 プレフィックスの目的

1. **名前空間の分離**: 複数のアプリケーションで同一のRedisインスタンスを共有
2. **キー管理の効率化**: パターンマッチングによる一括操作
3. **衝突の回避**: 他のデータとの衝突を防ぐ

### 4.3 プレフィックスの設定

```php
$connection = new RedisConnection([
    'prefix' => 'myapp:session:',
]);
```

**注意:**
- プレフィックスは`Redis::OPT_PREFIX`で自動的に付与される
- アプリケーションコードでは`session_id`のみを指定

### 4.4 キーの例

| アプリケーション | プレフィックス | セッションID | 完全なキー |
|----------------|--------------|-------------|-----------|
| アプリA | `appa:session:` | `abc123` | `appa:session:abc123` |
| アプリB | `appb:session:` | `xyz789` | `appb:session:xyz789` |
| 開発環境 | `dev:session:` | `test001` | `dev:session:test001` |
| 本番環境 | `prod:session:` | `live001` | `prod:session:live001` |

### 4.5 キーの検索

```php
public function keys(string $pattern): array
{
    $this->connect();
    
    $fullPattern = $this->config['prefix'] . $pattern;
    
    $keys = [];
    $iterator = null;
    
    while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
        foreach ($scanKeys as $key) {
            $keys[] = str_replace($this->config['prefix'], '', $key);
        }
    }
    
    return $keys;
}
```

**使用例:**
```php
$allSessions = $connection->keys('*');
$userSessions = $connection->keys('user:123:*');
```

## 5. TTL（Time To Live）管理

### 5.1 TTLの概念

TTLは、Redisキーの有効期限を秒単位で指定する機能です。TTLが経過すると、キーは自動的に削除されます。

### 5.2 TTLの設定方法

#### 5.2.1 SETEXコマンドの使用

```php
public function set(string $key, string $value, int $ttl): bool
{
    $this->connect();
    
    try {
        $result = $this->redis->setex($key, $ttl, $value);
        return $result !== false;
    } catch (Exception $e) {
        error_log(sprintf(
            '[ERROR] Redis SET operation failed: %s (key: %s)',
            $e->getMessage(),
            $key
        ));
        return false;
    }
}
```

**Redisコマンド:**
```
SETEX session:abc123 1440 "serialized_data"
```

#### 5.2.2 EXPIREコマンドの使用

```php
public function expire(string $key, int $ttl): bool
{
    $this->connect();
    
    try {
        return $this->redis->expire($key, $ttl) === 1;
    } catch (Exception $e) {
        error_log(sprintf(
            '[ERROR] Redis EXPIRE operation failed: %s (key: %s)',
            $e->getMessage(),
            $key
        ));
        return false;
    }
}
```

**Redisコマンド:**
```
EXPIRE session:abc123 1440
```

### 5.3 TTLの計算

```php
private function getTTL(): int
{
    $maxLifetime = $this->options['max_lifetime'] 
        ?? (int)ini_get('session.gc_maxlifetime');
    
    return max(60, $maxLifetime);
}
```

**デフォルト値:**
- `session.gc_maxlifetime`: 1440秒（24分）
- 最小値: 60秒

### 5.4 TTLの確認

```php
public function ttl(string $key): int
{
    $this->connect();
    
    return $this->redis->ttl($key);
}
```

**戻り値:**
- 正の整数: 残りのTTL（秒）
- -1: キーは存在するがTTLが設定されていない
- -2: キーが存在しない

### 5.5 TTL更新戦略

#### 5.5.1 アクセス時にTTLを更新

```php
public function updateTimestamp(string $id, string $data): bool
{
    $key = $id;
    $ttl = $this->getTTL();
    
    return $this->connection->expire($key, $ttl);
}
```

**メリット:**
- データの再書き込みが不要
- パフォーマンスが良い

**デメリット:**
- データの更新は行われない

#### 5.5.2 書き込み時にTTLを更新

```php
public function write(string $id, string $data): bool
{
    $key = $id;
    $ttl = $this->getTTL();
    
    return $this->connection->set($key, $processedData, $ttl);
}
```

**メリット:**
- データとTTLが同時に更新される
- 一貫性が保たれる

**デメリット:**
- データの再書き込みが発生

### 5.6 ガベージコレクション

RedisのTTL機能により、期限切れのキーは自動的に削除されます。

**Redisの削除戦略:**
1. **受動的削除**: キーにアクセスした際に期限切れをチェック
2. **能動的削除**: 定期的にランダムなキーをチェックして削除

**PHPのgc()メソッド:**
```php
public function gc(int $max_lifetime): int|false
{
    return 0;
}
```

**注意:**
- 明示的なガベージコレクションは不要
- RedisのTTL機能に依存

## 6. 接続管理

### 6.1 接続プーリング

#### 6.1.1 通常の接続

```php
$redis->connect('localhost', 6379);
```

- リクエストごとに新しい接続を作成
- リクエスト終了時に接続を閉じる

#### 6.1.2 永続的接続

```php
$redis->pconnect('localhost', 6379);
```

- 接続がプロセス間で再利用される
- 接続のオーバーヘッドを削減

### 6.2 接続の再利用

```php
class RedisConnection
{
    private static ?Redis $sharedConnection = null;

    public function connect(): bool
    {
        if ($this->config['persistent'] && self::$sharedConnection !== null) {
            $this->redis = self::$sharedConnection;
            $this->connected = true;
            return true;
        }

        // 通常の接続処理
        // ...

        if ($this->config['persistent']) {
            self::$sharedConnection = $this->redis;
        }

        return true;
    }
}
```

### 6.3 接続エラーのハンドリング

#### 6.3.1 再接続戦略

```php
private function reconnect(int $maxRetries = 3): bool
{
    $retryIntervals = [100, 200, 400];
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $this->disconnect();
            $this->connected = false;
            
            if ($this->connect()) {
                return true;
            }
        } catch (Exception $e) {
            if ($i < $maxRetries - 1) {
                usleep($retryIntervals[$i] * 1000);
            }
        }
    }
    
    return false;
}
```

#### 6.3.2 接続状態の確認

```php
public function ensureConnected(): void
{
    if (!$this->isConnected()) {
        if (!$this->reconnect()) {
            throw new RuntimeException('Unable to establish Redis connection');
        }
    }
}

public function get(string $key): string|false
{
    $this->ensureConnected();
    
    try {
        return $this->redis->get($key);
    } catch (RedisException $e) {
        error_log('[ERROR] Redis GET failed: ' . $e->getMessage());
        return false;
    }
}
```

### 6.4 タイムアウト設定

```php
$connection = new RedisConnection([
    'timeout' => 2.5,
    'read_timeout' => 2.5,
]);
```

| タイムアウト | 説明 | 推奨値 |
|------------|------|--------|
| timeout | 接続タイムアウト | 2.5秒 |
| read_timeout | 読み取りタイムアウト | 2.5秒 |

### 6.5 接続数の管理

#### 6.5.1 最大接続数の計算

```
最大接続数 = PHPプロセス数 × 接続数/プロセス
```

**例:**
- PHPプロセス数: 50
- 接続数/プロセス: 1（永続的接続）
- 最大接続数: 50

#### 6.5.2 Redisの最大接続数設定

```
# redis.conf
maxclients 10000
```

## 7. Redis操作の実装

### 7.1 基本操作

#### 7.1.1 GET操作

```php
public function get(string $key): string|false
{
    $this->connect();
    
    try {
        $value = $this->redis->get($key);
        return $value !== false ? $value : false;
    } catch (Exception $e) {
        error_log('[ERROR] Redis GET failed: ' . $e->getMessage());
        return false;
    }
}
```

**Redisコマンド:**
```
GET session:abc123
```

#### 7.1.2 SET操作

```php
public function set(string $key, string $value, int $ttl): bool
{
    $this->connect();
    
    try {
        return $this->redis->setex($key, $ttl, $value) !== false;
    } catch (Exception $e) {
        error_log('[ERROR] Redis SET failed: ' . $e->getMessage());
        return false;
    }
}
```

**Redisコマンド:**
```
SETEX session:abc123 1440 "data"
```

#### 7.1.3 DELETE操作

```php
public function delete(string $key): bool
{
    $this->connect();
    
    try {
        return $this->redis->del($key) >= 0;
    } catch (Exception $e) {
        error_log('[ERROR] Redis DEL failed: ' . $e->getMessage());
        return false;
    }
}
```

**Redisコマンド:**
```
DEL session:abc123
```

#### 7.1.4 EXISTS操作

```php
public function exists(string $key): bool
{
    $this->connect();
    
    try {
        return $this->redis->exists($key) === 1;
    } catch (Exception $e) {
        error_log('[ERROR] Redis EXISTS failed: ' . $e->getMessage());
        return false;
    }
}
```

**Redisコマンド:**
```
EXISTS session:abc123
```

### 7.2 バッチ操作（将来の拡張）

#### 7.2.1 パイプライン処理

```php
public function multiGet(array $keys): array
{
    $this->connect();
    
    $pipeline = $this->redis->multi(Redis::PIPELINE);
    
    foreach ($keys as $key) {
        $pipeline->get($key);
    }
    
    $results = $pipeline->exec();
    
    return array_combine($keys, $results);
}
```

#### 7.2.2 トランザクション

```php
public function transaction(callable $callback): bool
{
    $this->connect();
    
    $this->redis->multi();
    
    try {
        $callback($this->redis);
        $this->redis->exec();
        return true;
    } catch (Exception $e) {
        $this->redis->discard();
        return false;
    }
}
```

## 8. ValKey対応

### 8.1 ValKeyについて

ValKeyは、Redisのフォークプロジェクトで、Redis互換のインメモリデータストアです。

**特徴:**
- Redis互換のプロトコル
- ext-redisで接続可能
- 同じコードで動作

### 8.2 ValKeyへの接続

```php
$connection = new RedisConnection([
    'host' => 'valkey.example.com',
    'port' => 6379,
]);
```

**注意:**
- 接続方法はRedisと同じ
- コードの変更は不要

### 8.3 互換性

| 機能 | Redis | ValKey |
|------|-------|--------|
| 基本的なKVS操作 | ✓ | ✓ |
| TTL管理 | ✓ | ✓ |
| パイプライン | ✓ | ✓ |
| トランザクション | ✓ | ✓ |
| Pub/Sub | ✓ | ✓ |

## 9. パフォーマンス最適化

### 9.1 接続の最適化

1. **永続的接続の使用**: 接続のオーバーヘッドを削減
2. **接続プーリング**: 複数の接続を効率的に管理
3. **タイムアウトの調整**: 適切なタイムアウト値の設定

### 9.2 操作の最適化

1. **パイプライン処理**: 複数の操作をまとめて実行
2. **適切なTTL設定**: 不要なデータの自動削除
3. **キープレフィックスの使用**: 効率的なキー管理

### 9.3 ベンチマーク

```php
$start = microtime(true);

for ($i = 0; $i < 1000; $i++) {
    $connection->set("test:$i", "data", 3600);
}

$elapsed = microtime(true) - $start;
echo "1000 SET operations: " . $elapsed . " seconds\n";
```

## 10. セキュリティ

### 10.1 認証

```php
$connection = new RedisConnection([
    'host' => 'localhost',
    'port' => 6379,
    'password' => getenv('REDIS_PASSWORD'),
]);
```

### 10.2 TLS/SSL接続（将来の拡張）

```php
$connection = new RedisConnection([
    'host' => 'tls://redis.example.com',
    'port' => 6380,
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'cafile' => '/path/to/ca.crt',
    ],
]);
```

### 10.3 アクセス制御

- Redisの`ACL`機能を使用
- 最小権限の原則に従う
- セッション用の専用ユーザーを作成

### 10.4 セッションIDのログ保護

セッションIDは機密情報であり、ログに記録する際には必ずマスキングする必要があります。

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

// ログ出力時にセッションIDをマスキング
$logger->info('Redis operation', [
    'session_id' => SessionIdMasker::mask($sessionId),
    'operation' => 'GET',
]);
```

詳細は[doc/architecture.md](architecture.md)および[doc/specification.md](specification.md)のセキュリティセクションを参照してください。

## 11. 監視とデバッグ

### 11.1 接続状態の監視

```php
if ($connection->isConnected()) {
    echo "Connected to Redis\n";
} else {
    echo "Not connected to Redis\n";
}
```

### 11.2 Redis情報の取得

```php
public function getInfo(): array
{
    $this->connect();
    return $this->redis->info();
}
```

### 11.3 デバッグログ

```php
$this->redis->setOption(Redis::OPT_REPLY_LITERAL, true);
```

## 12. まとめ

本ドキュメントでは、Redis/ValKeyとの統合方法について詳細に説明しました。ext-redisを使用することで、高速で信頼性の高いセッション管理が実現できます。

**重要なポイント:**
1. ext-redisの適切な設定
2. キー命名規則の遵守
3. TTL管理の理解
4. 接続管理の最適化
5. エラーハンドリングの実装

これらの仕様に従うことで、スケーラブルで保守性の高いセッションハンドラを実装できます。
