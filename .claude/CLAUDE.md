# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## プロジェクト概要

enhanced-redis-session-handler.phpは、Redis/ValKeyをバックエンドストレージとして使用する、PHPの拡張可能なセッションハンドラライブラリです。

- **言語**: PHP 7.4+
- **プロジェクトタイプ**: Composerライブラリ
- **主要依存**: ext-redis, psr/log

## 開発コマンド

### テスト実行

```bash
# 全テストを実行
composer test

# カバレッジレポート（テキスト形式）
composer coverage

# カバレッジレポート（HTML形式、coverage/html/に生成）
composer coverage-report

# 特定のテストファイルを実行
vendor/bin/phpunit tests/RedisSessionHandlerTest.php

# 特定のテストメソッドを実行
vendor/bin/phpunit --filter testMethodName
```

### 静的解析とコードスタイル

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

### Docker環境

```bash
# Docker環境起動
docker compose -f docker/docker-compose.yml up -d

# ヘルスチェック
./docker/healthcheck.sh

# コンテナに入る
docker compose -f docker/docker-compose.yml exec app bash

# Docker内でテスト実行
docker compose -f docker/docker-compose.yml exec app composer test

# Docker環境停止
docker compose -f docker/docker-compose.yml down
```

## アーキテクチャ

### レイヤー構成

```
PHPアプリケーション
    ↓
RedisSessionHandler (SessionHandlerInterface実装)
    ↓ ↓ ↓
SessionIdGenerator / ReadHook / WriteHook
    ↓
RedisConnection (接続管理・エラーハンドリング)
    ↓
Redis/ValKey
```

### コアコンポーネント

- **SessionHandlerFactory**: ファクトリーパターンでハンドラを生成。設定を注入し、RedisSessionHandlerをビルド
- **RedisSessionHandler**: SessionHandlerInterfaceとSessionUpdateTimestampHandlerInterfaceを実装。セッションのCRUD操作とガベージコレクションを担当
- **SessionIdGeneratorInterface**: セッションID生成をカスタマイズ可能。デフォルト実装、Secure実装、プレフィックス付き実装などが用意されている
- **ReadHookInterface/WriteHookInterface**: セッションの読み込み・書き込み時にフック処理を挿入可能
- **RedisConnection**: Redis/ValKeyへの接続管理、リトライ、エラーハンドリングを担当

### 主要ディレクトリ構成

- `src/`: ライブラリの本体
  - `Config/`: 設定クラス（RedisConnectionConfig, SessionConfig等）
  - `SessionId/`: セッションIDジェネレータの実装
  - `Hook/`: Read/Writeフックの実装
  - `Exception/`: カスタム例外クラス
- `tests/`: テストコード
  - `Integration/`: 統合テスト
  - `E2E/`: エンドツーエンドテスト
  - `Support/`: テストサポートクラス
- `doc/`: 詳細ドキュメント
  - `architecture.md`: システムアーキテクチャ設計書
  - `specification.md`: 機能仕様書
  - `factory-usage.md`: ファクトリー使用ガイド
  - `redis-integration.md`: Redis/ValKey統合仕様
- `examples/`: 使用例のサンプルコード

## コーディング規約

- **コードスタイル**: PSR-12準拠（.php-cs-fixer.phpで定義）
- **静的解析**: PHPStan最大レベル + strict rules（phpstan.neonで定義）
- **テスト**: PHPUnit 9.6+を使用
- **名前空間**: `Uzulla\EnhancedRedisSessionHandler`

## テスト環境

- `phpunit.xml`で設定
- テスト実行時の環境変数:
  - `SESSION_REDIS_HOST`: デフォルト `localhost`
  - `SESSION_REDIS_PORT`: デフォルト `6379`
- Docker環境ではValKey（Redis互換）を使用

## CI/CD

GitHub Actionsで以下を自動実行:
- 静的解析（PHPStan）
- コードスタイルチェック
- テスト実行
- Docker環境でのテスト

## 重要な実装パターン

### SessionHandlerFactoryの使用

新しいセッションハンドラインスタンスを作成する際は、必ずSessionHandlerFactoryを使用してください:

```php
$config = new SessionConfig(
    new RedisConnectionConfig(),
    new DefaultSessionIdGenerator(),
    (int)ini_get('session.gc_maxlifetime'),
    new NullLogger()
);
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();
```

### フックの実装

ReadHookInterfaceやWriteHookInterfaceを実装する際は、既存の実装（LoggingHook, ReadTimestampHookなど）を参考にしてください。

### エラーハンドリング

- Redis操作のエラーは`RedisSessionException`およびそのサブクラスでハンドリング
- 接続エラー: `ConnectionException`
- 設定エラー: `ConfigurationException`
- データエラー: `SessionDataException`

## ライブラリとしての特性

このプロジェクトはライブラリであるため:
- `composer.lock`はリポジトリにコミットされていません
- CIは毎回最新の互換性のある依存関係を解決します
- 破壊的変更を避け、後方互換性を重視してください
