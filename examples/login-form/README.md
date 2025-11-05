# Login Form Example - Session Handler Compatibility Test

このサンプルは、Redis拡張とenhanced-redis-session-handlerの間でセッションデータが正しく引き継がれることを実証する、実用的なログインフォームアプリケーションです。

This example is a practical login form application that demonstrates that session data is correctly preserved when switching between Redis extension and enhanced-redis-session-handler.

## 🎯 目的 / Purpose

1. **セッションハンドラー互換性のテスト / Session Handler Compatibility Test**
   - Redis拡張で作成したセッションがenhanced-redis-session-handlerで読み込めることを確認
   - enhanced-redis-session-handlerで作成したセッションがRedis拡張で読み込めることを確認
   - Verify that sessions created with Redis extension can be read by enhanced-redis-session-handler
   - Verify that sessions created with enhanced-redis-session-handler can be read by Redis extension

2. **PreventEmptySessionCookie機能のデモ / PreventEmptySessionCookie Feature Demo**
   - ログアウト時に空セッションのCookieが削除されることを確認
   - Verify that cookies for empty sessions are removed on logout

3. **実用的な使用例 / Practical Usage Example**
   - Apache + mod_php環境での実際の使用方法を示す
   - Demonstrate actual usage in Apache + mod_php environment

## 📋 前提条件 / Prerequisites

### 必須 / Required

- **PHP 7.4以上 / PHP 7.4 or higher**
- **Apache + mod_php** (推奨 / recommended)
- **Redis拡張 / Redis extension** (`php-redis`)
- **Redisサーバー / Redis server** (localhost:6379)
- **Composer依存関係 / Composer dependencies** (`composer install`実行済み)

### 確認方法 / How to Check

```bash
# PHP version
php --version

# Redis extension
php -m | grep redis

# Redis server
redis-cli ping  # Should return: PONG

# Apache + mod_php (check PHP SAPI)
php -r "echo php_sapi_name();"  # Should return: apache2handler (when running via Apache)
```

## 🚀 セットアップ / Setup

### 1. Composerパッケージのインストール / Install Composer Packages

```bash
cd /path/to/enhanced-redis-session-handler.php
composer install
```

### 2. Apacheの設定 / Apache Configuration

このサンプルをApacheのドキュメントルート配下に配置するか、仮想ホストを設定します。

Place this example under Apache's document root or configure a virtual host.

**例 / Example:**

```apache
# /etc/apache2/sites-available/session-example.conf
<VirtualHost *:80>
    ServerName session-example.local
    DocumentRoot /path/to/enhanced-redis-session-handler.php/examples

    <Directory /path/to/enhanced-redis-session-handler.php/examples>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
# Enable site
sudo a2ensite session-example
sudo systemctl reload apache2

# Add to /etc/hosts
echo "127.0.0.1 session-example.local" | sudo tee -a /etc/hosts
```

### 3. Redisサーバーの起動 / Start Redis Server

```bash
# Redisが起動していることを確認 / Ensure Redis is running
sudo systemctl start redis-server
sudo systemctl status redis-server

# または / or
redis-server
```

## 📂 ファイル構成 / File Structure

```
examples/login-form/
├── README.md                    # このファイル / This file
├── config.php                   # 共通設定 / Common configuration
├── bootstrap-redis-ext.php      # Redis拡張セッションハンドラー設定
├── bootstrap-enhanced.php       # Enhanced handlerセッションハンドラー設定
├── index.php                    # ログインフォーム / Login form
├── login.php                    # ログイン処理 / Login process
├── dashboard.php                # ダッシュボード（ログイン後）/ Dashboard (after login)
└── logout.php                   # ログアウト処理 / Logout process
```

## 🎮 使用方法 / How to Use

### 基本的な使用 / Basic Usage

1. ブラウザで `http://session-example.local/login-form/` にアクセス
2. デモアカウントでログイン:
   - **Username:** `admin` / **Password:** `admin123`
   - **Username:** `user1` / **Password:** `password1`
   - **Username:** `user2` / **Password:** `password2`

### セッションハンドラー切り替えテスト / Session Handler Switching Test

このサンプルの最大の特徴は、セッションハンドラーを動的に切り替えてセッションデータの互換性をテストできることです。

The key feature of this example is the ability to dynamically switch session handlers to test session data compatibility.

#### テストシナリオ1: Redis拡張 → Enhanced handler

1. **Redis拡張でログイン / Login with Redis extension**
   ```
   http://session-example.local/login-form/?handler=redis-ext
   ```
   - ユーザー名とパスワードを入力してログイン
   - ダッシュボードが表示され、「Current Handler: redis-ext」と表示される

2. **Enhanced handlerに切り替え / Switch to Enhanced handler**
   - ダッシュボードで「Switch to Enhanced Handler」ボタンをクリック
   - または直接アクセス:
   ```
   http://session-example.local/login-form/dashboard.php?handler=enhanced
   ```

3. **セッションデータの確認 / Verify session data**
   - ログイン状態が保持されていることを確認
   - ユーザー情報が正しく表示されることを確認
   - 「Current Handler: enhanced」と表示される
   - Session Dataセクションで`$_SESSION`の内容が引き継がれていることを確認

#### テストシナリオ2: Enhanced handler → Redis拡張

1. **Enhanced handlerでログイン / Login with Enhanced handler**
   ```
   http://session-example.local/login-form/?handler=enhanced
   ```
   - ユーザー名とパスワードを入力してログイン
   - ダッシュボードが表示され、「Current Handler: enhanced」と表示される

2. **Redis拡張に切り替え / Switch to Redis extension**
   - ダッシュボードで「Switch to Redis Extension」ボタンをクリック
   - または直接アクセス:
   ```
   http://session-example.local/login-form/dashboard.php?handler=redis-ext
   ```

3. **セッションデータの確認 / Verify session data**
   - ログイン状態が保持されていることを確認
   - ユーザー情報が正しく表示されることを確認
   - 「Current Handler: redis-ext」と表示される

#### テストシナリオ3: PreventEmptySessionCookie機能のテスト

1. **Enhanced handlerでログイン / Login with Enhanced handler**
   ```
   http://session-example.local/login-form/?handler=enhanced
   ```

2. **ログアウト / Logout**
   - ダッシュボードで「Logout」ボタンをクリック
   - セッションが破棄される

3. **Cookieの確認 / Verify cookies**
   - ブラウザの開発者ツールでCookieを確認
   - Enhanced handlerを使用している場合、空セッションのCookieが削除される
   - ログインページに「You have been logged out successfully.」メッセージが表示される

4. **比較: Redis拡張でのログアウト / Compare: Logout with Redis extension**
   ```
   http://session-example.local/login-form/?handler=redis-ext
   ```
   - Redis拡張でログイン→ログアウト
   - Redis拡張の場合、セッションCookieは削除されない（標準動作）

## 🔍 動作の仕組み / How It Works

### セッションデータの互換性 / Session Data Compatibility

両方のハンドラーが以下の設定を使用しているため、セッションデータが互換性を持ちます:

Both handlers use the following settings, ensuring session data compatibility:

- **Serialize Handler:** `php` (not `php_serialize`)
- **Redis Key Prefix:** `login_example:`
- **Same Redis server and database:** `localhost:6379`, database 0

### セッションデータの形式 / Session Data Format

PHPの`php`シリアライザーは以下の形式でデータを保存します:

PHP's `php` serializer stores data in the following format:

```
user|a:4:{s:8:"username";s:5:"admin";s:4:"name";s:13:"Administrator";s:4:"role";s:5:"admin";s:12:"logged_in_at";s:19:"2025-11-05 12:34:56";}
```

この形式は両方のハンドラーで読み書き可能です。

This format is readable and writable by both handlers.

### PreventEmptySessionCookie機能

Enhanced handlerでは、`PreventEmptySessionCookie::setup()`を呼び出しています:

The Enhanced handler calls `PreventEmptySessionCookie::setup()`:

```php
PreventEmptySessionCookie::setup($handler, new NullLogger());
```

これにより:
1. `EmptySessionFilter`が登録される
2. セッション終了時にシャットダウン関数が実行される
3. `$_SESSION`が空の場合、`session_destroy()`でセッションを破棄
4. Set-Cookieヘッダーで過去の有効期限を設定してCookieを削除

This:
1. Registers `EmptySessionFilter`
2. Executes shutdown function at session end
3. If `$_SESSION` is empty, destroys session with `session_destroy()`
4. Removes cookie by setting past expiration in Set-Cookie header

## 🧪 テスト項目 / Test Items

### ✅ 確認すべき項目 / Items to Verify

- [ ] Redis拡張でログイン → Enhanced handlerに切り替え → ログイン状態が保持される
- [ ] Enhanced handlerでログイン → Redis拡張に切り替え → ログイン状態が保持される
- [ ] Enhanced handlerでログアウト → 空セッションのCookieが削除される
- [ ] Redis拡張でログアウト → セッションCookieは残る（標準動作）
- [ ] ユーザー情報（username, name, role, logged_in_at）が正しく引き継がれる
- [ ] セッション開始時刻（_started_at）が保持される
- [ ] セッションID、シリアライザー、ハンドラー名が正しく表示される

## 🐛 トラブルシューティング / Troubleshooting

### Redisに接続できない / Cannot connect to Redis

```
[ERROR] Failed to connect to Redis
```

**解決方法 / Solution:**

```bash
# Redisサーバーが起動しているか確認
redis-cli ping

# Redisサーバーを起動
sudo systemctl start redis-server
```

### セッションが切り替わらない / Session doesn't switch

**原因 / Cause:** セッションIDが変わっていない

**解決方法 / Solution:**
- セッションIDは同じまま、ハンドラーだけが切り替わります（これが正常な動作）
- `Current Handler`の表示が変わることを確認してください

### "Headers already sent" エラー

**原因 / Cause:** PHPファイルのBOM、または出力がヘッダー送信前に発生

**解決方法 / Solution:**
- すべてのPHPファイルが`<?php`で始まり、余分な空白がないことを確認
- ファイルエンコーディングがUTF-8 (BOMなし)であることを確認

### Redis拡張が見つからない / Redis extension not found

```
Redis extension is not loaded.
```

**解決方法 / Solution:**

```bash
# Ubuntu/Debian
sudo apt-get install php-redis
sudo systemctl restart apache2

# CentOS/RHEL
sudo yum install php-redis
sudo systemctl restart httpd

# 確認
php -m | grep redis
```

## 📚 参考情報 / References

### 関連ドキュメント / Related Documentation

- [../README.md](../README.md) - Examples overview
- [../../README.md](../../README.md) - Main documentation
- [../../doc/specification.md](../../doc/specification.md) - Feature specifications

### 関連する実装 / Related Implementation

- [../../src/Session/PreventEmptySessionCookie.php](../../src/Session/PreventEmptySessionCookie.php)
- [../../src/Serializer/PhpSerializer.php](../../src/Serializer/PhpSerializer.php)
- [../../tests/Integration/SessionSerializeHandlerTest.php](../../tests/Integration/SessionSerializeHandlerTest.php)

## 💡 Tips

### CLI環境でのテスト / Testing in CLI Environment

このサンプルはApache + mod_php向けですが、CLIでも動作確認できます:

This example is designed for Apache + mod_php, but you can test it in CLI:

```bash
# Built-in PHP server
cd examples/login-form
php -S localhost:8000

# Access in browser
http://localhost:8000/
```

**注意 / Note:** CLIではセッションCookieの動作が異なるため、完全なテストはApache環境で行ってください。

Session cookie behavior differs in CLI, so perform complete testing in Apache environment.

### Redisのデータを直接確認 / Directly Check Redis Data

```bash
# Redisに接続 / Connect to Redis
redis-cli

# セッションキーを検索 / Search for session keys
KEYS login_example:*

# セッションデータを確認 / Check session data
GET login_example:SESSION_ID_HERE

# すべてのセッションを削除（テスト後のクリーンアップ）
# Delete all sessions (cleanup after testing)
KEYS login_example:* | xargs redis-cli DEL
```

### デバッグモード / Debug Mode

より詳細なログを見るには、`bootstrap-enhanced.php`でロガーを変更します:

For more detailed logs, change the logger in `bootstrap-enhanced.php`:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

// ...
PreventEmptySessionCookie::setup($handler, $logger);
```

## 📝 ライセンス / License

このサンプルコードは、メインプロジェクトと同じMITライセンスの下で提供されています。

This example code is provided under the same MIT License as the main project.
