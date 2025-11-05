# Docker Compose Setup for Login Form Example

このディレクトリには、ログインフォームサンプルのDocker Compose構成が含まれています。

This directory contains the Docker Compose configuration for the login-form example.

## 🏗️ アーキテクチャ / Architecture

```
┌─────────────────────────────────────────────────────────┐
│                       User's Browser                     │
└────────────┬───────────────────────────────┬────────────┘
             │                               │
             │ http://localhost:8080         │ http://localhost:8081
             │ (Redis Extension)             │ (Enhanced Handler)
             │                               │
┌────────────▼────────────┐     ┌───────────▼─────────────┐
│  httpd-redis-ext        │     │  httpd-enhanced         │
│  (Apache + mod_php)     │     │  (Apache + mod_php)     │
│  ┌──────────────────┐   │     │  ┌──────────────────┐   │
│  │ Redis Extension  │   │     │  │ Enhanced Handler │   │
│  │ (php-redis)      │   │     │  │ (Library)        │   │
│  └──────────────────┘   │     │  └──────────────────┘   │
└────────────┬────────────┘     └───────────┬─────────────┘
             │                               │
             │     Same Redis Server         │
             │     (Shared Session Data)     │
             └───────────────┬───────────────┘
                             │
                   ┌─────────▼──────────┐
                   │   redis            │
                   │   (Redis 7 Alpine) │
                   │   Port 6379        │
                   └────────────────────┘
```

## 📦 サービス構成 / Services

### 1. httpd-redis-ext

- **ポート / Port:** 8080
- **セッションハンドラー / Session Handler:** Redis Extension (php-redis)
- **ドキュメントルート / Document Root:** `/var/www/html/examples/login-form`
- **自動プリペンド / Auto Prepend:** `prepend-redis-ext.php` (forces `handler=redis-ext`)

このサーバーは標準のRedis拡張を使用してセッションを管理します。

This server uses the standard Redis extension for session management.

### 2. httpd-enhanced

- **ポート / Port:** 8081
- **セッションハンドラー / Session Handler:** Enhanced Redis Session Handler
- **ドキュメントルート / Document Root:** `/var/www/html/examples/login-form`
- **自動プリペンド / Auto Prepend:** `prepend-enhanced.php` (forces `handler=enhanced`)

このサーバーはenhanced-redis-session-handlerライブラリを使用してセッションを管理します。

This server uses the enhanced-redis-session-handler library for session management.

### 3. redis

- **ポート / Port:** 6379
- **イメージ / Image:** redis:7-alpine
- **データ永続化 / Data Persistence:** Volume `redis-data` (AOF enabled)

両方のhttpdサーバーから共有されるRedisサーバーです。

Shared Redis server used by both httpd servers.

## 🚀 使用方法 / Usage

### 起動 / Start

```bash
# シンプルな起動（推奨） / Simple start (recommended)
./start.sh

# または / or
docker-compose up -d --build
```

### 停止 / Stop

```bash
# 停止 / Stop
./stop.sh

# または / or
docker-compose down

# データも削除 / Remove volumes too
docker-compose down -v
```

### ログ確認 / View Logs

```bash
# 全サービスのログ / All services
docker-compose logs -f

# 特定のサービス / Specific service
docker-compose logs -f httpd-redis-ext
docker-compose logs -f httpd-enhanced
docker-compose logs -f redis
```

### コンテナに入る / Enter Container

```bash
# Redis拡張サーバーに入る / Enter Redis extension server
docker-compose exec httpd-redis-ext bash

# Enhanced handlerサーバーに入る / Enter Enhanced handler server
docker-compose exec httpd-enhanced bash

# Redisサーバーに入る / Enter Redis server
docker-compose exec redis sh
```

### Redisのデータ確認 / Check Redis Data

```bash
# Redisコンテナに入る / Enter Redis container
docker-compose exec redis redis-cli

# セッションキーを検索 / Search for session keys
KEYS login_example:*

# セッションデータを確認 / Check session data
GET login_example:SESSION_ID_HERE

# すべてのキーを削除 / Delete all keys
FLUSHDB
```

## 🔧 カスタマイズ / Customization

### ポート番号の変更 / Change Port Numbers

`docker-compose.yml`を編集:

Edit `docker-compose.yml`:

```yaml
services:
  httpd-redis-ext:
    ports:
      - "8080:80"  # 変更 / Change this

  httpd-enhanced:
    ports:
      - "8081:80"  # 変更 / Change this
```

### PHP設定の変更 / Change PHP Settings

`php.ini`を編集してコンテナを再起動:

Edit `php.ini` and restart containers:

```bash
docker-compose restart
```

### Apache設定の変更 / Change Apache Settings

- `apache-redis-ext.conf` - Redis拡張サーバー用
- `apache-enhanced.conf` - Enhanced handlerサーバー用

編集後、コンテナを再ビルド:

After editing, rebuild containers:

```bash
docker-compose up -d --build
```

## 🧪 テスト方法 / Testing

### 互換性テスト / Compatibility Test

1. **Redis拡張でログイン / Login with Redis extension**
   ```
   http://localhost:8080/
   ```
   - `admin` / `admin123` でログイン

2. **同じセッションでEnhanced handlerにアクセス**
   ```
   http://localhost:8081/
   ```
   - ログイン状態が保持されているか確認
   - ユーザー情報が正しく表示されるか確認

3. **セッションデータの確認**
   ```bash
   docker-compose exec redis redis-cli
   > KEYS login_example:*
   > GET login_example:SESSION_ID
   ```

### PreventEmptySessionCookie機能テスト

1. Enhanced handlerでログイン: `http://localhost:8081/`
2. ログアウト: `http://localhost:8081/logout.php`
3. ブラウザの開発者ツールでCookieが削除されたことを確認

## 📁 ファイル構成 / File Structure

```
docker/
├── README.md                    # このファイル / This file
├── docker-compose.yml           # Docker Compose設定
├── Dockerfile                   # PHP + Apache + Redis拡張
├── apache-redis-ext.conf        # Redis拡張サーバー用Apache設定
├── apache-enhanced.conf         # Enhanced handlerサーバー用Apache設定
├── prepend-redis-ext.php        # Redis拡張強制用プリペンドファイル
├── prepend-enhanced.php         # Enhanced handler強制用プリペンドファイル
├── php.ini                      # PHP設定
├── .dockerignore                # Docker buildから除外するファイル
├── start.sh                     # 起動スクリプト
└── stop.sh                      # 停止スクリプト
```

## 🐛 トラブルシューティング / Troubleshooting

### ポートが既に使用されている / Port Already in Use

```
Error: Bind for 0.0.0.0:8080 failed: port is already allocated
```

**解決方法 / Solution:**

1. 使用中のプロセスを確認:
   ```bash
   lsof -i :8080
   ```

2. `docker-compose.yml`でポート番号を変更

### コンテナが起動しない / Container Won't Start

```bash
# ログを確認 / Check logs
docker-compose logs httpd-redis-ext
docker-compose logs httpd-enhanced

# コンテナを再ビルド / Rebuild containers
docker-compose down
docker-compose up -d --build
```

### Composerの依存関係エラー / Composer Dependency Error

コンテナ内で手動インストール:

Manual install in container:

```bash
docker-compose exec httpd-redis-ext bash
cd /var/www/html
composer install
```

### Redisに接続できない / Cannot Connect to Redis

1. Redisコンテナが起動しているか確認:
   ```bash
   docker-compose ps redis
   ```

2. Redis接続テスト:
   ```bash
   docker-compose exec redis redis-cli ping
   # Should return: PONG
   ```

### セッションが保持されない / Session Not Preserved

1. Redis内のデータを確認:
   ```bash
   docker-compose exec redis redis-cli KEYS '*'
   ```

2. セッションCookieを確認（ブラウザ開発者ツール）

3. 両方のサーバーが同じRedisサーバーを参照しているか確認:
   ```bash
   docker-compose exec httpd-redis-ext env | grep REDIS
   docker-compose exec httpd-enhanced env | grep REDIS
   ```

## 🔒 セキュリティ注意事項 / Security Notes

このDocker構成は**開発・テスト目的のみ**です。本番環境では使用しないでください。

This Docker configuration is for **development and testing purposes only**. Do not use in production.

本番環境では:
- 適切なファイアウォール設定
- Redis認証の有効化
- HTTPS/TLSの使用
- 適切なセッション設定（secure, httponly, samesite）

For production:
- Proper firewall configuration
- Enable Redis authentication
- Use HTTPS/TLS
- Proper session settings (secure, httponly, samesite)

## 📚 参照 / References

- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [PHP Official Docker Images](https://hub.docker.com/_/php)
- [Redis Official Docker Images](https://hub.docker.com/_/redis)
- [../README.md](../README.md) - Login form example documentation
