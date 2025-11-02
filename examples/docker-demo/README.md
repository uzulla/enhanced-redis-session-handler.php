# Docker環境でのセッションハンドラデモ / Session Handler Demo in Docker Environment

このディレクトリには、Docker環境でEnhanced Redis Session Handlerを実際に使用するWebアプリケーションのデモが含まれています。

This directory contains a web application demo that uses Enhanced Redis Session Handler in a Docker environment.

## 概要 / Overview

このデモでは、以下の機能を確認できます：

This demo demonstrates the following features:

- ✅ Docker環境でのセッションハンドラの統合 / Session handler integration in Docker
- ✅ Redisを使用したセッションデータの永続化 / Session data persistence using Redis
- ✅ セッションカウンターの動作 / Session counter functionality
- ✅ セッション情報の表示 / Session information display
- ✅ セッションの破棄 / Session destruction
- ✅ 複数ブラウザでの独立したセッション管理 / Independent session management across browsers

## ファイル構成 / File Structure

```
examples/docker-demo/
├── README.md          # このファイル / This file
├── session-init.php   # セッションハンドラ初期化スクリプト / Session handler initialization
└── index.php          # Webアプリケーションデモ / Web application demo
```

## 前提条件 / Prerequisites

以下がインストールされている必要があります：

The following must be installed:

- Docker
- Docker Compose

## セットアップ手順 / Setup Instructions

### 1. リポジトリのルートディレクトリに移動 / Navigate to Repository Root

```bash
cd /path/to/enhanced-redis-session-handler.php
```

### 2. Docker環境を起動 / Start Docker Environment

```bash
docker compose -f docker/docker-compose.yml up -d
```

このコマンドは以下のコンテナを起動します：

This command starts the following containers:

- **app**: PHP + Apache Webサーバー (ポート8080)
- **storage**: Redis (Valkey) プライマリストレージ (ポート6379)
- **storage-fallback**: Redis (Valkey) フォールバックストレージ (ポート6380)

### 3. コンテナの起動を確認 / Verify Container Status

```bash
docker compose -f docker/docker-compose.yml ps
```

すべてのコンテナが "Up" 状態であることを確認してください。

Ensure all containers are in "Up" state.

### 4. ブラウザでアクセス / Access via Browser

以下のURLをブラウザで開いてください：

Open the following URL in your browser:

```
http://localhost:8080/examples/docker-demo/
```

## 動作確認 / Verification

### 基本動作の確認 / Basic Functionality Check

1. **初回アクセス / First Access**
   - ブラウザで http://localhost:8080/examples/docker-demo/ を開く
   - 訪問カウンターが「1」と表示される
   - セッションIDが表示される
   - 作成日時と最終アクセス日時が表示される

2. **ページリロード / Page Reload**
   - ページをリロード（F5キー）
   - 訪問カウンターが「2」に増加する
   - セッションIDは変わらない
   - 最終アクセス日時が更新される

3. **セッション破棄 / Session Destruction**
   - 「セッション破棄」ボタンをクリック
   - 確認ダイアログで「OK」をクリック
   - ページがリロードされ、訪問カウンターが「1」にリセットされる
   - 新しいセッションIDが生成される

4. **複数ブラウザでの動作確認 / Multiple Browser Check**
   - 別のブラウザ（またはシークレットモード）で同じURLを開く
   - 異なるセッションIDが生成される
   - 各ブラウザで独立したカウンターが動作する

### Redisでのセッションデータ確認 / Verify Session Data in Redis

Redisに直接接続してセッションデータを確認できます：

You can connect to Redis directly to verify session data:

```bash
# Redisコンテナに接続 / Connect to Redis container
docker exec -it enhanced-redis-session-handler-storage redis-cli

# セッションキーの一覧を表示 / List session keys
KEYS session:*

# 特定のセッションデータを表示 / Display specific session data
GET session:YOUR_SESSION_ID_HERE

# Redisから切断 / Disconnect from Redis
exit
```

### ログの確認 / Check Logs

#### アプリケーションログ / Application Logs

```bash
# アプリケーションコンテナのログを表示 / Display application container logs
docker compose -f docker/docker-compose.yml logs app

# リアルタイムでログを監視 / Monitor logs in real-time
docker compose -f docker/docker-compose.yml logs -f app
```

#### PHPエラーログ / PHP Error Logs

```bash
# PHPエラーログを表示 / Display PHP error logs
docker exec enhanced-redis-session-handler-app tail -f /var/log/apache2/php_errors.log
```

#### Redisログ / Redis Logs

```bash
# Redisログを表示 / Display Redis logs
docker compose -f docker/docker-compose.yml logs storage
```

## トラブルシューティング / Troubleshooting

### 問題: ページにアクセスできない / Issue: Cannot Access Page

**症状 / Symptom:**
```
This site can't be reached
```

**解決方法 / Solution:**

1. Dockerコンテナが起動しているか確認
   ```bash
   docker compose -f docker/docker-compose.yml ps
   ```

2. コンテナを再起動
   ```bash
   docker compose -f docker/docker-compose.yml restart app
   ```

3. ポート8080が他のプロセスで使用されていないか確認
   ```bash
   # Linux/Mac
   lsof -i :8080
   
   # Windows
   netstat -ano | findstr :8080
   ```

### 問題: セッションハンドラの初期化に失敗 / Issue: Session Handler Initialization Failed

**症状 / Symptom:**
```
Session Handler Initialization Failed
Failed to open session
```

**解決方法 / Solution:**

1. Redisコンテナが起動しているか確認
   ```bash
   docker compose -f docker/docker-compose.yml ps storage
   ```

2. Redis接続をテスト
   ```bash
   docker exec -it enhanced-redis-session-handler-storage redis-cli ping
   # 期待される出力: PONG
   ```

3. 環境変数を確認
   ```bash
   docker exec enhanced-redis-session-handler-app env | grep REDIS
   ```

4. コンテナを再起動
   ```bash
   docker compose -f docker/docker-compose.yml down
   docker compose -f docker/docker-compose.yml up -d
   ```

### 問題: セッションデータが保存されない / Issue: Session Data Not Persisted

**症状 / Symptom:**
- ページをリロードしてもカウンターが増加しない
- セッションIDが毎回変わる

**解決方法 / Solution:**

1. php.iniの設定を確認
   ```bash
   docker exec enhanced-redis-session-handler-app cat /usr/local/etc/php/conf.d/custom.ini | grep session.save_handler
   # 期待される出力: session.save_handler = user
   ```

2. session-init.phpが正しく読み込まれているか確認
   ```bash
   docker exec enhanced-redis-session-handler-app ls -la /var/www/html/examples/docker-demo/session-init.php
   ```

3. PHPエラーログを確認
   ```bash
   docker exec enhanced-redis-session-handler-app tail -50 /var/log/apache2/php_errors.log
   ```

### 問題: Composerの依存関係エラー / Issue: Composer Dependency Error

**症状 / Symptom:**
```
Fatal error: Class 'Uzulla\EnhancedRedisSessionHandler\...' not found
```

**解決方法 / Solution:**

1. コンテナ内でComposerをインストール
   ```bash
   docker exec enhanced-redis-session-handler-app composer install
   ```

2. オートロードファイルが存在するか確認
   ```bash
   docker exec enhanced-redis-session-handler-app ls -la /var/www/html/vendor/autoload.php
   ```

## 環境変数 / Environment Variables

以下の環境変数がdocker/docker-compose.ymlで設定されています：

The following environment variables are configured in docker/docker-compose.yml:

| 変数名 / Variable | デフォルト値 / Default | 説明 / Description |
|------------------|----------------------|-------------------|
| `REDIS_HOST` | `storage` | Redisサーバーのホスト名 / Redis server hostname |
| `REDIS_PORT` | `6379` | Redisサーバーのポート番号 / Redis server port |
| `REDIS_FALLBACK_HOST` | `storage-fallback` | フォールバックRedisのホスト名 / Fallback Redis hostname |
| `REDIS_FALLBACK_PORT` | `6379` | フォールバックRedisのポート番号 / Fallback Redis port |

## カスタマイズ / Customization

### セッションタイムアウトの変更 / Change Session Timeout

`session-init.php` の以下の行を編集してください：

Edit the following line in `session-init.php`:

```php
$sessionConfig = new SessionConfig(
    $connectionConfig,
    new DefaultSessionIdGenerator(),
    1440, // ← この値を変更（秒単位） / Change this value (in seconds)
    $logger
);
```

### ログレベルの変更 / Change Log Level

`session-init.php` の以下の行を編集してください：

Edit the following line in `session-init.php`:

```php
$logger->pushHandler(new StreamHandler('php://stderr', LogLevel::INFO)); // ← INFO を DEBUG, WARNING, ERROR などに変更
```

### カスタムセッションIDジェネレータの使用 / Use Custom Session ID Generator

`session-init.php` で `DefaultSessionIdGenerator` を他のジェネレータに置き換えてください：

Replace `DefaultSessionIdGenerator` with another generator in `session-init.php`:

```php
use Uzulla\EnhancedRedisSessionHandler\SessionId\PrefixedSessionIdGenerator;

$sessionConfig = new SessionConfig(
    $connectionConfig,
    new PrefixedSessionIdGenerator('myapp_'), // プレフィックス付きセッションID
    1440,
    $logger
);
```

## Docker環境の停止と削除 / Stop and Remove Docker Environment

### コンテナの停止 / Stop Containers

```bash
docker compose -f docker/docker-compose.yml stop
```

### コンテナの停止と削除 / Stop and Remove Containers

```bash
docker compose -f docker/docker-compose.yml down
```

### コンテナとボリュームの削除 / Remove Containers and Volumes

```bash
docker compose -f docker/docker-compose.yml down -v
```

**注意:** `-v` オプションを使用すると、Redisに保存されたすべてのセッションデータが削除されます。

**Warning:** Using the `-v` option will delete all session data stored in Redis.

## 関連ドキュメント / Related Documentation

- [メインREADME / Main README](../../README.md)
- [開発ドキュメント / Development Documentation](../../DEVELOPMENT.md)
- [サンプル一覧 / Examples List](../README.md)
- [アーキテクチャドキュメント / Architecture Documentation](../../doc/developers/architecture.md)

## セキュリティに関する注意 / Security Notes

このデモは**開発環境専用**です。本番環境では以下の点に注意してください：

This demo is for **development environment only**. For production environments, please note:

- ⚠️ セッションデータをログに記録しない / Do not log session data
- ⚠️ 適切なセッションタイムアウトを設定する / Set appropriate session timeout
- ⚠️ HTTPS を使用する / Use HTTPS
- ⚠️ セキュアなセッションCookie設定を使用する / Use secure session cookie settings
- ⚠️ Redis接続にパスワード認証を使用する / Use password authentication for Redis

## ライセンス / License

このデモコードは、メインプロジェクトと同じMITライセンスの下で提供されています。

This demo code is provided under the same MIT License as the main project.

## フィードバック / Feedback

問題や改善提案がある場合は、GitHubのIssueを作成してください。

If you have issues or suggestions for improvement, please create a GitHub Issue.
