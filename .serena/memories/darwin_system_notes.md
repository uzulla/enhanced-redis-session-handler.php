# Darwin（macOS）システムノート

このプロジェクトはDarwin（macOS）上で開発されています。

## システムコマンド

### 基本コマンド
Darwinでは標準的なUnixコマンドが使用可能：
- `ls`: ディレクトリ内容の表示
- `cd`: ディレクトリ移動
- `grep`: テキスト検索
- `find`: ファイル検索
- `cat`: ファイル内容表示
- `head`/`tail`: ファイルの先頭/末尾表示

### Git操作
```bash
git status
git diff
git log
git add .
git commit -m "message"
git push
```

### Composer操作
```bash
composer install
composer update
composer test
composer phpstan
composer cs-check
composer cs-fix
```

### Docker操作
```bash
docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml down
docker compose -f docker/docker-compose.yml exec app bash
docker compose -f docker/docker-compose.yml logs -f app
```

## プロジェクトのエントリーポイント

このプロジェクトは**ライブラリ**であるため、直接実行するエントリーポイントはありません。

### 使用方法
他のPHPプロジェクトから以下のようにインストールして使用：
```bash
composer require uzulla/enhanced-redis-session-handler
```

### サンプルコード実行
`examples/`ディレクトリにサンプルコードがあります：
```bash
# Docker環境でサンプルを実行
docker compose -f docker/docker-compose.yml up -d
# http://localhost:8080/examples/01-basic-usage.php にアクセス
```

利用可能なサンプル：
- `01-basic-usage.php`: 基本的な使い方
- `02-custom-session-id.php`: カスタムセッションID
- `03-double-write.php`: ダブルライト機能
- `04-fallback-read.php`: フォールバック読み込み
- `05-logging.php`: ロギング機能
- `06-empty-session-no-cookie.php`: 空セッション時のクッキー防止

## 開発環境

### ローカル開発
```bash
# 依存関係インストール
composer install

# テスト実行（Redisが必要）
composer test

# 静的解析
composer phpstan

# コードスタイルチェック
composer cs-check
```

### Docker開発（推奨）
Docker環境を使用すると、PHP 7.4、Apache、ValKeyを含む完全な開発環境が利用可能：
```bash
docker compose -f docker/docker-compose.yml up -d
./docker/healthcheck.sh
docker compose -f docker/docker-compose.yml exec app bash
```

## 注意事項

### composer.lockの扱い
- ライブラリとして`composer.lock`はリポジトリにコミットされていない
- CIは毎回最新の互換性のある依存関係を解決
- ローカル開発時は`composer install`で依存関係をインストール
