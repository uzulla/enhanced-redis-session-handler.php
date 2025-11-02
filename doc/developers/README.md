# 開発者向けドキュメント

このディレクトリには、enhanced-redis-session-handler.phpライブラリの開発者およびコラボレータ向けのドキュメントが含まれています。

## 目次

### コアドキュメント

- **[architecture.md](architecture.md)** - システム全体のアーキテクチャ設計
  - レイヤー構成とコンポーネント関係
  - データフローとクラス構成図
  - 設計思想と拡張ポイント

### 実装詳細（implementation/）

- **[session-handler.md](implementation/session-handler.md)** - RedisSessionHandler実装詳細
  - SessionHandlerInterfaceの実装
  - SessionUpdateTimestampHandlerInterfaceの実装
  - 内部処理フローとエラーハンドリング

- **[serializer.md](implementation/serializer.md)** - Serializer機構
  - SessionSerializerInterfaceの設計
  - PhpSerializer / PhpSerializeSerializerの実装
  - カスタムSerializerの作成方法

- **[hooks-and-filters.md](implementation/hooks-and-filters.md)** - Hook/Filter機構
  - ReadHook / WriteHookの実装詳細
  - WriteFilterの設計と実装
  - フック実行順序と例外処理

- **[connection.md](implementation/connection.md)** - Redis接続管理
  - RedisConnectionクラスの実装
  - 接続プーリングとリトライ戦略
  - エラーハンドリングとログ出力

- **[prevent-empty-cookie.md](implementation/prevent-empty-cookie.md)** - PreventEmptySessionCookie機能
  - 設計の背景と議論
  - 実装詳細
  - 使用方法とベストプラクティス

### 開発プロセス

- **[testing.md](testing.md)** - テスト戦略と実行方法
  - ユニットテスト / 統合テスト / E2Eテスト
  - テストカバレッジ目標
  - テスト実行コマンド

- **[code-style.md](code-style.md)** - コーディング規約
  - PSR-12準拠のコードスタイル
  - PHPStan設定（最大レベル + strict rules）
  - 命名規則とベストプラクティス

- **[contributing.md](contributing.md)** - コントリビューションガイド
  - プルリクエストの作成方法
  - コミットメッセージ規約
  - レビュープロセス

## 関連ドキュメント

### プラグイン開発者向け
プラグイン（Hook、Filter、Serializer等）を作成したい場合は、[plugin-developers/](../plugin-developers/)ディレクトリを参照してください。

### ライブラリ利用者向け
ライブラリの使い方を知りたい場合は、[users/](../users/)ディレクトリまたはルートの[README.md](../../README.md)を参照してください。

## クイックリンク

### 開発環境セットアップ
詳細は[DEVELOPMENT.md](../../DEVELOPMENT.md)を参照してください。

```bash
# Docker環境起動
docker compose -f docker/docker-compose.yml up -d

# 依存関係インストール
composer install

# テスト実行
composer test

# 静的解析
composer phpstan

# コードスタイルチェック
composer cs-check
```

### ディレクトリ構造

```
src/
├── Config/              設定クラス
│   ├── RedisConnectionConfig.php
│   ├── SessionConfig.php
│   └── RedisSessionHandlerOptions.php
├── Exception/           カスタム例外
│   ├── RedisSessionException.php
│   ├── ConnectionException.php
│   ├── OperationException.php
│   ├── SessionDataException.php
│   ├── ConfigurationException.php
│   └── HookException.php
├── Hook/                フック・フィルター
│   ├── ReadHookInterface.php
│   ├── WriteHookInterface.php
│   ├── WriteFilterInterface.php
│   ├── LoggingHook.php
│   ├── DoubleWriteHook.php
│   ├── ReadTimestampHook.php
│   ├── FallbackReadHook.php
│   └── EmptySessionFilter.php
├── Serializer/          シリアライザ
│   ├── SessionSerializerInterface.php
│   ├── PhpSerializer.php
│   └── PhpSerializeSerializer.php
├── Session/             セッション関連ユーティリティ
│   └── PreventEmptySessionCookie.php
├── SessionId/           セッションIDジェネレータ
│   ├── SessionIdGeneratorInterface.php
│   ├── DefaultSessionIdGenerator.php
│   ├── SecureSessionIdGenerator.php
│   ├── PrefixedSessionIdGenerator.php
│   └── TimestampPrefixedSessionIdGenerator.php
├── Support/             サポートユーティリティ
│   └── SessionIdMasker.php
├── RedisConnection.php  Redis接続管理
├── RedisSessionHandler.php  メインセッションハンドラ
└── SessionHandlerFactory.php  ファクトリークラス
```

### アーキテクチャ概要図

```
┌─────────────────────────────────────────────────────┐
│           PHPアプリケーション層                        │
│  (session_start(), $_SESSION, session_write_close()) │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│         セッションハンドラ層                           │
│  ┌─────────────────────────────────────────────┐   │
│  │      RedisSessionHandler                     │   │
│  │  (SessionHandlerInterface実装)               │   │
│  └─────────────────────────────────────────────┘   │
│           ↓              ↓              ↓            │
│  ┌──────────────┐ ┌──────────┐ ┌──────────────┐   │
│  │SessionId     │ │Serializer│ │Hook/Filter   │   │
│  │Generator     │ │          │ │Manager       │   │
│  └──────────────┘ └──────────┘ └──────────────┘   │
│                        ↓              ↓              │
│                   ┌─────────┐  ┌─────────────┐     │
│                   │ReadHook │  │WriteHook    │     │
│                   └─────────┘  │WriteFilter  │     │
│                                └─────────────┘     │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│           Redis接続管理層                             │
│  ┌─────────────────────────────────────────────┐   │
│  │      RedisConnection                         │   │
│  │  (接続管理、エラーハンドリング)                 │   │
│  └─────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│              Redis/ValKey                            │
│         (セッションデータストレージ)                    │
└─────────────────────────────────────────────────────┘
```

## 重要な設計原則

### 1. 拡張性優先
- プラグイン可能なインターフェース設計（SessionIdGenerator, Serializer, Hook, Filter）
- 依存性注入によるコンポーネントの差し替え可能性
- フック機構による機能追加の容易性

### 2. セキュリティファースト
- セッションIDのログマスキング（SessionIdMasker）
- 暗号学的に安全な乱数生成
- 入力検証の徹底（Configクラス）

### 3. パフォーマンス考慮
- Redis接続の再利用
- TTLによる自動ガベージコレクション
- 効率的なシリアライゼーション

### 4. 後方互換性の維持
- ライブラリとしての責任
- セマンティックバージョニングの遵守
- 非推奨化プロセスの明確化

## 次のステップ

1. **新規開発者**
   - [architecture.md](architecture.md)でシステム全体を理解
   - [DEVELOPMENT.md](../../DEVELOPMENT.md)で環境をセットアップ
   - [testing.md](testing.md)でテストの実行方法を確認

2. **機能追加**
   - [architecture.md](architecture.md)で拡張ポイントを確認
   - 該当する`implementation/`配下のドキュメントを参照
   - [contributing.md](contributing.md)に従ってPRを作成

3. **バグ修正**
   - [testing.md](testing.md)でテストを追加・実行
   - 該当コンポーネントの実装ドキュメントを参照
   - [code-style.md](code-style.md)に従ってコーディング
