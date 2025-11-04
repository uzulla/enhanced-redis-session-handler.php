# コーディング規約

## 概要

enhanced-redis-session-handler.phpは、一貫性のある高品質なコードベースを維持するため、厳格なコーディング規約を採用しています。

## コードスタイル標準

### PSR-12準拠

このプロジェクトは[PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/)に準拠しています。

**主要な規則**:

1. **インデント**: スペース4つ
2. **行の長さ**: 120文字を推奨（厳格な制限なし）
3. **改行**: Unix形式（LF）
4. **文字エンコーディング**: UTF-8（BOMなし）

### PHP CS Fixer設定

`.php-cs-fixer.php`:

```php
<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();

return $config
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
```

**コマンド**:
```bash
# コードスタイルをチェック
composer cs-check

# コードスタイルを自動修正
composer cs-fix
```

## 静的解析

### PHPStan設定

`phpstan.neon`:

```neon
includes:
    - vendor/phpstan/phpstan-strict-rules/rules.neon

parameters:
    level: max
    paths:
        - src
        - tests
    reportUnmatchedIgnoredErrors: true
```

**特徴**:
- **最大レベル（level: max）**: 最も厳格な型チェック
- **Strict Rules**: より厳格なルールセット
- **全ファイル対象**: `src/`と`tests/`の両方

**コマンド**:
```bash
# PHPStan実行
composer phpstan

# メモリ制限を解除して実行
composer phpstan -- --memory-limit=-1
```

### 型宣言の徹底

```php
<?php

declare(strict_types=1); // 全ファイルに必須

namespace Uzulla\EnhancedRedisSessionHandler;

class Example
{
    // プロパティに型宣言
    private string $name;
    private int $count;
    private ?LoggerInterface $logger = null;

    // パラメータと戻り値に型宣言
    public function process(string $input): bool
    {
        // ...
        return true;
    }

    // 配列の場合はPHPDocで詳細を記述
    /**
     * @param array<string, mixed> $data
     * @return array<string>
     */
    public function extract(array $data): array
    {
        // ...
    }
}
```

## 命名規則

### クラス名

**PascalCase（UpperCamelCase）**:

```php
// ✓ 正しい
class RedisSessionHandler {}
class SessionIdGeneratorInterface {}
class PhpSerializeSerializer {}

// ✗ 間違い
class redis_session_handler {}
class sessionIdGeneratorInterface {}
```

### メソッド名

**camelCase（lowerCamelCase）**:

```php
// ✓ 正しい
public function connect(): bool {}
public function createSessionId(): string {}
public function beforeWrite(string $id, array $data): array {}

// ✗ 間違い
public function Connect(): bool {}
public function create_session_id(): string {}
```

### プロパティ名

**camelCase**:

```php
// ✓ 正しい
private Redis $redis;
private LoggerInterface $logger;
private int $maxRetries;

// ✗ 間違い
private Redis $Redis;
private LoggerInterface $Logger;
private int $max_retries;
```

### 定数名

**UPPER_SNAKE_CASE**:

```php
// ✓ 正しい
public const MIN_LENGTH = 32;
public const DEFAULT_TIMEOUT = 2.5;

// ✗ 間違い
public const minLength = 32;
public const DefaultTimeout = 2.5;
```

### インターフェース名

**Interfaceサフィックス**:

```php
// ✓ 正しい
interface SessionIdGeneratorInterface {}
interface ReadHookInterface {}
interface WriteFilterInterface {}

// ✗ 間違い
interface SessionIdGenerator {} // サフィックスなし
interface IReadHook {}          // I プレフィックス
```

### 例外クラス名

**Exceptionサフィックス**:

```php
// ✓ 正しい
class ConnectionException extends Exception {}
class ConfigurationException extends Exception {}

// ✗ 間違い
class ConnectionError extends Exception {}
class ConfigurationFailed extends Exception {}
```

## ファイル構造

### ディレクトリ構造

```
src/
├── Config/              設定クラス
├── Exception/           カスタム例外
├── Hook/                フック・フィルター
├── Serializer/          シリアライザ
├── Session/             セッション関連ユーティリティ
├── SessionId/           セッションIDジェネレータ
├── Support/             サポートユーティリティ
├── RedisConnection.php  接続管理
├── RedisSessionHandler.php セッションハンドラ
└── SessionHandlerFactory.php ファクトリー
```

**原則**:
- **1ファイル1クラス** - ファイル名とクラス名を一致させる
- **機能ごとにディレクトリ分割** - 関連するクラスをグループ化
- **浅い階層** - ネストは2階層まで（例: `Hook/`、`Serializer/`）

### ファイルテンプレート

```php
<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SubNamespace;

use OtherNamespace\SomeClass;
use AnotherNamespace\AnotherClass;

/**
 * クラスの説明
 *
 * より詳細な説明が必要な場合はここに記述
 */
class ClassName
{
    // プロパティ
    private string $property;

    // コンストラクタ
    public function __construct(string $property)
    {
        $this->property = $property;
    }

    // パブリックメソッド
    public function publicMethod(): void
    {
        // ...
    }

    // プライベートメソッド
    private function privateMethod(): void
    {
        // ...
    }
}
```

## PHPDoc

### 必須のPHPDoc

```php
/**
 * 配列の型が複雑な場合は必須
 *
 * @param array<string, mixed> $data
 * @return array<int, string>
 */
public function process(array $data): array
{
    // ...
}

/**
 * ジェネリック型
 *
 * @var array<ReadHookInterface>
 */
private array $readHooks = [];

/**
 * mixed型の場合は詳細を記述
 *
 * @param mixed $id Session ID (must be string)
 * @return string|false
 */
public function read($id)
{
    assert(is_string($id));
    // ...
}
```

### 省略可能なPHPDoc

型宣言で十分な場合は省略可能：

```php
// PHPDocなしでOK（型が明確）
public function connect(): bool
{
    return true;
}

// PHPDocなしでOK
private string $name;
private int $count;
```

### クラスレベルのPHPDoc

```php
/**
 * Redis session handler implementation
 *
 * このクラスはSessionHandlerInterfaceとSessionUpdateTimestampHandlerInterfaceを実装し、
 * Redis/ValKeyをバックエンドストレージとして使用します。
 *
 * Example:
 * ```php
 * $handler = new RedisSessionHandler($connection, $serializer, $options);
 * session_set_save_handler($handler, true);
 * session_start();
 * ```
 */
class RedisSessionHandler implements SessionHandlerInterface
{
    // ...
}
```

## コーディングベストプラクティス

### 1. 早期リターン

```php
// ✓ 良い例
public function process(string $input): bool
{
    if ($input === '') {
        return false;
    }

    if (!$this->validate($input)) {
        return false;
    }

    // メインロジック
    return true;
}

// ✗ 悪い例
public function process(string $input): bool
{
    if ($input !== '') {
        if ($this->validate($input)) {
            // メインロジック
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
}
```

### 2. ガード節の活用

```php
// ✓ 良い例
public function connect(): bool
{
    if ($this->connected) {
        return true; // 既に接続済み
    }

    // 接続処理
}

// ✗ 悪い例
public function connect(): bool
{
    if (!$this->connected) {
        // 接続処理
    } else {
        return true;
    }
}
```

### 3. 明示的な比較

```php
// ✓ 良い例
if ($result === false) { }
if ($count === 0) { }
if ($value !== null) { }

// ✗ 悪い例
if (!$result) { }
if (!$count) { }
if ($value) { }
```

### 4. Null合体演算子の活用

```php
// ✓ 良い例
$value = $config->getValue() ?? 'default';
$logger = $options->getLogger() ?? new NullLogger();

// ✗ 悪い例
$value = isset($config->getValue()) ? $config->getValue() : 'default';
```

### 5. 型安全なコレクション

```php
// ✓ 良い例：PHPDocで型を明示
/** @var array<ReadHookInterface> */
private array $readHooks = [];

public function addReadHook(ReadHookInterface $hook): void
{
    $this->readHooks[] = $hook;
}

// ✗ 悪い例：型が不明
private array $hooks = []; // 何が入るかわからない
```

### 6. 例外の適切な使用

```php
// ✓ 良い例：設定エラーは例外
if ($ttl <= 0) {
    throw new InvalidArgumentException('TTL must be positive');
}

// ✓ 良い例：操作失敗は false を返す
public function get(string $key)
{
    try {
        return $this->redis->get($key);
    } catch (RedisException $e) {
        $this->logger->error('GET failed', ['error' => $e]);
        return false;
    }
}

// ✗ 悪い例：操作失敗で例外
public function get(string $key): string
{
    $value = $this->redis->get($key);
    if ($value === false) {
        throw new RuntimeException('Key not found'); // NG
    }
    return $value;
}
```

## セキュリティプラクティス

### 1. セッションIDのマスキング

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

// ✓ 正しい
$this->logger->debug('Session operation', [
    'session_id' => SessionIdMasker::mask($id),
]);

// ✗ 間違い（セキュリティリスク）
$this->logger->debug('Session operation', [
    'session_id' => $id, // 生のセッションID
]);
```

### 2. パスワードのログ出力防止

```php
// ✓ 正しい
$this->logger->warning('Connection failed', [
    'host' => $host,
    'port' => $port,
    // パスワードは含めない
]);

// ✗ 間違い
$this->logger->warning('Connection failed', [
    'host' => $host,
    'password' => $password, // NG
]);
```

### 3. 入力検証

```php
// ✓ 正しい
public function __construct(int $port)
{
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Port must be between 1 and 65535'
        );
    }
    $this->port = $port;
}

// ✗ 間違い（検証なし）
public function __construct(int $port)
{
    $this->port = $port; // 不正な値を受け付ける可能性
}
```

## パフォーマンスプラクティス

### 1. 遅延初期化

```php
// ✓ 良い例
public function connect(): bool
{
    if ($this->connected) {
        return true; // 既に接続済み
    }
    // 接続処理
}

// ✗ 悪い例
public function __construct()
{
    $this->connect(); // コンストラクタで接続（不要な場合も）
}
```

### 2. 不要なループを避ける

```php
// ✓ 良い例
foreach ($this->writeFilters as $filter) {
    if (!$filter->shouldWrite($id, $data)) {
        return true; // 早期終了
    }
}

// ✗ 悪い例
$shouldWrite = true;
foreach ($this->writeFilters as $filter) {
    if (!$filter->shouldWrite($id, $data)) {
        $shouldWrite = false; // 継続してしまう
    }
}
return $shouldWrite;
```

## テストコードのスタイル

### テストメソッド名

**test + 説明的な名前**:

```php
// ✓ 良い例
public function testConnectRetriesOnFailure(): void {}
public function testWriteCallsBeforeWriteHooks(): void {}
public function testEmptyDataReturnsTrue(): void {}

// ✗ 悪い例
public function test1(): void {}
public function testConnection(): void {} // 曖昧
```

### アサーションの選択

```php
// ✓ 良い例：具体的なアサーション
$this->assertTrue($result);
$this->assertEquals('expected', $actual);
$this->assertInstanceOf(SomeClass::class, $object);

// △ 許容される例
$this->assertSame('expected', $actual); // より厳密

// ✗ 悪い例
$this->assertTrue($result === true); // 冗長
```

## コミット前チェックリスト

### 必須チェック

```bash
# 1. PHPStan（静的解析）
composer phpstan
# エラーがないことを確認

# 2. PHP CS Fixer（コードスタイル）
composer cs-check
# 問題があれば自動修正: composer cs-fix

# 3. テスト
composer test
# 全テストがパスすることを確認

# まとめて実行
composer check
```

### 推奨チェック

```bash
# カバレッジ確認
composer coverage

# 特定ファイルのPHPStan
vendor/bin/phpstan analyse src/NewClass.php

# 特定テストの実行
vendor/bin/phpunit tests/NewClassTest.php
```

## エディタ設定

### VS Code

`.vscode/settings.json`:

```json
{
    "php.validate.executablePath": "/usr/bin/php",
    "php.suggest.basic": false,
    "[php]": {
        "editor.tabSize": 4,
        "editor.insertSpaces": true,
        "editor.formatOnSave": true
    },
    "files.eol": "\n",
    "files.encoding": "utf8"
}
```

### PhpStorm

設定 → PHP → Code Sniffer:
- PHP CS Fixer: `.php-cs-fixer.php`
- PHPStan: `phpstan.neon`

## まとめ

コーディング規約の要点：

1. **PSR-12準拠**: 標準的なPHPコーディングスタイル
2. **PHPStan最大レベル**: 厳格な型チェック
3. **明確な命名規則**: PascalCase、camelCase、UPPER_SNAKE_CASE
4. **セキュリティ**: セッションIDマスキング、入力検証
5. **パフォーマンス**: 遅延初期化、早期リターン
6. **コミット前チェック**: phpstan + cs-check + test

## 関連ドキュメント

- [testing.md](testing.md) - テスト戦略と実行方法
- [contributing.md](contributing.md) - コントリビューションガイド
- [architecture.md](architecture.md) - システムアーキテクチャ
