# [Phase 5] HookStorage: ドキュメントとサンプルコード

## 概要

HookStorageパターンの設計ドキュメント、開発者向けガイド、実用的なサンプルコードを作成します。開発者がHookStorageを理解し、適切に使用できるようにします。

## 親タスク

- **親issue**: #29 - フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）
- **Phase**: 5/6
- **依存関係**: Phase 4（統合テスト）が完了していること
- **後続Phase**: Phase 6（品質保証）

## 目的

HookStorageパターンの仕組みと使用方法を文書化し、開発者が容易に理解・実装できるようにします。

## 実装タスク

### 1. 設計ドキュメントの作成

**ファイル**: `doc/hook-storage-design.md`

**目次**:

```markdown
# HookStorage設計書

## 概要
- 問題の背景
- HookStorageパターンとは
- 設計の目的

## アーキテクチャ
- 全体構成図
- コンポーネント間の関係
- データフロー

## 主要コンポーネント
### HookStorageInterface
- 役割と責務
- メソッド仕様

### HookContext
- 深度管理の仕組み
- 無限再帰防止メカニズム

### HookRedisStorage
- 実装の詳細
- エラーハンドリング

## 設計上の決定事項
### なぜオプショナル引数なのか
- 後方互換性の重要性
- 段階的移行の戦略

### 深度制限のデフォルト値
- 3階層にした理由
- ユースケース分析

### FallbackReadHookの扱い
- 無限再帰のリスク
- 直接アクセスが適切な理由

## パフォーマンスへの影響
- オーバーヘッドの測定結果
- 最適化の方針

## セキュリティ考慮事項
- ログ出力時のセッションIDマスキング
- エラーハンドリング

## 将来の拡張性
- WriteHookへの適用
- 他のストレージバックエンド対応
```

**主な内容**:
- 技術的な背景と設計判断
- アーキテクチャ図（ASCII artまたはMermaid）
- 実装の詳細な説明
- パフォーマンス測定結果

**見積もり**: 2時間

---

### 2. write-hooks.mdの更新

**ファイル**: `doc/write-hooks.md`（既存ファイルの更新）

**追加セクション**:

```markdown
## HookStorageを使用したフック開発

### HookStorageとは

フック内でRedis操作を行う際、`HookStorageInterface`を使用することで、
その操作も他のフックチェーンを適切に通るようになります。

### 基本的な使用方法

```php
class MyCustomHook implements ReadHookInterface
{
    public function afterRead(
        string $sessionId,
        string $data,
        ?HookStorageInterface $storage = null
    ): string {
        // HookStorageが提供されている場合に使用
        if ($storage !== null) {
            $storage->set('my_key:' . $sessionId, 'value', 3600);
        }
        return $data;
    }
}
```

### 後方互換性の維持

`$storage`パラメータはオプショナルです。既存のフック実装は
そのまま動作し続けます。

```php
// 旧実装（引き続き動作）
public function afterRead(string $sessionId, string $data): string
{
    return $data;
}
```

### いつHookStorageを使うべきか

**使用すべき場合**:
- フック内でRedisへの書き込みが必要
- 操作をLoggingHookなどで追跡したい
- フックの合成可能性を高めたい

**使用しない方が良い場合**:
- フォールバック処理（無限再帰のリスク）
- パフォーマンスが極めて重要な場合
- Redis以外のストレージへのアクセス

### ベストプラクティス

1. **必ずnullチェック**
   ```php
   if ($storage !== null) {
       // storage使用
   }
   ```

2. **エラーハンドリング**
   ```php
   try {
       $storage->set($key, $value, $ttl);
   } catch (Throwable $e) {
       $this->logger->warning('Storage operation failed', ['exception' => $e]);
   }
   ```

3. **深度を意識しない**
   深度管理はHookStorageが自動的に行うため、フック開発者は
   意識する必要はありません。
```

**見積もり**: 1時間

---

### 3. ベストプラクティスガイドの作成

**ファイル**: `doc/hook-best-practices.md`

**目次**:

```markdown
# フック開発のベストプラクティス

## 1. フック設計の原則

### 単一責任の原則
- 各フックは1つの明確な目的を持つ
- 複数の責務を持たせない

### 合成可能性
- フックは他のフックと組み合わせて使える設計
- グローバルな状態に依存しない

## 2. HookStorageの使用指針

### 使用すべきケース
- フック内でRedis操作が必要な場合
- 操作を他のフックで監視したい場合
- デバッグ・ログ記録を統一したい場合

### 直接アクセスが適切なケース
- フォールバック処理
- 完全に独立したRedis接続への操作
- パフォーマンス最適化が必須の場合

## 3. エラーハンドリング

### 基本方針
- フック内のエラーはセッション処理全体を停止させない
- エラーは必ずログ記録する
- ユーザーに影響を与えないようにする

### 実装例
```php
public function afterRead(
    string $sessionId,
    string $data,
    ?HookStorageInterface $storage = null
): string {
    try {
        if ($storage !== null) {
            $storage->set($key, $value, $ttl);
        }
    } catch (Throwable $e) {
        $this->logger->warning('Hook operation failed', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'exception' => $e,
        ]);
        // エラーでもデータは返す
    }
    return $data;
}
```

## 4. ロギングのベストプラクティス

### セッションIDのマスキング
```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$this->logger->debug('Operation', [
    'session_id' => SessionIdMasker::mask($sessionId),
]);
```

### ログレベルの使い分け
- DEBUG: 通常動作
- INFO: 重要な状態変化
- WARNING: エラーだが動作継続可能
- ERROR: 重大なエラー

## 5. テストのベストプラクティス

### 単体テストの書き方
- HookStorageをモック化
- RedisConnectionをモック化
- LoggerをTestHandlerで検証

### 統合テストの書き方
- 実際のRedis環境を使用
- 複数フックの組み合わせをテスト
- エッジケースの検証

## 6. パフォーマンス考慮事項

### 最小限のRedis操作
- 不要な読み書きを避ける
- バッチ操作を検討する

### TTLの適切な設定
- セッションライフタイムに合わせる
- 不要なデータを残さない

## 7. セキュリティ考慮事項

### セッションIDの取り扱い
- ログに生のセッションIDを出力しない
- SessionIdMaskerを必ず使用

### データの検証
- Redis から取得したデータを検証
- 不正なデータを処理しない

## 8. 移行ガイド

### 既存フックのHookStorage対応

#### Step 1: シグネチャ更新
```php
// Before
public function afterRead(string $sessionId, string $data): string

// After
public function afterRead(
    string $sessionId,
    string $data,
    ?HookStorageInterface $storage = null
): string
```

#### Step 2: HookStorage使用コードの追加
```php
if ($storage !== null) {
    // 新方式
    $storage->set($key, $value, $ttl);
} else {
    // 旧方式（後方互換性）
    $this->connection->set($key, $value, $ttl);
}
```

#### Step 3: テストの更新
- storage使用時のテスト追加
- storage未使用時のテスト維持

### 非推奨化のタイムライン
現時点では旧方式を非推奨にする予定はありません。
将来のメジャーバージョンアップ時に検討します。
```

**見積もり**: 2時間

---

### 4. 基本的なサンプルコードの作成

**ファイル**: `examples/hook-storage-example.php`（約100行）

**内容**:

```php
<?php

declare(strict_types=1);

/**
 * HookStorageの基本的な使用例
 *
 * このサンプルでは、LoggingHook と ReadTimestampHook を組み合わせ、
 * HookStorage経由でのRedis操作が適切にログ記録されることを示します。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;

// ロガーのセットアップ
$logger = new Logger('example');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Redis接続のセットアップ
$redis = new Redis();
$config = new RedisConnectionConfig(
    host: 'localhost',
    port: 6379,
    prefix: 'example:hook_storage:'
);
$connection = new RedisConnection($redis, $config, $logger);
$connection->connect();

// セッションハンドラのセットアップ
$serializer = new PhpSerializeSerializer();
$handler = new RedisSessionHandler($connection, $serializer);

// LoggingHookを追加（全てのRedis操作をログ記録）
$loggingHook = new LoggingHook($logger);
$handler->addWriteHook($loggingHook);

// ReadTimestampHookを追加（セッション読み取り時にタイムスタンプ記録）
$timestampHook = new ReadTimestampHook(
    $connection,
    $logger,
    'session:read_at:',
    86400  // 24時間
);
$handler->addReadHook($timestampHook);

// セッション操作の実行
$handler->open('', '');

$sessionId = 'demo_session_' . uniqid();
echo "Session ID: $sessionId\n\n";

// セッションデータの書き込み
echo "=== Writing session data ===\n";
$sessionData = serialize(['user_id' => 123, 'username' => 'demo_user']);
$handler->write($sessionId, $sessionData);
echo "\n";

// セッションデータの読み取り
// この時、ReadTimestampHookがHookStorage経由でタイムスタンプを記録
// LoggingHookがその操作をログに記録
echo "=== Reading session data ===\n";
$data = $handler->read($sessionId);
echo "Data retrieved: " . substr($data, 0, 50) . "...\n\n";

// タイムスタンプの確認
$timestampKey = 'session:read_at:' . $sessionId;
$timestamp = $connection->get($timestampKey);
if ($timestamp !== false) {
    echo "=== Timestamp recorded ===\n";
    echo "Timestamp key: $timestampKey\n";
    echo "Timestamp value: $timestamp\n";
    echo "Human readable: " . date('Y-m-d H:i:s', (int)$timestamp) . "\n\n";
}

// クリーンアップ
$connection->delete($sessionId);
$connection->delete($timestampKey);

echo "=== Example completed ===\n";
echo "Check the log output above to see how HookStorage operations are logged.\n";
```

**見積もり**: 2時間

---

### 5. 実践的なサンプルコードの作成

**ファイル**: `examples/logging-with-timestamp.php`（約80行）

**内容**:

```php
<?php

declare(strict_types=1);

/**
 * 実践的なサンプル：セッション監視システム
 *
 * LoggingHook と ReadTimestampHook を組み合わせて、
 * セッションアクセスを追跡・監視する実用的な例です。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Psr\Log\LogLevel;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;

// ロガーのセットアップ（詳細な監視用）
$logger = new Logger('session_monitor');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
$logger->pushProcessor(new IntrospectionProcessor());

$redis = new Redis();
$config = new RedisConnectionConfig(
    host: 'localhost',
    port: 6379,
    prefix: 'app:sessions:'
);
$connection = new RedisConnection($redis, $config, $logger);
$connection->connect();

$serializer = new PhpSerializeSerializer();
$handler = new RedisSessionHandler($connection, $serializer);

// セッション操作をINFOレベルで記録
$handler->addWriteHook(new LoggingHook(
    $logger,
    beforeWriteLevel: LogLevel::INFO,
    afterWriteLevel: LogLevel::INFO
));

// 最終アクセス時刻を記録（24時間保持）
$handler->addReadHook(new ReadTimestampHook(
    $connection,
    $logger,
    'session:last_access:',
    86400
));

// シミュレーション：複数セッションの操作
$handler->open('', '');

$sessions = [
    ['id' => 'user_alice_' . time(), 'data' => ['user_id' => 1, 'name' => 'Alice']],
    ['id' => 'user_bob_' . time(), 'data' => ['user_id' => 2, 'name' => 'Bob']],
];

echo "=== Simulating session operations ===\n\n";

foreach ($sessions as $session) {
    echo "--- Processing session: {$session['id']} ---\n";

    // セッション作成
    $handler->write($session['id'], serialize($session['data']));

    // 少し待機
    usleep(100000);  // 0.1秒

    // セッション読み取り（タイムスタンプが記録される）
    $handler->read($session['id']);

    echo "\n";
}

// 最終アクセス時刻の確認
echo "=== Last access timestamps ===\n";
foreach ($sessions as $session) {
    $timestampKey = 'session:last_access:' . $session['id'];
    $timestamp = $connection->get($timestampKey);
    if ($timestamp !== false) {
        $elapsed = time() - (int)$timestamp;
        echo "{$session['id']}: {$elapsed} seconds ago\n";
    }
}

// クリーンアップ
foreach ($sessions as $session) {
    $connection->delete($session['id']);
    $connection->delete('session:last_access:' . $session['id']);
}

echo "\n=== Example completed ===\n";
```

**見積もり**: 1時間

---

### 6. README.mdの更新（必要に応じて）

**ファイル**: `README.md`（既存ファイルの更新）

**追加セクション案**:

```markdown
## HookStorageパターン

v1.x以降、フック内でのRedis操作が他のフックを通るようになりました。

詳細は以下のドキュメントを参照してください：
- [HookStorage設計書](doc/hook-storage-design.md)
- [フック開発ガイド](doc/write-hooks.md)
- [ベストプラクティス](doc/hook-best-practices.md)

サンプルコード：
- [基本的な使用例](examples/hook-storage-example.php)
- [実践的な例](examples/logging-with-timestamp.php)
```

**見積もり**: 0.5時間

---

## 技術的考慮事項

### 1. ドキュメントの言語

- 日本語で記述（プロジェクトの方針に従う）
- 技術用語は英語を併記

### 2. サンプルコードの実行可能性

- `composer install` 後に実行可能
- Redisが起動している環境が必要
- 実行方法をコメントで明記

### 3. Markdown linting

- MD040（code fence language）などのlint警告に対応
- CLAUDE.mdの指示に従う

## 完了条件

- [ ] 設計ドキュメント（hook-storage-design.md）が完成
- [ ] write-hooks.mdが更新されている
- [ ] ベストプラクティスガイド（hook-best-practices.md）が完成
- [ ] 基本サンプル（hook-storage-example.php）が動作する
- [ ] 実践サンプル（logging-with-timestamp.php）が動作する
- [ ] README.mdが更新されている（必要に応じて）
- [ ] 全ドキュメントがMarkdown lintをパス
- [ ] サンプルコードがPHPStan、CS Fixerをパス
- [ ] コードレビュー完了

## 見積もり

- **コード量**: 約180行（サンプルコード）+ ドキュメント
- **工数**: 約8.5時間
  - hook-storage-design.md: 2時間
  - write-hooks.md更新: 1時間
  - hook-best-practices.md: 2時間
  - hook-storage-example.php: 2時間
  - logging-with-timestamp.php: 1時間
  - README.md更新: 0.5時間

## 関連情報

- **親issue**: #29
- **前Phase**: Phase 4 - 統合テスト
- **次Phase**: Phase 6 - 品質保証
- **設計ドキュメント**: `ISSUE-29-UPDATED.md`

## ラベル

- enhancement
- priority: low
- type: documentation
- area: docs
- phase: 5/6
