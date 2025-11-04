# システムアーキテクチャ設計書

## 1. プロジェクト概要

### 1.1 目的
enhanced-redis-session-handler.phpは、PHPのセッション管理をRedis/ValKeyを使用して実装する拡張可能なセッションハンドラライブラリです。標準的なRedisセッションハンドラに加えて、プラグイン機構とフック機能を提供することで、カスタマイズ性と拡張性を高めています。

### 1.2 主要な特徴
- **SessionHandlerInterface準拠**: PHPの標準セッションハンドラインターフェースを完全実装
- **プラグイン可能なセッションIDジェネレータ**: セッションID生成ロジックをカスタマイズ可能
- **プラグイン可能なSerializer**: セッションデータのシリアライズ形式を切り替え可能
- **Hook/Filter機構**: セッションの読み込み・書き込み時に処理を挿入・制御可能
- **Redis/ValKey対応**: ext-redisを使用した高速なセッションストレージ
- **拡張性**: 新しい機能を容易に追加できる設計

### 1.3 対象ユーザー
- 水平スケーリングが必要なPHPアプリケーション開発者
- セッション管理をカスタマイズしたい開発者
- 高可用性が求められるWebサービスの運用者

## 2. アーキテクチャ概要

### 2.1 レイヤー構成

```
┌─────────────────────────────────────────────────────┐
│           PHPアプリケーション層                        │
│  (session_start(), $_SESSION, session_write_close()) │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│         セッションハンドラ層                           │
│  ┌─────────────────────────────────────────────┐   │
│  │      RedisSessionHandler                     │   │
│  │  (SessionHandlerInterface実装)               │   │
│  └─────────────────────────────────────────────┘   │
│           ↓              ↓              ↓            │
│  ┌──────────────┐ ┌──────────┐ ┌──────────────┐   │
│  │SessionId     │ │Serializer│ │Hook/Filter   │   │
│  │Generator     │ │          │ │Manager       │   │
│  └──────────────┘ └──────────┘ └──────────────┘   │
│                        ↓              ↓              │
│                   ┌─────────┐  ┌─────────────┐     │
│                   │ReadHook │  │WriteHook    │     │
│                   └─────────┘  │WriteFilter  │     │
│                                └─────────────┘     │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│           Redis接続管理層                             │
│  ┌─────────────────────────────────────────────┐   │
│  │      RedisConnection                         │   │
│  │  (接続管理、エラーハンドリング)                 │   │
│  └─────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│              Redis/ValKey                            │
│         (セッションデータストレージ)                    │
└─────────────────────────────────────────────────────┘
```

### 2.2 コンポーネント間の関係

```
PHPアプリケーション
         ↓
 RedisSessionHandler
    ↙    ↓    ↘
Generator Serializer Hook/Filter
              ↓         ↓
         ReadHook  WriteHook/WriteFilter
    ↓                  ↓
RedisConnection
    ↓
Redis/ValKey
```

## 3. コアコンポーネント設計

### 3.1 RedisSessionHandler

#### 3.1.1 責務
- PHPのSessionHandlerInterfaceの実装
- SessionUpdateTimestampHandlerInterfaceの実装
- セッションのCRUD操作（作成、読み込み、更新、削除）
- ガベージコレクション
- Hook/Filterの呼び出し管理
- Serializerによるデータ変換

#### 3.1.2 主要プロパティ

```php
class RedisSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface
{
    private RedisConnection $connection;
    private SessionIdGeneratorInterface $idGenerator;
    private LoggerInterface $logger;

    /** @var ReadHookInterface[] */
    private array $readHooks = [];

    /** @var WriteHookInterface[] */
    private array $writeHooks = [];

    /** @var WriteFilterInterface[] */
    private array $writeFilters = [];

    private int $maxLifetime;
    private SessionSerializerInterface $serializer;
}
```

#### 3.1.3 主要メソッド

```php
// 基本操作
public function open(string $path, string $name): bool;
public function close(): bool;
public function read(string $id): string|false;
public function write(string $id, string $data): bool;
public function destroy(string $id): bool;
public function gc(int $max_lifetime): int|false;

// タイムスタンプ更新
public function validateId(string $id): bool;
public function updateTimestamp(string $id, string $data): bool;

// セッションID生成
public function create_sid(): string;

// Hook/Filter管理
public function addReadHook(ReadHookInterface $hook): void;
public function addWriteHook(WriteHookInterface $hook): void;
public function addWriteFilter(WriteFilterInterface $filter): void;
```

**設計上の注意点：**

このライブラリでは、`RedisSessionHandler`は`SessionHandlerInterface`を直接実装します。PHPには`SessionHandler`という抽象クラスも存在しますが、以下の理由から使用しません：

- **完全な制御**: インターフェースを直接実装することで、すべてのメソッドの動作を完全に制御できます
- **Hook/Filter機構の実装**: read/writeメソッドの前後に処理を挿入するため、デフォルト実装に依存しない方が適切です
- **明示的な実装**: すべてのメソッドを明示的に実装することで、コードの意図が明確になります
- **柔軟性**: 将来的な拡張や変更に対して柔軟に対応できます

#### 3.1.4 依存関係
- RedisConnection: Redis接続管理
- SessionIdGeneratorInterface: セッションID生成
- SessionSerializerInterface: データシリアライズ
- ReadHookInterface[]: 読み込み時フック（複数）
- WriteHookInterface[]: 書き込み時フック（複数）
- WriteFilterInterface[]: 書き込みフィルター（複数）

### 3.2 RedisConnection

#### 3.2.1 責務
- Redis/ValKeyへの接続管理
- 接続エラーのハンドリング
- 接続の再利用（接続プーリング）
- Redis操作の抽象化
- リトライ戦略の実装

#### 3.2.2 主要メソッド

```php
class RedisConnection
{
    public function __construct(
        Redis $redis,
        RedisConnectionConfig $config,
        LoggerInterface $logger
    );

    public function connect(): void;
    public function close(): void;
    public function get(string $key): string|false;
    public function set(string $key, string $value, int $ttl): bool;
    public function delete(string $key): bool;
    public function exists(string $key): bool;
    public function expire(string $key, int $ttl): bool;
    public function isConnected(): bool;
}
```

#### 3.2.3 設定パラメータ（RedisConnectionConfig）
- host: Redisサーバーのホスト名
- port: Redisサーバーのポート番号
- timeout: 接続タイムアウト
- password: 認証パスワード（オプション）
- database: データベース番号（デフォルト: 0）
- prefix: キープレフィックス（デフォルト: "session:"）
- persistent: 永続的接続の使用
- retryInterval: リトライ間隔
- readTimeout: 読み取りタイムアウト
- maxRetries: 最大リトライ回数

### 3.3 プラグインアーキテクチャ

#### 3.3.1 SessionIdGeneratorInterface

```php
interface SessionIdGeneratorInterface
{
    public function generate(): string;
}
```

**実装例:**
- `DefaultSessionIdGenerator`: PHPのデフォルトアルゴリズムを使用（16バイト = 32文字）
- `SecureSessionIdGenerator`: より強力なランダム性を持つ実装（カスタマイズ可能な長さ）
- `PrefixedSessionIdGenerator`: プレフィックス付きID生成
- `TimestampPrefixedSessionIdGenerator`: タイムスタンププレフィックス付き

#### 3.3.2 SessionSerializerInterface（新機能）

```php
interface SessionSerializerInterface
{
    public function decode(string $data): array;
    public function encode(array $data): string;
    public function getName(): string;
}
```

**目的**: PHPのセッションデータシリアライズ形式の切り替えをサポート

**実装:**
- `PhpSerializer`: session.serialize_handler = 'php'形式
- `PhpSerializeSerializer`: session.serialize_handler = 'php_serialize'形式

**設計背景**:
- PHPのセッションハンドラは`read()`で文字列を返し、`write()`で文字列を受け取る
- Hook/Filterでは配列形式のデータを扱いたい
- Serializerがその変換を担当

**データフロー**:
```
Redis → 文字列 → [Serializer.decode] → 配列 → Hook処理 → [Serializer.encode] → 文字列 → Redis
```

#### 3.3.3 ReadHookInterface

```php
interface ReadHookInterface
{
    public function beforeRead(string $sessionId): void;
    public function afterRead(string $sessionId, string $data): string;
}
```

**用途例:**
- アクセスログの記録（LoggingHook）
- タイムスタンプの更新（ReadTimestampHook）
- フォールバック処理（FallbackReadHook）

#### 3.3.4 WriteHookInterface

```php
interface WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array;
    public function afterWrite(string $sessionId, bool $success): void;
    public function onWriteError(string $sessionId, Throwable $exception): void;
}
```

**重要**: 実装では`array`を受け取り・返す設計に変更されています（Serializer導入のため）

**用途例:**
- ログ記録（LoggingHook）
- 二重書き込み（DoubleWriteHook）
- データ変換や検証

#### 3.3.5 WriteFilterInterface（新機能）

```php
interface WriteFilterInterface
{
    public function shouldWrite(string $sessionId, array $data): bool;
}
```

**目的**: 書き込み操作自体をキャンセルする判断

**WriteHookとの違い**:
- **WriteHook**: データを変換する（暗号化、圧縮など）
- **WriteFilter**: 書き込みの可否を判断する（条件による制御）

**実装例:**
- `EmptySessionFilter`: 空セッションの書き込みをスキップ

**実行順序**:
1. `WriteFilter.shouldWrite()` で書き込みの可否を判断
2. `false`なら書き込み処理全体をスキップ
3. `true`なら`WriteHook.beforeWrite()`以降を実行

### 3.4 SessionHandlerFactory

#### 3.4.1 責務
- `RedisSessionHandler`のインスタンス生成
- `SessionConfig`に基づく設定の適用
- 依存関係の注入とワイヤリング
- Hook/Filterの登録

#### 3.4.2 主要メソッド

```php
class SessionHandlerFactory
{
    public function __construct(SessionConfig $config);
    public function build(): RedisSessionHandler;
    public function getConfig(): SessionConfig;
}
```

**設計パターン:**
ファクトリーパターンを採用することで、複雑な依存関係の管理を簡素化し、ユーザーコードから実装の詳細を隠蔽します。

**使用例:**

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

詳細な使用方法については、[../users/factory-usage.md](../users/factory-usage.md)を参照してください。

### 3.5 SessionIdMasker

#### 3.5.1 責務
- セッションIDのマスキング処理
- ログ出力時のセキュリティ保護

#### 3.5.2 主要メソッド

```php
class SessionIdMasker
{
    public static function mask(string $sessionId): string;
}
```

**実装詳細:**
- デバッグのためにセッションIDの末尾4文字のみを表示
- 長さが4文字以下の場合は全体を"..."でプレフィックス
- 例: "abc123def456" → "...f456"

**セキュリティ上の理由:**
セッションIDは機密情報であり、ログに記録すると漏洩時にセッションハイジャックのリスクがあります。末尾4文字のみ表示することで、デバッグ時の相関分析は可能にしつつ、セキュリティを確保します。

### 3.6 PreventEmptySessionCookie

#### 3.6.1 責務
- 空セッション時のCookie送信を防止
- セッションの状態を監視してCookieを制御

#### 3.6.2 主要メソッド

```php
class PreventEmptySessionCookie
{
    public static function setup(
        ?WriteFilterInterface $filter = null,
        ?LoggerInterface $logger = null
    ): void;

    public static function checkAndCleanup(): void;
    public static function reset(): void;
}
```

**設計背景**:
- 空セッションの場合、Redisにはデータを保存しない（EmptySessionFilter）
- しかし、PHPは自動的にセッションCookieを送信してしまう
- 次回アクセス時に不要なRedis問い合わせが発生する

**解決策**:
1. `shutdown_function`でセッション終了時にチェック
2. 空セッションだった場合、過去の有効期限でCookieを上書き（削除）
3. これによりクライアント側のCookieが削除される

詳細は[implementation/prevent-empty-cookie.md](implementation/prevent-empty-cookie.md)を参照してください。

## 4. データフロー

### 4.1 セッション読み込みフロー

```
session_start()
     ↓
RedisSessionHandler::open()
     ↓
RedisSessionHandler::read(sessionId)
     ↓
[ReadHook::beforeRead()] (全Hook実行)
     ↓
RedisConnection::get(key)
     ↓
Redis: GET session:xxx
     ↓
文字列データ取得
     ↓
Serializer::decode(文字列) → 配列
     ↓
[ReadHook::afterRead()] (全Hook実行)
     ↓
Serializer::encode(配列) → 文字列
     ↓
PHPに返却（$_SESSIONに展開）
```

### 4.2 セッション書き込みフロー

```
session_write_close()
     ↓
RedisSessionHandler::write(sessionId, data)
     ↓
Serializer::decode(data文字列) → 配列
     ↓
[WriteFilter::shouldWrite()] (全Filter実行)
     ↓
書き込みキャンセル判定
     ↓ (続行する場合)
[WriteHook::beforeWrite()] (全Hook実行、配列変換)
     ↓
Serializer::encode(配列) → 文字列
     ↓
RedisConnection::set(key, 文字列, ttl)
     ↓
Redis: SETEX session:xxx ttl data
     ↓
[WriteHook::afterWrite()] (全Hook実行)
     ↓
RedisSessionHandler::close()
     ↓
(shutdown_functionでPreventEmptySessionCookie::checkAndCleanup())
```

### 4.3 セッションID生成フロー

```
session_start() (新規)
     ↓
RedisSessionHandler::create_sid()
     ↓
SessionIdGenerator::generate()
     ↓
重複チェック: RedisConnection::exists()
     ↓
新しいセッションID確定
```

## 5. クラス構成図

```
SessionHandlerInterface
SessionUpdateTimestampHandlerInterface
          ↑
          |
   RedisSessionHandler
    ↙  ↓  ↓  ↘
   /   |  |   \
  /    |  |    \
Generator Serializer Hook Filter
  |       |       |      |
  |       |    ReadHook WriteHook
  |       |             WriteFilter
  |       |
  |    PhpSerializer
  |    PhpSerializeSerializer
  |
DefaultSessionIdGenerator
SecureSessionIdGenerator
PrefixedSessionIdGenerator
```

詳細なクラス図は各実装ドキュメントを参照してください。

## 6. エラーハンドリング方針

### 6.1 エラーの分類

1. **接続エラー** (`ConnectionException`): Redis/ValKeyへの接続失敗
2. **操作エラー** (`OperationException`): Redis操作の失敗（GET, SET, DELなど）
3. **データエラー** (`SessionDataException`): セッションデータの破損や不正なフォーマット
4. **設定エラー** (`ConfigurationException`): 不正な設定パラメータ
5. **フックエラー** (`HookException`): Hook/Filter実行時のエラー

### 6.2 エラーハンドリング戦略

- **接続エラー**: 再接続を試み（最大3回）、失敗した場合は例外をスロー
- **操作エラー**: ログに記録し、falseを返す（PHPのセッションハンドラ仕様に準拠）
- **データエラー**: ログに記録し、空のセッションデータを返す
- **設定エラー**: 初期化時に例外をスロー
- **フックエラー**: ログに記録し、フック処理をスキップ（セッション機能は継続）

### 6.3 ログレベル

- **CRITICAL**: 接続失敗、設定エラー
- **ERROR**: 操作失敗、データ破損
- **WARNING**: 再接続試行、タイムアウト
- **INFO**: 正常な操作、ガベージコレクション実行
- **DEBUG**: 詳細な操作ログ

## 7. パフォーマンス考慮事項

### 7.1 最適化ポイント

1. **接続の再利用**: 同一リクエスト内でRedis接続を再利用
2. **TTLの適切な設定**: セッションの有効期限を適切に管理（自動削除）
3. **キープレフィックスの使用**: 名前空間の分離とキー管理の効率化
4. **Serializerの選択**: 用途に応じた最適なシリアライズ形式
5. **Filter早期終了**: WriteFilterで不要な書き込みをスキップ

### 7.2 スケーラビリティ

- **水平スケーリング**: 複数のWebサーバーで同一のRedisインスタンスを共有
- **Redis Cluster対応**: 将来的にRedis Clusterへの対応を検討（拡張ポイント）
- **読み取りレプリカ**: 読み取り専用レプリカの活用（将来の拡張）

## 8. セキュリティ考慮事項

### 8.1 セッションID生成

- 暗号学的に安全な乱数生成器の使用（`random_bytes()`）
- 十分な長さとエントロピーの確保（最低16バイト）
- セッションID固定攻撃への対策

### 8.2 データ保護

- Redis接続の暗号化（TLS/SSL対応可能）
- セッションデータの暗号化（WriteHookで実装可能）
- アクセス制御（Redis認証の使用）

### 8.3 セッションハイジャック対策

- セッションIDの定期的な再生成
- IPアドレスやUser-Agentの検証（ReadHookで実装可能）
- タイムアウトの適切な設定

### 8.4 セッションIDのログ出力時の保護

セッションIDは機密情報のため、ログ出力時には必ずマスキングすることが重要です。ログ漏洩時のセッションハイジャックのリスクを軽減するため、`SessionIdMasker`ユーティリティクラスを使用します。

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$logger->info('Session operation', [
    'session_id' => SessionIdMasker::mask($sessionId),
]);
```

**重要**:
- セキュリティ上、ログには生のセッションIDを記録しないこと
- すべての組み込みフック（LoggingHook、DoubleWriteHook等）は自動的にセッションIDをマスキング
- 末尾4文字のみ表示することで、デバッグ時の相関分析は可能にしつつセキュリティを確保

## 9. 拡張ポイント

### 9.1 プラグイン機構

以下のインターフェースを実装することで、動作をカスタマイズ可能：

- **SessionIdGenerator**: カスタムID生成ロジック
- **SessionSerializer**: カスタムシリアライズ形式
- **ReadHook**: 読み込み時の前処理・後処理
- **WriteHook**: 書き込み時の前処理・後処理
- **WriteFilter**: 書き込みの可否判断

### 9.2 将来の拡張候補

1. **複数バックエンド対応**: Memcached、DynamoDBなど
2. **セッションレプリケーション**: 複数のRedisインスタンスへの同時書き込み
3. **セッション分析機能**: アクセスパターンの分析
4. **自動スケーリング**: 負荷に応じたRedis接続数の調整
5. **パイプライン処理**: 複数のRedis操作をまとめて実行

## 10. テスト戦略

### 10.1 ユニットテスト

- 各クラスの個別機能テスト
- モックを使用した依存関係の分離
- エッジケースとエラーケースのテスト

### 10.2 統合テスト

- 実際のRedis接続を使用したテスト
- セッションのライフサイクル全体のテスト
- Hook/Filterの統合テスト

### 10.3 E2Eテスト

- 実際のPHPセッション機能を使用したテスト
- PreventEmptySessionCookieのテスト
- 各種シナリオの動作確認

詳細は[testing.md](testing.md)を参照してください。

## 11. デプロイメント

### 11.1 必要な環境

- PHP 7.4以上
- ext-redis拡張 5.0以上
- Redis 5.0以上（公式サポート）
- ValKey 7.2.5以上（テストはValKey 9.0.0で実施）

### 11.2 インストール方法

```bash
composer require uzulla/enhanced-redis-session-handler
```

### 11.3 基本的な設定例

```php
<?php
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Psr\Log\NullLogger;

$connectionConfig = new RedisConnectionConfig(
    host: 'localhost',
    port: 6379,
    prefix: 'myapp:session:'
);

$config = new SessionConfig(
    $connectionConfig,
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    (int)ini_get('session.gc_maxlifetime'),
    new NullLogger()
);

$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

## 12. まとめ

このアーキテクチャ設計により、以下の目標を達成します：

1. **拡張性**: プラグイン機構により、機能を容易に追加可能
2. **保守性**: 明確な責務分離により、コードの理解と修正が容易
3. **パフォーマンス**: Redisの高速性を活かした効率的なセッション管理
4. **セキュリティ**: セキュアなセッションID生成とデータ保護
5. **互換性**: PHPの標準セッションハンドラインターフェースに準拠

この設計書は、実装フェーズでの指針となり、開発者が一貫性のあるコードを書くための基盤を提供します。

## 関連ドキュメント

- [implementation/session-handler.md](implementation/session-handler.md) - RedisSessionHandler実装詳細
- [implementation/serializer.md](implementation/serializer.md) - Serializer機構
- [implementation/hooks-and-filters.md](implementation/hooks-and-filters.md) - Hook/Filter機構
- [implementation/connection.md](implementation/connection.md) - Redis接続管理
- [implementation/prevent-empty-cookie.md](implementation/prevent-empty-cookie.md) - PreventEmptySessionCookie
- [testing.md](testing.md) - テスト戦略
- [../users/factory-usage.md](../users/factory-usage.md) - ファクトリー使用方法
