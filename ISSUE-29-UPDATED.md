# Issue #29: フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）

## 📋 問題の詳細

### 現状

現在、ReadTimestampHookなどのフック内でRedisConnectionを直接呼び出しているため、フック内でのRedis操作が他のフックを通りません。

**具体的な問題箇所:**

1. **ReadTimestampHook** (`src/Hook/ReadTimestampHook.php:71`)
   ```php
   $this->connection->set($timestampKey, $timestamp, $this->timestampTtl);
   // ← LoggingHook や他のWriteHookが実行されない
   ```

2. **FallbackReadHook** (`src/Hook/FallbackReadHook.php:56`)
   ```php
   $data = $connection->get($sessionId);
   // ← 他のReadHookが実行されない
   ```

3. **DoubleWriteHook** (`src/Hook/DoubleWriteHook.php:80`)
   ```php
   $this->secondaryConnection->set($sessionId, $serializedData, $this->ttl);
   // ← セカンダリへの書き込みもWriteHookを通らない
   ```

### 影響

- LoggingHookでフック内のRedis操作をログ記録できない
- フックの合成可能性（composability）が低い
- デバッグやモニタリングが困難

### 発生シナリオ例

```
構成: LoggingHook + ReadTimestampHook

期待される動作:
1. セッション読み込み → LoggingHookでログ記録 ✓
2. タイムスタンプ記録 → LoggingHookでログ記録 ✓

実際の動作:
1. セッション読み込み → LoggingHookでログ記録 ✓
2. タイムスタンプ記録 → ログ記録されない ✗
```

## 💡 解決策：HookStorageパターンの導入

### アプローチ

フック専用のストレージレイヤー（`HookStorageInterface`）を導入し、フック内でのRedis操作も適切にフックチェーンを通るようにします。

### アーキテクチャ図

```
Before:
┌─────────────────────────┐
│ RedisSessionHandler     │
│  - ReadHook.afterRead() │
│      ↓                  │
│    connection.set()     │ ← 他のフックを通らない！
└─────────────────────────┘

After:
┌─────────────────────────┐
│ RedisSessionHandler     │
│  - hookStorage          │
│  - ReadHook.afterRead(  │
│      sessionId,         │
│      data,              │
│      hookStorage ←━━━━  │ 追加
│    )                    │
│      ↓                  │
│    hookStorage.set()    │
│      ↓                  │
│    [WriteHookチェーン]  │ ← 実行される！
│      ↓                  │
│    connection.set()     │
└─────────────────────────┘
```

### 主な設計ポイント

1. **後方互換性の維持**
   - `HookStorageInterface`の引数はオプショナル
   - 既存コードはそのまま動作

2. **無限再帰の防止**
   - 実行深度チェックを実装
   - 最大深度（デフォルト: 3）を超えた場合は直接実行

3. **段階的移行**
   - 各フックを個別に対応可能
   - 急いで全体を変更する必要なし

## 🚀 実装計画

### Phase 1: 設計・基盤実装（コア部分）

**目的**: HookStorageの基本インフラを構築

- [ ] `src/Storage/HookStorageInterface.php` を作成（約50行）
  - `set(string $key, string $value, int $ttl): bool`
  - `get(string $key): string|false`
  - `delete(string $key): bool`
- [ ] `src/Storage/HookContext.php` を作成（約80行）
  - 実行深度の管理
  - コンテキスト情報の保持
  - 無限再帰防止メカニズム
- [ ] `src/Storage/HookRedisStorage.php` を実装（約150行）
  - HookStorageInterfaceの実装
  - RedisConnectionのラッパー
  - 深度チェックロジック
- [ ] `tests/Storage/HookRedisStorageTest.php` を作成（約200行）
  - 基本操作のテスト
  - 無限再帰防止のテスト
  - 深度制限のテスト

**見積もり**: 約9時間

### Phase 2: インターフェース拡張（後方互換性維持）

**目的**: 既存のフックインターフェースにHookStorageサポートを追加

- [ ] `src/Hook/ReadHookInterface.php` を拡張（約10行）
  - `afterRead()` に `?HookStorageInterface $storage = null` を追加
- [ ] `src/Hook/WriteHookInterface.php` を拡張（約10行）
  - 必要に応じて `beforeWrite()` / `afterWrite()` にstorageを追加
- [ ] `src/RedisSessionHandler.php` を更新（約30行）
  - `HookRedisStorage` インスタンスの生成
  - フック呼び出し時にstorageを渡す
- [ ] `tests/RedisSessionHandlerTest.php` を更新（約50行）
  - HookStorageが正しく渡されることを確認

**見積もり**: 約4時間

### Phase 3: 既存フックの対応

**目的**: 主要フックをHookStorageに対応させる

- [ ] `src/Hook/ReadTimestampHook.php` を更新（約40行）
  - storage引数の追加
  - storage経由でのタイムスタンプ記録
  - 後方互換性の維持（storageがnullの場合は従来の動作）
- [ ] `tests/Hook/ReadTimestampHookTest.php` を更新（約80行）
  - storage使用時のテスト
  - storage未使用時のテスト（後方互換性）
- [ ] `src/Hook/DoubleWriteHook.php` を更新（約40行）
  - storage経由でのセカンダリ書き込み（オプション）
  - 設定により直接書き込みも可能
- [ ] `tests/Hook/DoubleWriteHookTest.php` を更新（約80行）
  - 両パターンのテスト

**見積もり**: 約8時間

### Phase 4: 統合テスト

**目的**: フックの組み合わせと実際のRedisを使った動作確認

- [ ] `tests/Integration/HookStorageIntegrationTest.php` を作成（約250行）
  - LoggingHook + ReadTimestampHook の組み合わせテスト
  - フック内Redis操作がログ記録されることを確認
  - 無限再帰が発生しないことを確認
  - 深度制限の動作確認
  - 複数フックの連鎖テスト
  - エラーハンドリングのテスト

**見積もり**: 約4時間

### Phase 5: ドキュメントとサンプル

**目的**: 開発者が理解・使用できるように文書化

- [ ] `doc/hook-storage-design.md` を作成
  - 設計の背景と目的
  - アーキテクチャの詳細
  - 無限再帰防止の仕組み
  - パフォーマンスへの影響
- [ ] `doc/write-hooks.md` を更新
  - HookStorageの使用方法を追記
  - ベストプラクティスを追加
  - 移行ガイド
- [ ] `doc/hook-best-practices.md` を作成
  - フック開発のベストプラクティス
  - storageを使うべきケース
  - 直接アクセスが適切なケース
- [ ] `examples/hook-storage-example.php` を作成（約100行）
  - HookStorageの基本的な使用例
  - LoggingHook + ReadTimestampHook の実例
- [ ] `examples/logging-with-timestamp.php` を作成（約80行）
  - 実践的なサンプル
  - 実際のアプリケーションでの使用例
- [ ] `README.md` を更新（必要に応じて）
  - HookStorageパターンへの言及

**見積もり**: 約8.5時間

### Phase 6: 品質保証

**目的**: コード品質とテストカバレッジの確保

- [ ] PHPStan チェック・修正
  - 型エラーの解消
  - strict rules準拠の確認
- [ ] PHP CS Fixer 実行
  - コードスタイルの統一
- [ ] 全テスト実行・修正
  - `composer test` が全てパス
  - 新規追加テストの確認
  - 既存テストへの影響確認
- [ ] カバレッジレポート確認
  - 新規コードのカバレッジ > 90%
- [ ] コードレビュー対応予備時間
  - レビューフィードバックへの対応

**見積もり**: 約5.5時間

## 📊 全体見積もり

| Phase | 内容 | 新規/変更コード | 見積もり |
|-------|------|----------------|----------|
| Phase 1 | 設計・基盤実装 | 約480行 | 9時間 |
| Phase 2 | インターフェース拡張 | 約100行 | 4時間 |
| Phase 3 | 既存フック対応 | 約240行 | 8時間 |
| Phase 4 | 統合テスト | 約250行 | 4時間 |
| Phase 5 | ドキュメント | 約180行 | 8.5時間 |
| Phase 6 | 品質保証 | - | 5.5時間 |
| **合計** | | **約1,250行** | **39時間** |

**実質期間**: 1〜2週間（レビュー・調整時間を含む）

## 🔍 技術的検討事項

### 1. FallbackReadHookの扱い

FallbackReadHookは `onReadError()` 内で他のRedisConnectionからデータを取得します。

**決定**: 例外的に直接アクセスを許可
- 理由: 無限再帰のリスク（ReadError → Fallback → ReadError → ...）
- フォールバックは特殊なケースとして扱う

### 2. DoubleWriteHookのセカンダリRedis

セカンダリRedisへの書き込みもフックを通すべきか？

**決定**: オプション引数で制御可能に
- デフォルト: 直接書き込み（パフォーマンス重視）
- オプション: storage経由（完全なフック実行）

### 3. パフォーマンスへの影響

深度チェックやコンテキスト管理のオーバーヘッド

**対応**:
- ベンチマークテストの実施
- 目標: 5%以下のオーバーヘッド
- 必要に応じて最適化

### 4. 無限再帰防止の深度制限

**デフォルト値**: 3階層
- 通常のユースケースでは十分
- 設定により変更可能
- 制限に達した場合は警告ログ

## ✅ 完了条件

- [ ] 全Phaseのタスクが完了
- [ ] `composer test` が全てパス
- [ ] `composer phpstan` がエラーなし
- [ ] `composer cs-check` がエラーなし
- [ ] カバレッジレポートで新規コードのカバレッジ > 90%
- [ ] ドキュメントが完成し、レビュー済み
- [ ] サンプルコードが動作確認済み
- [ ] 既存の全機能が正常動作（後方互換性確認）

## 📚 関連情報

- **元のissue**: #29
- **関連PR**: #28 (レビューコメント: https://github.com/uzulla/enhanced-redis-session-handler.php/pull/28#discussion_r2454941083)
- **設計ドキュメント**: `doc/hook-storage-design.md` (実装後に作成)
- **アーキテクチャドキュメント**: `doc/architecture.md` (更新必要)

## 🏷️ ラベル

- enhancement
- priority: low
- type: refactoring
- area: hooks
- size: large (1-2週間)

## 優先度

**低** - 現在の実装で動作しますが、将来的な改善として価値があります。
ただし、実装規模は中〜大程度（1-2週間）となります。
