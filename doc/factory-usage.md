# SessionHandlerFactory 使用ガイド

## 概要

`SessionHandlerFactory`は、`RedisSessionHandler`のインスタンスを作成するためのファクトリークラスです。`SessionConfig`を受け取り、設定に基づいてハンドラを生成します。

## 基本的な使い方

### デフォルト設定でのインスタンス作成

最もシンプルな使い方は、デフォルト設定でハンドラを作成することです：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Psr\Log\NullLogger;

// 設定を作成
$config = new SessionConfig(
    new RedisConnectionConfig(),
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    (int)ini_get('session.gc_maxlifetime'),
    new NullLogger()
);

// ファクトリーでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

// セッションハンドラとして登録
session_set_save_handler($handler, true);
session_start();
```

デフォルトのRedis接続設定：
- ホスト: `localhost`
- ポート: `6379`
- タイムアウト: `2.5`秒
- データベース: `0`
- プレフィックス: `session:`

### カスタム設定でのインスタンス作成

各種設定をカスタマイズする場合は、`RedisConnectionConfig`と`SessionConfig`のコンストラクタで指定します：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Psr\Log\NullLogger;

// Redis接続設定を作成
$connectionConfig = new RedisConnectionConfig(
    host: 'redis.example.com',
    port: 6380,
    timeout: 2.5,
    password: 'secret',
    database: 2,
    prefix: 'myapp:session:',
    persistent: false,
    retryInterval: 100,
    readTimeout: 2.5,
    maxRetries: 3
);

// セッション設定を作成
$config = new SessionConfig(
    $connectionConfig,
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    7200,  // 最大ライフタイム（秒）
    new NullLogger()
);

// ファクトリーでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

## 設定パラメータ

### RedisConnectionConfig

Redis接続に関する設定を行います。コンストラクタのパラメータ：

- `host` (string): Redisサーバーのホスト名またはIPアドレス（デフォルト: `'localhost'`）
- `port` (int): Redisサーバーのポート番号（デフォルト: `6379`）
- `timeout` (float): 接続タイムアウト時間（秒）（デフォルト: `2.5`）
- `password` (?string): Redis認証用のパスワード（デフォルト: `null`）
- `database` (int): 使用するRedisデータベース番号（デフォルト: `0`）
- `prefix` (string): セッションキーのプレフィックス（デフォルト: `'session:'`）
- `persistent` (bool): 永続的接続を使用するか（デフォルト: `false`）
- `retryInterval` (int): リトライ間隔（ミリ秒）（デフォルト: `100`）
- `readTimeout` (float): 読み取りタイムアウト時間（秒）（デフォルト: `2.5`）
- `maxRetries` (int): 接続失敗時の最大リトライ回数（デフォルト: `3`）

### SessionConfig

セッションハンドラの設定を行います。コンストラクタのパラメータ：

- `connectionConfig` (RedisConnectionConfig): Redis接続設定
- `idGenerator` (SessionIdGeneratorInterface): セッションIDジェネレータ
- `maxLifetime` (int): セッションの最大ライフタイム（秒）
- `logger` (LoggerInterface): PSR-3準拠のロガー

### フック設定

`SessionConfig`には、フックを追加するためのメソッドがあります：

- `addReadHook(ReadHookInterface $hook)`: 読み込み時のフックを追加
- `addWriteHook(WriteHookInterface $hook)`: 書き込み時のフックを追加
- `addWriteFilter(WriteFilterInterface $filter)`: 書き込みフィルターを追加

## 実用例

### 本番環境での設定例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// ロガーの設定
$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('/var/log/session.log', Logger::WARNING));

// Redis接続設定
$connectionConfig = new RedisConnectionConfig(
    host: getenv('REDIS_HOST') ?: 'localhost',
    port: (int)(getenv('REDIS_PORT') ?: 6379),
    timeout: 2.5,
    password: getenv('REDIS_PASSWORD') ?: null,
    database: 1,
    prefix: 'prod:session:',
    persistent: true,
    retryInterval: 100,
    readTimeout: 2.5,
    maxRetries: 5
);

// セッション設定
$config = new SessionConfig(
    $connectionConfig,
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    3600,
    $logger
);

// フックを追加
$config->addReadHook(new ReadTimestampHook());
$config->addWriteHook(new LoggingHook($logger));

// ハンドラの作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

### 開発環境での設定例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// 詳細なログ出力
$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Redis接続設定
$connectionConfig = new RedisConnectionConfig(
    host: 'localhost',
    port: 6379,
    timeout: 2.5,
    password: null,
    database: 0,
    prefix: 'dev:session:',
    persistent: false,
    retryInterval: 100,
    readTimeout: 2.5,
    maxRetries: 3
);

// セッション設定
$config = new SessionConfig(
    $connectionConfig,
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    86400,  // 24時間
    $logger
);

// ハンドラの作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

### 複数のフックを使用する例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\FallbackReadHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Psr\Log\NullLogger;

// Redis接続設定
$connectionConfig = new RedisConnectionConfig(
    host: 'redis-primary.example.com',
    port: 6379,
    timeout: 2.5,
    password: null,
    database: 0,
    prefix: 'session:',
    persistent: false,
    retryInterval: 100,
    readTimeout: 2.5,
    maxRetries: 3
);

// セッション設定
$config = new SessionConfig(
    $connectionConfig,
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    3600,
    new NullLogger()
);

// 複数のフックを追加
$config->addReadHook(new ReadTimestampHook());
$config->addReadHook(new FallbackReadHook($fallbackConnection));
$config->addWriteHook(new LoggingHook($logger));
$config->addWriteHook(new DoubleWriteHook($secondaryConnection));

// ハンドラの作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

### 空セッション最適化を使用する例

PreventEmptySessionCookie機能とファクトリーパターンを組み合わせることで、空のセッションによる不要なRedis書き込みとCookie送信を防止できます：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// ロガーの設定
$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('/var/log/session.log', Logger::INFO));

// Redis接続設定
$connectionConfig = new RedisConnectionConfig(
    host: getenv('REDIS_HOST') ?: 'localhost',
    port: (int)(getenv('REDIS_PORT') ?: 6379),
    timeout: 2.5,
    password: getenv('REDIS_PASSWORD') ?: null,
    database: 1,
    prefix: 'app:session:',
    persistent: true,
    retryInterval: 100,
    readTimeout: 2.5,
    maxRetries: 5
);

// セッション設定
$config = new SessionConfig(
    $connectionConfig,
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    3600,
    $logger
);

// ハンドラの作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

// PreventEmptySessionCookie機能を有効化
// これにより、$_SESSIONが空の場合はCookieが送信されず、Redisへの書き込みも行われません
PreventEmptySessionCookie::setup($handler, $logger);

// セッション開始
session_start();

// ビジネスロジック
// ログインユーザーのみセッションにデータを設定
if (isset($_POST['login'])) {
    $_SESSION['user_id'] = authenticateUser($_POST['username'], $_POST['password']);
    $_SESSION['login_time'] = time();
}

// ゲストユーザーの場合、$_SESSIONは空のまま
// → 自動的にCookieが削除され、Redisへの書き込みも行われない
```

**この機能の利点：**

1. **パフォーマンス向上**: 匿名ユーザーやゲストユーザーが多いアプリケーションで、不要なRedis書き込みを削減
2. **コスト削減**: Redisへのアクセス回数が減り、インフラコストを削減
3. **最小限の変更**: 既存コードへの影響は最小限（`PreventEmptySessionCookie::setup()`の呼び出しのみ）
4. **互換性**: 既存のセッション（Cookie既存）には影響せず、通常通り動作

**使用例：**

- ECサイトでログイン前のブラウジング時にセッションを作成しない
- ニュースサイトで未ログインユーザーのページ閲覧時にセッションを作成しない
- APIサーバーで認証されていないリクエストにセッションを作成しない

詳細な動作例については、[examples/06-empty-session-no-cookie.php](../examples/06-empty-session-no-cookie.php)を参照してください。

## 設定の変更

設定を後から変更する必要がある場合は、`SessionConfig`のセッターメソッドを使用できます：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SecureSessionIdGenerator;
use Psr\Log\NullLogger;

// 初期設定
$config = new SessionConfig(
    new RedisConnectionConfig(),
    new PhpSerializeSerializer(),
    new DefaultSessionIdGenerator(),
    3600,
    new NullLogger()
);

// 設定を変更
$config->setMaxLifetime(7200);
$config->setIdGenerator(new SecureSessionIdGenerator());
$config->setLogger($customLogger);

// 新しい接続設定に変更
$newConnectionConfig = new RedisConnectionConfig(
    host: 'redis.example.com',
    port: 6380
);
$config->setConnectionConfig($newConnectionConfig);

// ハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();
```

## ユーザーセッション管理の使用

`UserSessionIdGenerator`と`UserSessionHelper`を使用して、ユーザー単位のセッション管理を実現できます：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\UserSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\UserSessionHelper;
use Psr\Log\NullLogger;

// 1. UserSessionIdGeneratorを作成
$generator = new UserSessionIdGenerator();

// 2. SessionConfigにUserSessionIdGeneratorを設定
$connectionConfig = new RedisConnectionConfig();
$config = new SessionConfig(
    $connectionConfig,
    $generator,  // UserSessionIdGeneratorを指定
    3600,
    new NullLogger()
);

// 3. セッションハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

// 4. UserSessionHelperを作成
$helper = new UserSessionHelper(
    $generator,
    $handler->getConnection(),  // RedisConnectionを取得
    new NullLogger()
);

// 5. セッションハンドラを設定
session_set_save_handler($handler, true);

// === 匿名セッション（ログイン前） ===
session_start();
// セッションID: anon_abc123...

$_SESSION['cart_items'] = 2;
$_SESSION['visited_pages'] = ['home', 'products'];

// === ログイン処理 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 認証処理
    if (authenticate($_POST['username'], $_POST['password'])) {
        $userId = getUserIdByUsername($_POST['username']);

        // ユーザーIDを設定してセッションID再生成
        // （セッションフィクセーション攻撃対策）
        $helper->setUserIdAndRegenerate($userId);
        // セッションID: user123_def456...

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $_POST['username'];

        // セッションデータは自動的に新しいセッションIDに移行される
        // $_SESSION['cart_items'] と $_SESSION['visited_pages'] は保持される

        header('Location: /dashboard');
        exit;
    }
}
```

### 管理機能の使用例

```php
<?php

// 管理者ページでの使用例

// 1. 特定ユーザーのアクティブセッション数を確認
$userId = '123';
$sessionCount = $helper->countUserSessions($userId);
echo "アクティブセッション数: {$sessionCount}\n";

// 2. アクティブセッション一覧を取得
$sessions = $helper->getUserSessions($userId);
foreach ($sessions as $sessionKey => $info) {
    echo "セッションID: {$info['session_id']}\n";  // マスキング済み
    echo "データサイズ: {$info['data_size']} bytes\n";
}

// 3. 特定ユーザーの全セッションを強制ログアウト
// （セキュリティインシデント発生時、パスワード変更時など）
if ($currentUser->isAdmin()) {
    $targetUserId = $_POST['target_user_id'];

    // 権限チェック（必須！）
    if (!canForceLogout($currentUser, $targetUserId)) {
        throw new UnauthorizedException();
    }

    // 全セッションを削除
    $deletedCount = $helper->forceLogoutUser($targetUserId);
    echo "削除されたセッション数: {$deletedCount}\n";
}
```

### ログアウト処理

```php
<?php

// 通常のログアウト
session_start();

// オプション1: セッション破棄
session_destroy();

// オプション2: ユーザーIDをクリアして匿名セッションに戻す
$generator->clearUserId();
session_regenerate_id(true);
// セッションID: anon_xxxxx に戻る
```

**重要な注意事項:**

1. **セッションフィクセーション対策**: ログイン成功時は必ず`setUserIdAndRegenerate()`を呼び出すこと
2. **権限チェック**: `forceLogoutUser()`呼び出し前に必ず権限チェックを実施すること
3. **セキュリティ**: 管理機能はアクセス制御を適切に実装すること

**詳細設計:** `doc/UserSessionIdGenerator/design.md` を参照

## まとめ

`SessionHandlerFactory`を使用することで：

1. **明示的な設定**: すべての設定パラメータがコンストラクタで明示的に指定されます
2. **型安全**: すべてのパラメータは型ヒントを持ち、IDEの補完が効きます
3. **シンプル**: 設定は一度作成され、ファクトリーに渡されます
4. **保守性**: 設定の変更が必要な場合は、セッターメソッドで変更できます

詳細な仕様については、以下のドキュメントも参照してください：

- [アーキテクチャ設計書](architecture.md)
- [機能仕様書](specification.md)
- [Redis統合仕様](redis-integration.md)
