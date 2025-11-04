# アーキテクチャ概要

## レイヤー構成

```text
PHPアプリケーション
    ↓
RedisSessionHandler (SessionHandlerInterface実装)
    ↓ ↓ ↓
SessionIdGenerator / ReadHook / WriteHook / WriteFilter
    ↓
RedisConnection (接続管理・エラーハンドリング)
    ↓
Redis/ValKey
```

## コアコンポーネント

### SessionHandlerFactory
- **役割**: ファクトリーパターンでハンドラを生成
- **機能**: 設定を注入し、RedisSessionHandlerをビルド
- **場所**: `src/SessionHandlerFactory.php`

### RedisSessionHandler
- **役割**: セッションハンドラの主要実装
- **実装インターフェース**:
  - `SessionHandlerInterface`
  - `SessionUpdateTimestampHandlerInterface`
- **機能**: セッションのCRUD操作とガベージコレクション
- **場所**: `src/RedisSessionHandler.php`

### RedisConnection
- **役割**: Redis/ValKeyへの接続管理
- **機能**: リトライ、エラーハンドリング、接続プーリング
- **場所**: `src/RedisConnection.php`

### SessionIdGeneratorInterface
- **役割**: セッションID生成をカスタマイズ可能にする
- **実装例**:
  - `DefaultSessionIdGenerator`: 標準実装
  - `SecureSessionIdGenerator`: セキュア実装
  - `PrefixedSessionIdGenerator`: プレフィックス付き実装
  - `TimestampPrefixedSessionIdGenerator`: タイムスタンプ付き実装
- **場所**: `src/SessionId/`

### フック機構

#### ReadHookInterface
- **役割**: セッションの読み込み時にフック処理を挿入
- **実装例**: `LoggingHook`, `ReadTimestampHook`, `FallbackReadHook`
- **場所**: `src/Hook/`

#### WriteHookInterface
- **役割**: セッションの書き込み時にフック処理を挿入
- **実装例**: `LoggingHook`, `DoubleWriteHook`
- **場所**: `src/Hook/`

#### WriteFilterInterface
- **役割**: セッションデータの書き込み前にフィルタリング
- **実装例**: `EmptySessionFilter`
- **場所**: `src/Hook/`

### Serializer
- **役割**: セッションデータのシリアライズ/デシリアライズ
- **実装**:
  - `PhpSerializer`: PHP標準のserialize/unserialize
  - `PhpSerializeSerializer`: session_encode/session_decode
- **場所**: `src/Serializer/`

### 設定クラス（Config）
- **RedisConnectionConfig**: Redis接続設定（ホスト、ポート、認証等）
- **SessionConfig**: セッション設定（TTL、ジェネレータ、フック等）
- **RedisSessionHandlerOptions**: ハンドラオプション
- **場所**: `src/Config/`

### 例外クラス（Exception）
- **RedisSessionException**: 基底例外クラス
- **ConnectionException**: 接続エラー
- **ConfigurationException**: 設定エラー
- **SessionDataException**: データエラー
- **OperationException**: 操作エラー
- **HookException**: フック処理エラー
- **場所**: `src/Exception/`

### サポートクラス（Support）
- **SessionIdMasker**: セッションIDのマスキング処理
- **場所**: `src/Support/`

### セッション管理ユーティリティ（Session）
- **PreventEmptySessionCookie**: 空セッションのクッキー送信を防止
- **場所**: `src/Session/`

## ディレクトリ構成

```
src/
├── Config/              設定クラス
├── Exception/           例外クラス
├── Hook/                フック実装
├── Serializer/          シリアライザ実装
├── Session/             セッション管理ユーティリティ
├── SessionId/           セッションIDジェネレータ実装
├── Support/             サポートクラス
├── RedisConnection.php  Redis接続管理
├── RedisSessionHandler.php  セッションハンドラ本体
└── SessionHandlerFactory.php  ファクトリー

tests/
├── Integration/         統合テスト
├── E2E/                 エンドツーエンドテスト
├── Support/             テストサポートクラス
└── *.php                単体テスト

doc/
├── architecture.md      アーキテクチャ設計書
├── specification.md     機能仕様書
├── factory-usage.md     ファクトリー使用ガイド
└── redis-integration.md Redis/ValKey統合仕様

examples/
├── 01-basic-usage.php
├── 02-custom-session-id.php
├── 03-double-write.php
├── 04-fallback-read.php
├── 05-logging.php
├── 06-empty-session-no-cookie.php
└── docker-demo/
```

## データフロー

### セッション読み込み
1. アプリケーション → `session_start()`
2. `RedisSessionHandler::read($sessionId)`
3. ReadHooksの実行
4. `RedisConnection` → Redis/ValKeyからデータ取得
5. Serializerでデシリアライズ
6. アプリケーションに返却

### セッション書き込み
1. アプリケーション → セッションデータ更新
2. `RedisSessionHandler::write($sessionId, $data)`
3. WriteFiltersでフィルタリング
4. Serializerでシリアライズ
5. WriteHooksの実行
6. `RedisConnection` → Redis/ValKeyへ保存（TTL付き）

## 拡張ポイント

1. **SessionIdGeneratorInterface**: カスタムID生成ロジック
2. **ReadHookInterface**: 読み込み時の追加処理
3. **WriteHookInterface**: 書き込み時の追加処理
4. **WriteFilterInterface**: 書き込みデータのフィルタリング
5. **SessionSerializerInterface**: カスタムシリアライザ

## 重要な実装パターン

### ファクトリーパターンの使用
新しいセッションハンドラインスタンスを作成する際は、必ずSessionHandlerFactoryを使用：

```php
$config = new SessionConfig(
    new RedisConnectionConfig(),
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    (int)ini_get('session.gc_maxlifetime'),
    new NullLogger()
);
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();
```

### ビルダーパターン
SessionConfigはビルダーパターンもサポート：
- `addReadHook()`
- `addWriteHook()`
- `addWriteFilter()`
- `setConnectionConfig()`
- など
