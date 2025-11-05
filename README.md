# enhanced-redis-session-handler.php

PHPのセッション管理をRedis/ValKeyで実装する拡張可能なセッションハンドラライブラリ

## 概要

enhanced-redis-session-handler.phpは、PHPの標準セッションハンドラインターフェース（`SessionHandlerInterface`）を実装し、Redis/ValKeyをバックエンドストレージとして使用するライブラリです。標準的なRedisセッションハンドラに加えて、プラグイン機構とフック機能を提供することで、高いカスタマイズ性と拡張性を実現しています。

## 主な特徴

- **SessionHandlerInterface準拠**: PHPの標準セッションハンドラインターフェースを完全実装
- **プラグイン可能なセッションIDジェネレータ**: セッションID生成ロジックをカスタマイズ可能
- **フック機構**: セッションの読み込み・書き込み時に任意の処理を挿入可能
- **Redis/ValKey対応**: ext-redisを使用した高速なセッションストレージ
- **拡張性**: 新しい機能を容易に追加できる設計
- **水平スケーリング対応**: 複数のWebサーバーでセッションを共有可能
- **空セッション最適化**: 空のセッションのCookie送信とRedis書き込みを防止してパフォーマンス向上

## 対象ユーザー

- 水平スケーリングが必要なPHPアプリケーション開発者
- セッション管理をカスタマイズしたい開発者
- 高可用性が求められるWebサービスの運用者

## 必要な環境

- **PHP**: 7.4以上
- **ext-redis**: 5.0以上
- **Redis**: 5.0以上（公式サポート）
- **ValKey**: 7.2.5以上（テストはValKey 9.0.0で実施）

詳細な互換性情報については、[doc/redis-integration.md](doc/redis-integration.md)を参照してください。

## インストール

```bash
composer require uzulla/enhanced-redis-session-handler
```

## クイックスタート

### 基本的な使い方

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Psr\Log\NullLogger;

// 設定を作成
$config = new SessionConfig(
    new RedisConnectionConfig(),
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

### カスタム設定

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
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
    new DefaultSessionIdGenerator(),
    7200,
    new NullLogger()
);

// ファクトリーでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

詳細な使用方法については、[doc/factory-usage.md](doc/factory-usage.md)を参照してください。

## 空セッション最適化機能

### 概要

PreventEmptySessionCookie機能は、空のセッション（データが設定されていないセッション）の場合に、Cookieの送信とRedisへの書き込みを防止します。これにより、以下のメリットが得られます：

- **パフォーマンス向上**: 不要なRedis書き込み操作を削減
- **トラフィック削減**: 不要なCookie送信を防止
- **リソース効率化**: 匿名ユーザーやゲストユーザーが多いアプリケーションでの負荷軽減

### 使用方法

既存のコードに対して最小限の変更で利用できます。`PreventEmptySessionCookie::setup()`を呼び出すだけです：

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;
use Psr\Log\NullLogger;

// 設定を作成
$config = new SessionConfig(
    new RedisConnectionConfig(),
    new DefaultSessionIdGenerator(),
    (int)ini_get('session.gc_maxlifetime'),
    new NullLogger()
);

// ファクトリーでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

// PreventEmptySessionCookie機能を有効化（この1行を追加するだけ）
PreventEmptySessionCookie::setup($handler, new NullLogger());

// 通常通りセッションを開始
session_start();

// $_SESSIONを通常通り使用
// データが設定されない場合、自動的にCookieが削除されます
```

### 動作の仕組み

1. **EmptySessionFilterの登録**: セッションデータが空かどうかを検出するフィルターを登録
2. **Shutdown関数の登録**: 新規セッション（Cookie未存在）の場合、リクエスト終了時に実行される関数を登録
3. **空セッションの検出**: セッション終了時に`$_SESSION`が空の場合を検出
4. **自動クリーンアップ**: 空の場合、`session_destroy()`を呼び出してRedis書き込みを防止し、Set-Cookieヘッダーで過去の日付を送信してCookieを削除

### 対応環境

- PHP 7.4以上
- 既存のセッションには影響しません（Cookie既存の場合は通常通り動作）
- 既存のコードへの変更は最小限（`setup()`の呼び出しのみ）

### 実用例

```php
<?php

use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
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
    prefix: 'myapp:session:'
);

// セッション設定
$config = new SessionConfig(
    $connectionConfig,
    new DefaultSessionIdGenerator(),
    3600,
    $logger
);

// ハンドラの作成と設定
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

// 空セッション最適化を有効化
PreventEmptySessionCookie::setup($handler, $logger);

// セッション開始
session_start();

// ビジネスロジック
// ログインしているユーザーのみセッションにデータを設定
if (isUserLoggedIn()) {
    $_SESSION['user_id'] = getUserId();
    $_SESSION['username'] = getUsername();
}
// ログインしていない場合、$_SESSIONは空のまま
// → 自動的にCookieが削除され、Redisへの書き込みも行われない
```

詳細な使用例については、[examples/06-empty-session-no-cookie.php](examples/06-empty-session-no-cookie.php)を参照してください。

## ドキュメント

詳細なドキュメントは`doc/`ディレクトリに用意されています：

- **[doc/factory-usage.md](doc/factory-usage.md)**: SessionHandlerFactory使用ガイド
  - ファクトリーパターンによる簡単なインスタンス作成
  - ビルダーパターンを使用した設定のカスタマイズ
  - 実用的な使用例とベストプラクティス

- **[doc/architecture.md](doc/architecture.md)**: システムアーキテクチャ設計書
  - プロジェクト概要と主要な特徴
  - アーキテクチャ概要とレイヤー構成
  - コアコンポーネント設計
  - データフローとクラス構成図
  - エラーハンドリング方針
  - パフォーマンスとセキュリティ考慮事項
  - 拡張ポイントとテスト戦略

- **[doc/specification.md](doc/specification.md)**: 機能仕様書
  - SessionHandlerInterface実装の詳細
  - セッションIDジェネレータ機能
  - 読み込み時フック機能
  - 書き込み時フック機能
  - エラーハンドリング仕様
  - 設定仕様とパフォーマンス仕様
  - セキュリティ仕様とテスト仕様
  - 実装例とコードサンプル

- **[doc/redis-integration.md](doc/redis-integration.md)**: Redis/ValKey統合仕様
  - ext-redisの使用方法
  - キー命名規則
  - TTL（Time To Live）管理
  - 接続管理とプーリング
  - Redis操作の実装
  - ValKey対応
  - パフォーマンス最適化
  - セキュリティと監視

## 開発環境のセットアップ

開発環境のセットアップ方法については、[DEVELOPMENT.md](DEVELOPMENT.md)を参照してください。

### Docker環境（推奨）

Dockerを使用すると、PHP 7.4、Apache、ValKeyを含む完全な開発環境を簡単に構築できます：

```bash
# 環境を起動
docker compose -f docker/docker-compose.yml up -d

# ヘルスチェックを実行
./docker/healthcheck.sh

# コンテナに入る
docker compose -f docker/docker-compose.yml exec app bash
```

詳細は[DEVELOPMENT.md](DEVELOPMENT.md)を参照してください。

## ライセンス

MIT License

Copyright (c) 2025 uzulla / Junichi Ishida

詳細は[LICENSE](LICENSE)ファイルを参照してください。

## 貢献

プルリクエストや Issue の報告を歓迎します。

## サポート

問題が発生した場合は、GitHubのIssueトラッカーで報告してください。

## 関連リンク

- [Redis公式サイト](https://redis.io/)
- [ValKey公式サイト](https://valkey.io/)
- [ext-redis GitHub](https://github.com/phpredis/phpredis)
- [PHP SessionHandlerInterface](https://www.php.net/manual/ja/class.sessionhandlerinterface.php)
