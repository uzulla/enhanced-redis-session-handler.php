# テスト戦略と実行方法

## 概要

enhanced-redis-session-handler.phpは、包括的なテスト戦略を採用しています。ユニットテスト、統合テスト、エンドツーエンド（E2E）テストの3層構造で、品質と信頼性を確保しています。

## テストフレームワーク

- **PHPUnit**: 9.6+
- **カバレッジ**: Xdebug または PCOV
- **モック**: PHPUnit標準のモック機能

## テスト実行コマンド

### 基本的なテスト実行

```bash
# 全テストを実行
composer test

# PHPUnitを直接実行
vendor/bin/phpunit

# 特定のテストファイルを実行
vendor/bin/phpunit tests/RedisSessionHandlerTest.php

# 特定のテストメソッドを実行
vendor/bin/phpunit --filter testMethodName

# テストグループを実行
vendor/bin/phpunit --group integration
```

### カバレッジレポート

```bash
# カバレッジをテキスト形式で表示
composer coverage

# HTMLカバレッジレポートを生成（coverage/html/に出力）
composer coverage-report

# ブラウザでカバレッジを確認
open coverage/html/index.html
```

### 静的解析とコードスタイル

```bash
# PHPStan実行
composer phpstan

# コードスタイルチェック
composer cs-check

# コードスタイル自動修正
composer cs-fix

# 全てのlintチェック（PHPStan + コードスタイル）
composer lint

# 全てのチェック（lint + テスト）
composer check
```

## テストディレクトリ構造

```
tests/
├── Config/                     設定クラスのユニットテスト
│   ├── RedisConnectionConfigTest.php
│   ├── SessionConfigTest.php
│   └── RedisSessionHandlerOptionsTest.php
├── Hook/                       Hook/Filterのユニットテスト
│   ├── ReadHookTest.php
│   ├── WriteHookTest.php
│   ├── LoggingHookTest.php
│   ├── DoubleWriteHookTest.php
│   ├── FallbackReadHookTest.php
│   ├── ReadTimestampHookTest.php
│   └── EmptySessionFilterTest.php
├── Serializer/                 Serializerのユニットテスト
│   ├── PhpSerializerTest.php
│   └── PhpSerializeSerializerTest.php
├── SessionId/                  SessionIdGeneratorのユニットテスト
│   ├── SessionIdGeneratorTest.php
│   ├── PrefixedSessionIdGeneratorTest.php
│   └── TimestampPrefixedSessionIdGeneratorTest.php
├── Session/                    Sessionユーティリティのテスト
│   └── PreventEmptySessionCookieTest.php
├── Integration/                統合テスト
│   ├── BasicSessionTest.php
│   ├── ErrorHandlingIntegrationTest.php
│   ├── ReadHookIntegrationTest.php
│   ├── WriteHookIntegrationTest.php
│   ├── SessionIdGeneratorIntegrationTest.php
│   ├── SessionSerializeHandlerTest.php
│   └── PreventEmptySessionCookieIntegrationTest.php
├── E2E/                        エンドツーエンドテスト
│   └── ExamplesTest.php
├── Support/                    テストサポートクラス
│   └── TestRedisFactory.php
├── RedisSessionHandlerTest.php メインハンドラのユニットテスト
├── RedisConnectionTest.php     接続管理のユニットテスト
├── SessionHandlerFactoryTest.php ファクトリーのテスト
├── RetryTest.php               リトライロジックのテスト
├── LoggerAwareTest.php         ロガー設定のテスト
└── DummyTest.php               CI環境確認用
```

## テスト戦略

### 1. ユニットテスト

**目的**: 個々のクラス・メソッドの動作を検証

**対象**:
- 設定クラス（Config/）
- Hook/Filter実装
- Serializer実装
- SessionIdGenerator実装
- RedisSessionHandler
- RedisConnection

**特徴**:
- モックを使用してRedis接続を模擬
- 高速に実行可能
- 外部依存なし

**例**: `tests/RedisSessionHandlerTest.php`

```php
public function testWriteCallsBeforeWriteHooks(): void
{
    $hook = $this->createMock(WriteHookInterface::class);
    $hook->expects($this->once())
        ->method('beforeWrite')
        ->willReturnCallback(function ($id, $data) {
            $data['hooked'] = true;
            return $data;
        });

    $handler->addWriteHook($hook);
    $handler->write('session_id', serialize(['key' => 'value']));
}
```

### 2. 統合テスト

**目的**: 複数コンポーネント間の連携を検証

**対象**:
- セッションハンドラとRedisの連携
- Hook/Filterの実際の動作
- Serializerの互換性
- エラーハンドリング

**特徴**:
- 実際のRedis接続を使用
- Docker環境でValKeyを使用
- 環境変数で接続先を設定可能

**例**: `tests/Integration/BasicSessionTest.php`

```php
public function testSessionDataPersistsAcrossRequests(): void
{
    $handler = $this->createSessionHandler();

    // 1st request: write data
    $handler->open('', '');
    $sessionId = $handler->create_sid();
    $handler->write($sessionId, serialize(['user_id' => 123]));
    $handler->close();

    // 2nd request: read data
    $handler->open('', '');
    $data = $handler->read($sessionId);
    $this->assertEquals(['user_id' => 123], unserialize($data));
    $handler->close();
}
```

**環境変数**:
```bash
# phpunit.xml または環境変数で設定
SESSION_REDIS_HOST=localhost
SESSION_REDIS_PORT=6379
```

### 3. エンドツーエンド（E2E）テスト

**目的**: 実際の使用シナリオを検証

**対象**:
- `examples/`ディレクトリのサンプルコード
- 実際のPHPセッション拡張との連携

**特徴**:
- `session_start()`等の実際のPHP関数を使用
- 最も実環境に近いテスト

**例**: `tests/E2E/ExamplesTest.php`

```php
public function testBasicExampleWorks(): void
{
    $output = shell_exec('php examples/basic_usage.php');
    $this->assertStringContainsString('Session started successfully', $output);
}
```

## テスト設定

### phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         verbose="true"
         failOnWarning="true"
         failOnRisky="false"
         beStrictAboutOutputDuringTests="false"
         beStrictAboutTodoAnnotatedTests="true"
         convertDeprecationsToExceptions="false">
    <testsuites>
        <testsuite name="Unit Tests">
            <directory>tests</directory>
        </testsuite>
    </testsuites>

    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <report>
            <html outputDirectory="coverage/html"/>
            <text outputFile="php://stdout" showUncoveredFiles="true"/>
        </report>
    </coverage>

    <php>
        <ini name="display_errors" value="1"/>
        <ini name="error_reporting" value="-1"/>
        <ini name="memory_limit" value="-1"/>
        <ini name="session.serialize_handler" value="php_serialize"/>
        <env name="SESSION_REDIS_HOST" value="localhost"/>
        <env name="SESSION_REDIS_PORT" value="6379"/>
    </php>
</phpunit>
```

**重要な設定**:

1. **`failOnWarning="true"`**:
   - 警告でもテスト失敗とする
   - 品質基準を高く保つ

2. **`session.serialize_handler="php_serialize"`**:
   - テストでは`php_serialize`を使用
   - `PhpSerializeSerializer`との整合性

3. **`convertDeprecationsToExceptions="false"`**:
   - 非推奨警告を例外としない
   - PHP 8.xとの互換性維持

## テストカバレッジ目標

### 目標値

- **全体カバレッジ**: 80%以上
- **コアクラス**: 90%以上
  - RedisSessionHandler
  - RedisConnection
  - Serializer
  - Hook/Filter

### カバレッジ確認

```bash
# テキスト形式でカバレッジを表示
composer coverage

# 出力例:
# Code Coverage Report:
#   2025-11-02 12:00:00
#
# Summary:
#   Classes: 95.00% (19/20)
#   Methods: 92.50% (148/160)
#   Lines:   88.75% (1420/1600)
```

### カバレッジレポートの確認

```bash
# HTMLレポート生成
composer coverage-report

# ブラウザで開く
open coverage/html/index.html
```

**HTMLレポートの見方**:
- 緑: カバーされたコード
- 赤: カバーされていないコード
- クラス・メソッド単位で確認可能

## モックとスタブ

### Redis接続のモック

```php
public function testConnectSuccess(): void
{
    $redis = $this->createMock(Redis::class);
    $redis->expects($this->once())
        ->method('connect')
        ->with('localhost', 6379)
        ->willReturn(true);

    $connection = new RedisConnection($redis, $config, $logger);
    $result = $connection->connect();

    $this->assertTrue($result);
}
```

### Loggerのモック

```php
public function testLogsError(): void
{
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
        ->method('error')
        ->with(
            $this->equalTo('Operation failed'),
            $this->arrayHasKey('exception')
        );

    // テスト実行
}
```

### Hookのモック

```php
public function testHookReceivesCorrectData(): void
{
    $hook = $this->createMock(WriteHookInterface::class);
    $hook->expects($this->once())
        ->method('beforeWrite')
        ->with(
            $this->equalTo('session_id'),
            $this->equalTo(['key' => 'value'])
        )
        ->willReturn(['key' => 'modified']);

    $handler->addWriteHook($hook);
}
```

## テストデータの管理

### テストサポートクラス

`tests/Support/TestRedisFactory.php`:

```php
class TestRedisFactory
{
    public static function create(): Redis
    {
        $redis = new Redis();
        $host = $_ENV['SESSION_REDIS_HOST'] ?? 'localhost';
        $port = (int)($_ENV['SESSION_REDIS_PORT'] ?? 6379);

        $redis->connect($host, $port);
        return $redis;
    }

    public static function createConnection(): RedisConnection
    {
        $redis = self::create();
        $config = new RedisConnectionConfig(
            host: $_ENV['SESSION_REDIS_HOST'] ?? 'localhost',
            port: (int)($_ENV['SESSION_REDIS_PORT'] ?? 6379),
            prefix: 'test:session:'
        );

        return new RedisConnection($redis, $config, new NullLogger());
    }
}
```

### セットアップとティアダウン

```php
class MyTest extends TestCase
{
    private RedisConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = TestRedisFactory::createConnection();
        $this->connection->connect();
    }

    protected function tearDown(): void
    {
        // テストデータのクリーンアップ
        $keys = $this->connection->keys('*');
        foreach ($keys as $key) {
            $this->connection->delete($key);
        }

        $this->connection->disconnect();
        parent::tearDown();
    }
}
```

## CI/CD環境でのテスト

### GitHub Actions

`.github/workflows/test.yml` でのテスト実行：

```yaml
- name: Run PHPUnit Tests
  run: composer test

- name: Run PHPStan
  run: composer phpstan

- name: Check Code Style
  run: composer cs-check
```

### Docker環境

```bash
# Docker環境起動
docker compose -f docker/docker-compose.yml up -d

# コンテナ内でテスト実行
docker compose -f docker/docker-compose.yml exec app composer test

# 環境停止
docker compose -f docker/docker-compose.yml down
```

**Docker Compose設定**:
- PHP 7.4
- Apache
- ValKey 9.0.0（Redis互換）

## テストのベストプラクティス

### 1. テスト名は明確に

```php
// ✓ 良い例
public function testWriteCallsBeforeWriteHooksInOrder(): void

// ✗ 悪い例
public function testWrite(): void
```

### 2. Arrange-Act-Assert パターン

```php
public function testExample(): void
{
    // Arrange: テストデータを準備
    $handler = new RedisSessionHandler(/* ... */);
    $data = ['key' => 'value'];

    // Act: 操作を実行
    $result = $handler->write('id', serialize($data));

    // Assert: 結果を検証
    $this->assertTrue($result);
}
```

### 3. 1テスト1アサーション（原則）

```php
// ✓ 良い例：焦点が明確
public function testSessionIdIsGenerated(): void
{
    $id = $handler->create_sid();
    $this->assertNotEmpty($id);
}

public function testSessionIdHasCorrectLength(): void
{
    $id = $handler->create_sid();
    $this->assertEquals(32, strlen($id));
}

// △ 許容される例：関連する複数のアサーション
public function testSessionDataIsPersisted(): void
{
    $handler->write('id', 'data');
    $result = $handler->read('id');

    $this->assertNotFalse($result);
    $this->assertEquals('data', $result);
}
```

### 4. テストの独立性

```php
// ✓ 良い例：各テストが独立
public function testA(): void
{
    $handler = $this->createHandler(); // 新規作成
    // テスト
}

public function testB(): void
{
    $handler = $this->createHandler(); // 新規作成
    // テスト
}

// ✗ 悪い例：テスト間で状態を共有
private $sharedHandler; // NG
```

### 5. エッジケースのテスト

```php
public function testEmptyData(): void
{
    $result = $handler->write('id', '');
    $this->assertTrue($result);
}

public function testVeryLongSessionId(): void
{
    $longId = str_repeat('a', 1000);
    $result = $handler->write($longId, 'data');
    $this->assertTrue($result);
}

public function testNegativeTTL(): void
{
    $this->expectException(InvalidArgumentException::class);
    $connection->set('key', 'value', -1);
}
```

## デバッグ

### テスト失敗時のデバッグ

```bash
# 詳細出力でテスト実行
vendor/bin/phpunit --verbose

# 特定のテストのみ実行
vendor/bin/phpunit --filter testMethodName

# エラー時に停止
vendor/bin/phpunit --stop-on-failure

# 警告も表示
vendor/bin/phpunit --display-warnings
```

### var_dump デバッグ

```php
public function testDebug(): void
{
    $data = $handler->read('id');
    var_dump($data); // 出力される
    $this->assertTrue(true);
}
```

**注意**: `beStrictAboutOutputDuringTests="false"`に設定されているため、出力可能。

## トラブルシューティング

### Redis接続エラー

**症状**: `ConnectionException: Failed to connect to Redis`

**解決策**:
```bash
# Redisが起動しているか確認
docker compose -f docker/docker-compose.yml ps

# 環境変数を確認
echo $SESSION_REDIS_HOST
echo $SESSION_REDIS_PORT

# Dockerログを確認
docker compose -f docker/docker-compose.yml logs valkey
```

### カバレッジが生成されない

**症状**: `composer coverage`でエラー

**解決策**:
```bash
# Xdebugまたはpcovがインストールされているか確認
php -m | grep -E 'xdebug|pcov'

# Xdebugのインストール（Dockerコンテナ内）
pecl install xdebug
echo "zend_extension=xdebug.so" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
```

### メモリ不足

**症状**: `Allowed memory size exhausted`

**解決策**:
```bash
# メモリ制限を解除してテスト実行
php -d memory_limit=-1 vendor/bin/phpunit
```

## まとめ

テスト戦略の要点：

1. **3層構造**: ユニット、統合、E2Eテストで包括的にカバー
2. **高いカバレッジ**: 80%以上を目標、コアクラスは90%以上
3. **CI/CD統合**: GitHub Actionsで自動テスト実行
4. **Docker環境**: 再現可能なテスト環境
5. **ベストプラクティス**: 明確なテスト名、Arrange-Act-Assert、独立性

## 関連ドキュメント

- [code-style.md](code-style.md) - コーディング規約
- [contributing.md](contributing.md) - コントリビューションガイド
- [implementation/session-handler.md](implementation/session-handler.md) - RedisSessionHandler実装
