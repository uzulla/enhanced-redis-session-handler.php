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

# PHPMD実行（コード品質チェック）
composer phpmd

# コードスタイルチェック（PSR-12準拠）
composer cs-check

# コードスタイル自動修正
composer cs-fix

# 全てのlintチェックを実行（PHPStan + PHPMD + CS Fixer）
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

```text
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

## セキュリティ考慮事項

### セッションIDのログ出力

セッションIDは機密情報のため、ログ出力時は必ずマスキングすること:

```php
private function maskSessionId(string $sessionId): string
{
    if (strlen($sessionId) <= 4) {
        return '...' . $sessionId;
    }
    return '...' . substr($sessionId, -4);
}

// ログ出力例
$this->logger->debug('Session operation', [
    'session_id' => $this->maskSessionId($sessionId),
]);
```

**重要**: 生のセッションIDをログに記録すると、ログ漏洩時にセッションハイジャックのリスクがあります。末尾4文字のみ表示することで、デバッグ時の相関分析は可能にしつつセキュリティを確保します。

### 入力検証

設定クラス（Config配下）では、コンストラクタで必ず入力検証を実施:
- ポート番号: 1-65535の範囲チェック
- タイムアウト値: 非負の値チェック
- TTL値: 正の値チェック
- 配列パラメータ: 空でないことのチェック

無効な値の場合は `InvalidArgumentException` を投げてください。

## コミット規約

- コミットメッセージは日本語で記述
- 1行で変更の理由（WHY）を説明
- 複数の独立した修正は別々のコミットに分割

### コミット・プッシュ前の必須チェック

**重要**: コミット・プッシュ前に必ず以下の手順を実行してください：

#### 1. Redis環境のセットアップ

Docker環境が利用可能な場合:

```bash
docker compose -f docker/docker-compose.yml up -d
./docker/healthcheck.sh
```

Docker環境が利用できない場合、システムRedisを使用:

```bash
# Redisが起動しているか確認
redis-cli ping

# 起動していない場合はRedisを起動
redis-server --daemonize yes --port 6379

# 起動確認
redis-cli ping  # 'PONG'が返ればOK
```

#### 2. 静的解析とコードスタイルチェック

```bash
# PHPStan実行（エラーがないこと）
composer phpstan

# PHPMD実行（エラーがないこと）
composer phpmd

# コードスタイルチェック（修正が必要なファイルがないこと）
composer cs-check
```

エラーがある場合は修正してください：

```bash
# コードスタイルを自動修正
composer cs-fix
```

#### 3. テスト実行

```bash
# 全テストを実行（すべてパスすること）
composer test
```

**注意**: テストが失敗する場合は、必ずRedis環境が起動していることを確認してください。多くの統合テストはRedisが必要です。

#### 4. 環境のクリーンアップ（オプション）

テスト完了後、必要に応じてRedis環境を停止:

```bash
# システムRedisの場合
redis-cli shutdown save

# Docker環境の場合
docker compose -f docker/docker-compose.yml down
```

### コミット・プッシュのワークフロー例

```bash
# 1. Redis起動
redis-server --daemonize yes --port 6379

# 2. 静的解析
composer phpstan && composer cs-check

# 3. テスト実行
composer test

# 4. すべてパスしたらコミット
git add .
git commit -m "変更内容の説明"

# 5. プッシュ
git push -u origin ブランチ名

# 6. Redis停止（必要に応じて）
redis-cli shutdown save
```

## Git操作のベストプラクティス

PRレビュー対応時:
1. 各指摘事項ごとに個別のコミットを作成
2. コミットメッセージでレビュー指摘の理由を説明
3. 全修正完了後、PRにまとめコメントを投稿
4. 上記の「コミット・プッシュ前の必須チェック」をすべて実行
