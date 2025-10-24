# SessionHandlerFactory 使用ガイド

## 概要

`SessionHandlerFactory`は、`RedisSessionHandler`のインスタンスを簡単に作成するためのファクトリークラスです。ビルダーパターンを採用しており、流暢なインターフェース（Fluent Interface）で設定を行うことができます。

## 基本的な使い方

### デフォルト設定でのインスタンス作成

最もシンプルな使い方は、デフォルト設定でハンドラを作成することです：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

// デフォルト設定でハンドラを作成
$handler = SessionHandlerFactory::createDefault()->build();

// セッションハンドラとして登録
session_set_save_handler($handler, true);
session_start();
```

デフォルト設定：
- ホスト: `localhost`
- ポート: `6379`
- タイムアウト: `2.5`秒
- データベース: `0`
- プレフィックス: `session:`
- 最大ライフタイム: `ini_get('session.gc_maxlifetime')`の値

### カスタム設定でのインスタンス作成

ビルダーパターンを使用して、各種設定をカスタマイズできます：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

$handler = SessionHandlerFactory::createDefault()
    ->withHost('redis.example.com')
    ->withPort(6380)
    ->withPassword('secret')
    ->withDatabase(2)
    ->withPrefix('myapp:session:')
    ->withMaxLifetime(7200)
    ->build();

session_set_save_handler($handler, true);
session_start();
```

## 利用可能な設定メソッド

### Redis接続設定

#### withHost(string $host)
Redisサーバーのホスト名またはIPアドレスを設定します。

```php
$factory->withHost('redis.example.com');
```

#### withPort(int $port)
Redisサーバーのポート番号を設定します。

```php
$factory->withPort(6380);
```

#### withPassword(?string $password)
Redis認証用のパスワードを設定します。

```php
$factory->withPassword('secret');
```

#### withDatabase(int $database)
使用するRedisデータベース番号を設定します（0-15）。

```php
$factory->withDatabase(2);
```

#### withPrefix(string $prefix)
セッションキーのプレフィックスを設定します。

```php
$factory->withPrefix('myapp:session:');
```

#### withPersistent(bool $persistent)
永続的接続を使用するかどうかを設定します。

```php
$factory->withPersistent(true);
```

#### withTimeout(float $timeout)
接続タイムアウト時間（秒）を設定します。

```php
$factory->withTimeout(5.0);
```

#### withReadTimeout(float $readTimeout)
読み取りタイムアウト時間（秒）を設定します。

```php
$factory->withReadTimeout(5.0);
```

#### withMaxRetries(int $maxRetries)
接続失敗時の最大リトライ回数を設定します。

```php
$factory->withMaxRetries(5);
```

#### withRetryInterval(int $retryInterval)
リトライ間隔（ミリ秒）を設定します。

```php
$factory->withRetryInterval(200);
```

### セッション設定

#### withMaxLifetime(int $maxLifetime)
セッションの最大ライフタイム（秒）を設定します。

```php
$factory->withMaxLifetime(3600); // 1時間
```

#### withIdGenerator(SessionIdGeneratorInterface $generator)
カスタムセッションIDジェネレータを設定します。

```php
use Uzulla\EnhancedRedisSessionHandler\SessionId\SecureSessionIdGenerator;

$factory->withIdGenerator(new SecureSessionIdGenerator());
```

#### withLogger(LoggerInterface $logger)
PSR-3準拠のロガーを設定します。

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('/var/log/session.log'));

$factory->withLogger($logger);
```

### フック設定

#### withReadHook(ReadHookInterface $hook)
読み込み時のフックを追加します。

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;

$factory->withReadHook(new ReadTimestampHook());
```

#### withWriteHook(WriteHookInterface $hook)
書き込み時のフックを追加します。

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;

$logger = new Logger('session');
$factory->withWriteHook(new LoggingHook($logger));
```

#### withWriteFilter(WriteFilterInterface $filter)
書き込みフィルターを追加します。

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;

class EmptySessionFilter implements WriteFilterInterface
{
    public function shouldWrite(string $sessionId, array $data): bool
    {
        return !empty($data);
    }
}

$factory->withWriteFilter(new EmptySessionFilter());
```

## 実用例

### 本番環境での設定例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// ロガーの設定
$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('/var/log/session.log', Logger::WARNING));

// ハンドラの作成
$handler = SessionHandlerFactory::createDefault()
    ->withHost(getenv('REDIS_HOST') ?: 'localhost')
    ->withPort((int)(getenv('REDIS_PORT') ?: 6379))
    ->withPassword(getenv('REDIS_PASSWORD') ?: null)
    ->withDatabase(1)
    ->withPrefix('prod:session:')
    ->withMaxLifetime(3600)
    ->withPersistent(true)
    ->withMaxRetries(5)
    ->withLogger($logger)
    ->withReadHook(new ReadTimestampHook())
    ->withWriteHook(new LoggingHook($logger))
    ->build();

session_set_save_handler($handler, true);
session_start();
```

### 開発環境での設定例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// 詳細なログ出力
$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$handler = SessionHandlerFactory::createDefault()
    ->withHost('localhost')
    ->withPort(6379)
    ->withPrefix('dev:session:')
    ->withMaxLifetime(86400) // 24時間
    ->withLogger($logger)
    ->build();

session_set_save_handler($handler, true);
session_start();
```

### 複数のフックを使用する例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\FallbackReadHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;

$handler = SessionHandlerFactory::createDefault()
    ->withHost('redis-primary.example.com')
    ->withReadHook(new ReadTimestampHook())
    ->withReadHook(new FallbackReadHook($fallbackConnection))
    ->withWriteHook(new LoggingHook($logger))
    ->withWriteHook(new DoubleWriteHook($secondaryConnection))
    ->build();

session_set_save_handler($handler, true);
session_start();
```

## SessionConfigクラスとの併用

より高度な設定が必要な場合は、`SessionConfig`クラスを直接使用することもできます：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

// 接続設定を作成
$connectionConfig = new RedisConnectionConfig(
    host: 'redis.example.com',
    port: 6380,
    timeout: 5.0,
    password: 'secret',
    database: 2,
    prefix: 'myapp:',
    persistent: true
);

// セッション設定を作成
$sessionConfig = new SessionConfig(
    connectionConfig: $connectionConfig,
    maxLifetime: 7200
);

// フックを追加
$sessionConfig->addReadHook(new ReadTimestampHook());
$sessionConfig->addWriteHook(new LoggingHook($logger));

// ファクトリーでハンドラを作成
$handler = SessionHandlerFactory::create($sessionConfig)->build();

session_set_save_handler($handler, true);
session_start();
```

## まとめ

`SessionHandlerFactory`を使用することで：

1. **簡潔な記述**: ビルダーパターンにより、設定を流暢に記述できます
2. **型安全**: すべての設定メソッドは型ヒントを持ち、IDEの補完が効きます
3. **柔軟性**: デフォルト設定から始めて、必要な部分だけをカスタマイズできます
4. **保守性**: 設定の変更が容易で、コードの可読性が高まります

詳細な仕様については、以下のドキュメントも参照してください：

- [アーキテクチャ設計書](architecture.md)
- [機能仕様書](specification.md)
- [Redis統合仕様](redis-integration.md)
