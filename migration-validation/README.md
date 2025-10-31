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

### 環境の停止

```bash
docker-compose down
```

## PHP Serializer 相互運用性検証

このセクションでは、`php` serializer を使用した redis-ext と enhanced-redis-session-handler の相互運用性を検証します。

### 前提条件

1. Docker環境が起動していること
2. PHP 8.1環境でComposerの依存関係がインストールされていること

```bash
docker-compose exec php81-apache composer install
```

### 検証手順

#### テストメニューへのアクセス

- **PHP 7.4 環境**: http://localhost:8074/index.php
- **PHP 8.1 環境**: http://localhost:8081/index.php

各環境のテストメニューから、以下の検証を実行できます。

### PHP 7.4 環境での検証

#### 1. 旧→新テスト (redis-ext → library)

**目的**: redis-ext で書き込んだセッションを enhanced-redis-session-handler ライブラリで読み込めることを確認

**手順**:
1. http://localhost:8074/index.php にアクセス
2. 「旧→新テスト」セクションの「Step 1: redis-ext で書き込み」をクリック
3. セッションIDとデータが表示されることを確認
4. 「Step 2: ライブラリで読み込み」をクリック
5. ✓ Successfully read session data written by redis-ext! が表示されることを確認
6. セッションデータが正しく読み込まれていることを確認

**期待結果**:
- redis-ext で書き込んだセッションデータが enhanced-redis-session-handler で正しく読み込める
- test_type, php_version, redis_ext_version, timestamp, test_data が全て正しく取得できる

#### 2. 新→旧テスト (library → redis-ext)

**目的**: enhanced-redis-session-handler ライブラリで書き込んだセッションを redis-ext で読み込めることを確認

**手順**:
1. http://localhost:8074/destroy.php にアクセスしてセッションをクリーンアップ
2. http://localhost:8074/index.php にアクセス
3. 「新→旧テスト」セクションの「Step 1: ライブラリで書き込み」をクリック
4. セッションIDとデータが表示されることを確認
5. 「Step 2: redis-ext で読み込み」をクリック
6. ✓ Successfully read session data written by enhanced-redis-session-handler! が表示されることを確認
7. セッションデータが正しく読み込まれていることを確認

**期待結果**:
- enhanced-redis-session-handler で書き込んだセッションデータが redis-ext で正しく読み込める
- test_type, php_version, library_version, timestamp, test_data が全て正しく取得できる

### PHP 8.1 環境での検証

#### 3. 旧→新テスト (redis-ext → library)

**手順**:
1. http://localhost:8081/destroy.php にアクセスしてセッションをクリーンアップ
2. http://localhost:8081/index.php にアクセス
3. 「旧→新テスト」セクションの「Step 1: redis-ext で書き込み」をクリック
4. セッションIDとデータが表示されることを確認
5. 「Step 2: ライブラリで読み込み」をクリック
6. ✓ Successfully read session data written by redis-ext! が表示されることを確認

**期待結果**:
- PHP 8.1 環境でも redis-ext → library の相互運用性が動作する

#### 4. 新→旧テスト (library → redis-ext)

**手順**:
1. http://localhost:8081/destroy.php にアクセスしてセッションをクリーンアップ
2. http://localhost:8081/index.php にアクセス
3. 「新→旧テスト」セクションの「Step 1: ライブラリで書き込み」をクリック
4. セッションIDとデータが表示されることを確認
5. 「Step 2: redis-ext で読み込み」をクリック
6. ✓ Successfully read session data written by enhanced-redis-session-handler! が表示されることを確認

**期待結果**:
- PHP 8.1 環境でも library → redis-ext の相互運用性が動作する

### クロスバージョン検証

#### 5. PHP 7.4 → PHP 8.1 テスト

**目的**: PHP 7.4 で書き込んだセッションを PHP 8.1 で読み込めることを確認

**手順**:
1. http://localhost:8074/destroy.php にアクセスしてセッションをクリーンアップ
2. http://localhost:8074/test.php?action=write_old にアクセス（redis-ext で書き込み）
3. セッションIDをメモ
4. **同じブラウザで** http://localhost:8081/test.php?action=read_new にアクセス（ライブラリで読み込み）
5. ✓ Successfully read session data written by redis-ext! が表示されることを確認
6. php_version が 7.4.x であることを確認（PHP 7.4 で書き込まれたことの証明）

**期待結果**:
- PHP 7.4 で書き込んだセッションが PHP 8.1 で正しく読み込める
- セッションIDが両環境で共有される（Cookie経由）

#### 6. PHP 8.1 → PHP 7.4 テスト

**目的**: PHP 8.1 で書き込んだセッションを PHP 7.4 で読み込めることを確認

**手順**:
1. http://localhost:8081/destroy.php にアクセスしてセッションをクリーンアップ
2. http://localhost:8081/test.php?action=write_new にアクセス（ライブラリで書き込み）
3. セッションIDをメモ
4. **同じブラウザで** http://localhost:8074/test.php?action=read_old にアクセス（redis-ext で読み込み）
5. ✓ Successfully read session data written by enhanced-redis-session-handler! が表示されることを確認
6. php_version が 8.1.x であることを確認（PHP 8.1 で書き込まれたことの証明）

**期待結果**:
- PHP 8.1 で書き込んだセッションが PHP 7.4 で正しく読み込める
- セッションIDが両環境で共有される（Cookie経由）

### Redis データの確認

セッションデータが Redis に正しく保存されているか確認できます。

```bash
# Redis に接続
redis-cli -p 16379

# データベース 0 を選択（デフォルト）
SELECT 0

# セッションキーの一覧を表示
KEYS PHPREDIS_SESSION:*

# 特定のセッションデータを表示（セッションIDを置き換えてください）
GET PHPREDIS_SESSION:your_session_id_here

# TTLを確認
TTL PHPREDIS_SESSION:your_session_id_here
```

**期待結果**:
- キーのプレフィックスは `PHPREDIS_SESSION:` （redis-ext のデフォルト）
- データベースは 0 （プレフィックスなし）
- セッションデータが `php_serialize` 形式でシリアライズされている
- TTL が設定されている（デフォルト: 1440秒 = 24分）

### 旧版の相互運用性テスト（参考）

Issue #67 で実装された基本的な相互運用性テストも引き続き利用できます：

http://localhost:8081/session_interop.php

このページでは以下のテストが実行されます：
- セッションハンドラーの登録
- セッションデータの書き込み
- セッションデータの読み取り
- セッションIDの検証
- タイムスタンプの更新
- セッションの破棄

### Redis接続確認

```bash
redis-cli -p 16379 PING
```

`PONG` が返ってくれば正常に動作しています。

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
