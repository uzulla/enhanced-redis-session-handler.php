# ライブラリ利用者向けドキュメント

このディレクトリには、enhanced-redis-session-handler.phpライブラリを使用するアプリケーション開発者向けのドキュメントが含まれています。

## 目次

- **[factory-usage.md](factory-usage.md)** - SessionHandlerFactoryの使用方法
- **[redis-integration.md](redis-integration.md)** - Redis/ValKey統合の詳細

## クイックスタート

### インストール

```bash
composer require uzulla/enhanced-redis-session-handler
```

### 基本的な使い方

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

// 通常通り$_SESSIONを使用
$_SESSION['user_id'] = 123;
```

詳細は[factory-usage.md](factory-usage.md)を参照してください。

## 関連ドキュメント

### プラグイン開発者向け
Hook、Filter、Serializerなどのプラグインを作成したい場合は、[plugin-developers/](../plugin-developers/)ディレクトリを参照してください。

### 開発者向け
ライブラリ自体の開発に参加したい場合は、[developers/](../developers/)ディレクトリを参照してください。

## サポート

問題が発生した場合は、[GitHubのIssue](https://github.com/uzulla/enhanced-redis-session-handler.php/issues)で報告してください。
