# Migration Validation Environment

独立した検証環境のスケルトン。PHP 7.4とPHP 8.1の両方の環境で動作確認ができます。

## Quick Start

### 環境の起動

```bash
cd migration-validation
docker-compose up -d
```

### 動作確認

以下のURLにアクセスして、各環境が正しく動作していることを確認してください：

- **PHP 7.4 環境**: http://localhost:8074/health.php
- **PHP 8.1 環境**: http://localhost:8081/health.php

各ページで以下の情報が表示されます：
- PHP バージョン
- redis-ext バージョン
- 動作確認メッセージ

### 相互運用性テスト（PHP 8.1のみ）

PHP 8.1コンテナでは、enhanced-redis-session-handlerライブラリとredis-extの相互運用性をテストできます。

#### 1. Composerで依存関係をインストール

```bash
docker-compose exec php81-apache composer install
```

#### 2. 相互運用性テストにアクセス

http://localhost:8081/session_interop.php

このページでは以下のテストが実行されます：
- セッションハンドラーの登録
- セッションデータの書き込み
- セッションデータの読み取り
- セッションIDの検証
- タイムスタンプの更新
- セッションの破棄

すべてのテストが成功すれば、redis-extとenhanced-redis-session-handlerが正しく連携していることが確認できます。

### Redis接続確認

```bash
redis-cli -p 16379 PING
```

`PONG` が返ってくれば正常に動作しています。

### 環境の停止

```bash
docker-compose down
```

## 構成

- **PHP 7.4 コンテナ**: Apache + mod_php + redis-ext 6.0.2 (ポート 8074)
  - ベースライン環境として使用
- **PHP 8.1 コンテナ**: Apache + mod_php + redis-ext 6.2.0 + Composer (ポート 8081)
  - enhanced-redis-session-handlerライブラリの相互運用性テスト用
  - リポジトリルートが `/workspace` にマウントされています
- **Redis コンテナ**: Redis 7 Alpine (ポート 16379)

## トラブルシューティング

### ポートが既に使用されている場合

ポート 8074, 8081, 16379 が既に使用されている場合は、`docker-compose.yml` のポート設定を変更してください。

### コンテナのログを確認

```bash
docker-compose logs -f
```

### コンテナの再ビルド

```bash
docker-compose build --no-cache
docker-compose up -d
```
