# コードスタイルと規約

## コードスタイル基準

### PSR-12準拠
このプロジェクトはPSR-12コーディング規格に完全準拠しています。
`.php-cs-fixer.php`で設定され、`composer cs-check`でチェック、`composer cs-fix`で自動修正されます。

### 静的解析
- **レベル**: PHPStan 最大レベル（level: max）
- **厳密ルール**: phpstan-strict-rulesを適用
- **対象**: src/とtests/ディレクトリ
- **設定ファイル**: `phpstan.neon`

## 命名規則

### クラス名
- PascalCase（例: `RedisSessionHandler`, `SessionConfig`）
- インターフェースは`Interface`サフィックス（例: `SessionIdGeneratorInterface`, `ReadHookInterface`）

### メソッド名
- camelCase（例: `getConnectionConfig`, `addReadHook`）
- getter/setterパターンを使用

### プロパティ名
- camelCase（例: `$connectionConfig`, `$maxLifetime`）
- private/publicを明示的に宣言

## 型ヒント

### 厳格な型指定
- **全てのメソッド引数に型ヒントを付ける**
- **全てのメソッド戻り値に型を指定する**
- **プロパティにも型を指定する**（PHP 7.4+）

例：
```php
public function __construct(
    RedisConnectionConfig $connectionConfig,
    SessionSerializerInterface $serializer,
    SessionIdGeneratorInterface $idGenerator,
    int $maxLifetime,
    LoggerInterface $logger
) {
    // ...
}

public function setMaxLifetime(int $maxLifetime): self
{
    // ...
    return $this;
}
```

### 配列型の指定
PHPDocで詳細な型情報を提供：
```php
/** @var array<ReadHookInterface> */
private array $readHooks = [];
```

## エラーハンドリング

### 入力検証
設定クラス（Config配下）では、コンストラクタで必ず入力検証を実施：
- ポート番号: 1-65535の範囲チェック
- タイムアウト値: 非負の値チェック
- TTL値: 正の値チェック
- 配列パラメータ: 空でないことのチェック

無効な値の場合は`InvalidArgumentException`を投げる。

### カスタム例外
Redis操作のエラーは`RedisSessionException`およびそのサブクラスでハンドリング：
- `ConnectionException`: 接続エラー
- `ConfigurationException`: 設定エラー
- `SessionDataException`: データエラー
- `OperationException`: 操作エラー
- `HookException`: フック処理エラー

## セキュリティ規約

### セッションIDのログ出力
セッションIDは機密情報のため、ログ出力時は必ずマスキング：

```php
private function maskSessionId(string $sessionId): string
{
    if (strlen($sessionId) <= 4) {
        return '...' . $sessionId;
    }
    return '...' . substr($sessionId, -4);
}

// ログ出力例
$this->logger->debug('Session operation', [
    'session_id' => $this->maskSessionId($sessionId),
]);
```

**重要**: 生のセッションIDをログに記録しない。末尾4文字のみ表示する。

## ドキュメント規約

### PHPDoc
- クラス・メソッドには適切なPHPDocを記述
- 複雑なロジックにはインラインコメントを追加
- 日本語コメントを使用可能

## テスト規約

### テスト配置
- 単体テスト: `tests/`直下
- 統合テスト: `tests/Integration/`
- E2Eテスト: `tests/E2E/`
- サポートクラス: `tests/Support/`

### テスト命名
- テストクラス名: `*Test.php`（例: `RedisSessionHandlerTest.php`）
- テストメソッド名: `test*`（例: `testMethodName`）

### テスト環境
- PHPUnit 9.6+を使用
- `phpunit.xml`で設定
- `session.serialize_handler`は`php_serialize`に設定
