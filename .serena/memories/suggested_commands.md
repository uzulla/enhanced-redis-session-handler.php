# 推奨コマンド一覧

## テスト関連

### 基本的なテスト実行
```bash
# 全テストを実行
composer test

# テキスト形式のカバレッジレポート
composer coverage

# HTML形式のカバレッジレポート（coverage/html/に生成）
composer coverage-report
```

### 特定テストの実行
```bash
# 特定のテストファイルを実行
vendor/bin/phpunit tests/RedisSessionHandlerTest.php

# 特定のテストメソッドを実行
vendor/bin/phpunit --filter testMethodName

# 特定のディレクトリ内のテストのみ実行
vendor/bin/phpunit tests/Integration/
vendor/bin/phpunit tests/E2E/
```

## 静的解析・コードスタイル

```bash
# PHPStan実行（最大レベル + strict rules）
composer phpstan

# コードスタイルチェック（PSR-12準拠）
composer cs-check

# コードスタイル自動修正
composer cs-fix

# 全てのlintチェックを実行
composer lint

# 全てのチェック（lint + テスト）
composer check
```

## Docker環境

```bash
# Docker環境起動
docker compose -f docker/docker-compose.yml up -d

# ヘルスチェック
./docker/healthcheck.sh

# コンテナに入る
docker compose -f docker/docker-compose.yml exec app bash

# Docker内でテスト実行
docker compose -f docker/docker-compose.yml exec app composer test

# Docker内で静的解析
docker compose -f docker/docker-compose.yml exec app composer phpstan

# Docker環境停止
docker compose -f docker/docker-compose.yml down

# ログ確認
docker compose -f docker/docker-compose.yml logs -f app
docker compose -f docker/docker-compose.yml logs -f storage
```

## 開発環境セットアップ

```bash
# ローカル環境での依存関係インストール
composer install
```

## アクセスポイント（Docker環境）
- Webサーバー: http://localhost:8080
- ValKey: localhost:6379

## 環境変数（テスト時）
- `SESSION_REDIS_HOST`: デフォルト `localhost`
- `SESSION_REDIS_PORT`: デフォルト `6379`
