# Examples / サンプルコード

このディレクトリには、enhanced-redis-session-handlerの実用的な使用例が含まれています。

This directory contains practical usage examples for enhanced-redis-session-handler.

## 前提条件 / Prerequisites

すべてのサンプルを実行する前に、以下を確認してください：

Before running any examples, ensure the following:

1. **Redisサーバーが起動していること / Redis server is running**
   ```bash
   # Redisサーバーの起動確認 / Check if Redis is running
   redis-cli ping
   # 期待される出力 / Expected output: PONG
   ```

2. **依存パッケージがインストールされていること / Dependencies are installed**
   ```bash
   composer install
   ```

3. **PHP 7.4以上がインストールされていること / PHP 7.4 or higher is installed**
   ```bash
   php --version
   ```

## サンプル一覧 / Examples List

### 01. 基本的な使用例 / Basic Usage
**ファイル / File:** `01-basic-usage.php`

最もシンプルな使用方法を示します。セッションハンドラの基本的な設定、データの読み書き、セッションの破棄を学べます。

Demonstrates the simplest way to use the library. Learn basic session handler configuration, reading/writing data, and session destruction.

**実行方法 / How to run:**
```bash
php examples/01-basic-usage.php
```

**学べること / What you'll learn:**
- Redis接続設定の作成 / Creating Redis connection configuration
- セッション設定の構築 / Building session configuration
- セッションハンドラの登録 / Registering session handler
- セッションデータの読み書き / Reading and writing session data
- セッションの破棄 / Destroying sessions

---

### 02. カスタムセッションIDジェネレータ / Custom Session ID Generator
**ファイル / File:** `02-custom-session-id.php`

カスタムセッションIDジェネレータの使用方法を示します。プレフィックス付きIDやタイムスタンプ付きIDの生成方法を学べます。

Demonstrates how to use custom session ID generators. Learn how to generate prefixed IDs and timestamp-prefixed IDs.

**実行方法 / How to run:**
```bash
php examples/02-custom-session-id.php
```

**学べること / What you'll learn:**
- `PrefixedSessionIdGenerator`の使用 / Using PrefixedSessionIdGenerator
- `TimestampPrefixedSessionIdGenerator`の使用 / Using TimestampPrefixedSessionIdGenerator
- 複数アプリケーションでの名前空間分離 / Namespace separation for multiple applications
- セッションIDのカスタマイズ / Customizing session IDs

**用途 / Use Cases:**
- 複数のアプリケーションが同じRedisインスタンスを共有する場合
- セッションIDの識別を容易にする場合
- デバッグやログ分析の簡素化

---

### 03. ダブルライトフック / Double Write Hook
**ファイル / File:** `03-double-write.php`

プライマリとセカンダリのRedisインスタンスに同時にセッションデータを書き込む方法を示します。

Demonstrates how to write session data to both primary and secondary Redis instances simultaneously.

**実行方法 / How to run:**
```bash
php examples/03-double-write.php
```

**前提条件 / Prerequisites:**
- プライマリRedis: localhost:6379
- セカンダリRedis: localhost:6379 (異なるデータベース番号)
  または localhost:6380

**学べること / What you'll learn:**
- `DoubleWriteHook`の設定 / Configuring DoubleWriteHook
- 複数Redisインスタンスへの同時書き込み / Writing to multiple Redis instances
- セカンダリ書き込み失敗時のエラーハンドリング / Error handling on secondary write failure
- フェイルセーフ設定 / Fail-safe configuration

**用途 / Use Cases:**
- セッションデータのバックアップ作成 / Creating backup copies
- データセンター間でのレプリケーション / Cross-datacenter replication
- 新しいRedisインスタンスへの移行 / Migration to new Redis instance

---

### 04. フォールバック読み込み / Fallback Read
**ファイル / File:** `04-fallback-read.php`

プライマリRedisが利用できない場合にセカンダリRedisからセッションデータを読み込む方法を示します。

Demonstrates how to read session data from secondary Redis instances when the primary is unavailable.

**実行方法 / How to run:**
```bash
php examples/04-fallback-read.php
```

**前提条件 / Prerequisites:**
- プライマリRedis: localhost:6379 (database 0)
- フォールバック1: localhost:6379 (database 1)
- フォールバック2: localhost:6379 (database 2)

**学べること / What you'll learn:**
- `FallbackReadHook`の設定 / Configuring FallbackReadHook
- 複数フォールバックRedisの優先順位 / Priority order of multiple fallbacks
- 高可用性セッション管理 / High availability session management
- 自動フェイルオーバー / Automatic failover

**用途 / Use Cases:**
- 高可用性が必要なシステム / Systems requiring high availability
- Redis障害時の自動フェイルオーバー / Automatic failover on Redis failure
- 複数データセンター構成での冗長性 / Redundancy in multi-datacenter setups

---

### 05. ロギング機能 / Logging Functionality
**ファイル / File:** `05-logging.php`

Monologを使用したセッション操作のロギング方法を示します。

Demonstrates how to log session operations using Monolog.

**実行方法 / How to run:**
```bash
php examples/05-logging.php
```

**学べること / What you'll learn:**
- `LoggingHook`の設定 / Configuring LoggingHook
- Monologとの統合 / Integration with Monolog
- ファイルへのロギング / Logging to files
- 複数のログハンドラの使用 / Using multiple log handlers
- ログレベルの設定 / Configuring log levels
- セッションデータのロギング（開発環境のみ） / Logging session data (development only)

**用途 / Use Cases:**
- セッション問題のデバッグ / Debugging session issues
- セッションアクセスの監査 / Auditing session access
- セッションアクティビティの監視 / Monitoring session activity
- パフォーマンス分析 / Performance analysis

**セキュリティ注意事項 / Security Notes:**
- 本番環境ではセッションデータをログに記録しないでください
- パスワードやトークンなどの機密情報は絶対にログに記録しないでください
- Do not log session data in production environments
- Never log sensitive information like passwords or tokens

---

### 06. 空セッション時のCookie送信防止 / Empty Session Cookie Prevention
**ファイル / File:** `06-empty-session-no-cookie.php`

空のセッションデータの場合、Cookieを送信しない機能（PreventEmptySessionCookie）の使用方法を示します。

Demonstrates how to use the PreventEmptySessionCookie feature to prevent sending cookies for empty sessions.

**実行方法 / How to run:**
```bash
php examples/06-empty-session-no-cookie.php
```

**学べること / What you'll learn:**
- `PreventEmptySessionCookie::setup()`の使用方法 / Using PreventEmptySessionCookie::setup()
- 空セッション時の動作 / Behavior with empty sessions
- データありセッション時の動作 / Behavior with sessions containing data
- 既存セッションへの影響 / Impact on existing sessions
- EmptySessionFilterの動作 / How EmptySessionFilter works

**用途 / Use Cases:**
- 空セッションによる無駄なRedis書き込みの削減 / Reducing unnecessary Redis writes for empty sessions
- セッションCookieの不要な送信の防止 / Preventing unnecessary session cookie transmission
- パフォーマンスの向上 / Improving performance
- 匿名ユーザーが多いアプリケーション / Applications with many anonymous users

**動作の仕組み / How it Works:**
1. `PreventEmptySessionCookie::setup()`が`EmptySessionFilter`を登録
2. セッション終了時にシャットダウン関数が実行される
3. `$_SESSION`が空の場合、`session_destroy()`でセッションを破棄
4. Set-Cookieヘッダーで過去の有効期限を設定してCookieを削除

**注意事項 / Notes:**
- この機能はオプトイン方式（明示的に有効化が必要）
- 既存のアプリケーションコードは変更不要（`$_SESSION`の使用方法は同じ）
- 既存セッション（Cookie既存）は通常通り動作

---

## Login Form Example (Web Application)

**ディレクトリ / Directory:** `login-form/`

**前提環境 / Prerequisites:**
- Apache + mod_php
- Redis extension (php-redis)
- PHP 7.4+

このディレクトリには、Redis拡張とenhanced-redis-session-handlerの間でセッションデータが正しく引き継がれることを実証する、実用的なログインフォームアプリケーションが含まれています。

This directory contains a practical login form application that demonstrates session data is correctly preserved when switching between Redis extension and enhanced-redis-session-handler.

**主な機能 / Key Features:**
- ログイン/ログアウト機能 / Login/Logout functionality
- セッションハンドラーの動的切り替え / Dynamic session handler switching
- Redis拡張とenhanced-redis-session-handlerの互換性テスト / Compatibility testing between Redis extension and enhanced handler
- PreventEmptySessionCookie機能のデモ / PreventEmptySessionCookie feature demo

**使用方法 / How to use:**

詳細は [login-form/README.md](login-form/README.md) を参照してください。

For details, see [login-form/README.md](login-form/README.md).

```bash
# Apache + mod_php環境でアクセス / Access in Apache + mod_php environment
http://localhost/examples/login-form/

# または Built-in PHP serverで / Or with Built-in PHP server
cd examples/login-form
php -S localhost:8000
```

**学べること / What you'll learn:**
- Redis拡張からenhanced-redis-session-handlerへの切り替え / Switching from Redis extension to enhanced handler
- セッションデータの互換性確保 / Ensuring session data compatibility
- PHPシリアライザー（'php'形式）の使用 / Using PHP serializer ('php' format)
- PreventEmptySessionCookie機能の実用例 / Practical use of PreventEmptySessionCookie feature
- Webアプリケーションでの実装パターン / Implementation patterns in web applications

**用途 / Use Cases:**
- 既存のRedis拡張ベースのアプリケーションからの移行 / Migration from existing Redis extension-based applications
- セッションハンドラーの切り替えテスト / Session handler switching tests
- Apache + mod_php環境での実装例 / Implementation example in Apache + mod_php environment

**テストシナリオ / Test Scenarios:**
1. Redis拡張でログイン → enhanced-redis-session-handlerに切り替え → ログイン状態保持確認
2. enhanced-redis-session-handlerでログイン → Redis拡張に切り替え → ログイン状態保持確認
3. ログアウト時の空セッションCookie削除確認（enhanced-redis-session-handler使用時）

---

## 実行順序の推奨 / Recommended Execution Order

初めて使用する場合は、以下の順序でサンプルを実行することをお勧めします：

If you're new to the library, we recommend running the examples in this order:

### CLI環境で実行するサンプル / Samples for CLI Environment

1. **01-basic-usage.php** - 基本を理解する / Understand the basics
2. **02-custom-session-id.php** - セッションIDのカスタマイズを学ぶ / Learn ID customization
3. **06-empty-session-no-cookie.php** - 空セッション管理を学ぶ / Learn empty session management
4. **05-logging.php** - ロギングを学ぶ / Learn logging
5. **03-double-write.php** - 冗長性を学ぶ / Learn redundancy
6. **04-fallback-read.php** - 高可用性を学ぶ / Learn high availability

### Webアプリケーションサンプル / Web Application Sample

7. **login-form/** - Webアプリケーションでの実装とセッションハンドラー互換性テスト / Web application implementation and session handler compatibility test
   - Apache + mod_php環境で実行 / Run in Apache + mod_php environment
   - ブラウザでアクセスして動作確認 / Access with browser to verify behavior

## トラブルシューティング / Troubleshooting

### Redisに接続できない / Cannot connect to Redis

```
[ERROR] Failed to open session
```

**解決方法 / Solution:**
1. Redisサーバーが起動しているか確認 / Check if Redis server is running
   ```bash
   redis-cli ping
   ```

2. Redis接続設定を確認 / Verify Redis connection settings
   - ホスト / Host: localhost
   - ポート / Port: 6379
   - パスワード / Password: (設定されている場合 / if configured)

3. ファイアウォール設定を確認 / Check firewall settings

### 複数データベースが使用できない / Cannot use multiple databases

一部のサンプル（03, 04）は複数のRedisデータベースを使用します。Redisの設定で`databases`が十分な数に設定されているか確認してください。

Some examples (03, 04) use multiple Redis databases. Ensure your Redis configuration has sufficient `databases` setting.

```bash
# redis.confを確認 / Check redis.conf
grep "^databases" /etc/redis/redis.conf
# 期待される出力 / Expected output: databases 16
```

### Composerの依存関係エラー / Composer dependency errors

```bash
# 依存関係を再インストール / Reinstall dependencies
composer install --no-cache
```

## 追加リソース / Additional Resources

- **メインドキュメント / Main Documentation:** [../README.md](../README.md)
- **開発ドキュメント / Development Documentation:** [../DEVELOPMENT.md](../DEVELOPMENT.md)
- **API仕様 / API Specification:** [../doc/](../doc/)

## フィードバック / Feedback

サンプルに関する質問や改善提案がある場合は、GitHubのIssueを作成してください。

If you have questions or suggestions for improving these examples, please create a GitHub Issue.

## ライセンス / License

これらのサンプルコードは、メインプロジェクトと同じMITライセンスの下で提供されています。

These example codes are provided under the same MIT License as the main project.
