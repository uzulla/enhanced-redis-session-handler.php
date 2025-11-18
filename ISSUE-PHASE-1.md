# [Phase 1] HookStorage: 設計・基盤実装

## 概要

issue #29「フック内Redis操作が他フックを通らない問題」を解決するため、HookStorageパターンの基盤となるインフラを実装します。

## 親タスク

- **親issue**: #29 - フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）
- **Phase**: 1/6
- **依存関係**: なし
- **後続Phase**: Phase 2（インターフェース拡張）

## 目的

HookStorageの基本インフラを構築し、フック内でのRedis操作を適切に管理できる仕組みを提供します。

## 実装タスク

### 1. HookStorageInterfaceの作成

**ファイル**: `src/Storage/HookStorageInterface.php`（約50行）

```php
<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Storage;

/**
 * フック内でRedis操作を行うためのインターフェース
 *
 * このインターフェースを経由することで、フック内のRedis操作も
 * 適切に他のフックチェーンを通るようになります。
 */
interface HookStorageInterface
{
    /**
     * フック内でRedisへデータを保存
     *
     * @param string $key Redis key
     * @param string $value 保存する値
     * @param int $ttl TTL（秒）
     * @return bool 成功時true、失敗時false
     */
    public function set(string $key, string $value, int $ttl): bool;

    /**
     * フック内でRedisからデータを取得
     *
     * @param string $key Redis key
     * @return string|false 値が存在する場合は文字列、存在しない場合はfalse
     */
    public function get(string $key): string|false;

    /**
     * フック内でRedisのデータを削除
     *
     * @param string $key Redis key
     * @return bool 削除成功時true、失敗時false
     */
    public function delete(string $key): bool;
}
```

**実装ポイント**:
- PSR-12準拠
- PHPStan strict rules準拠
- 戻り値の型は既存のRedisConnectionと統一

**見積もり**: 1時間

---

### 2. HookContextの作成

**ファイル**: `src/Storage/HookContext.php`（約80行）

実行深度の管理とコンテキスト情報を保持するクラスです。

**主な機能**:
- 実行深度のカウント（無限再帰防止）
- 深度制限の管理（デフォルト: 3階層）
- コンテキスト情報のスタック管理

**実装内容**:
```php
class HookContext
{
    private int $maxDepth = 3;
    private int $currentDepth = 0;

    public function enterHook(): void;
    public function exitHook(): void;
    public function getCurrentDepth(): int;
    public function isAtMaxDepth(): bool;
    public function setMaxDepth(int $depth): void;
}
```

**実装ポイント**:
- スレッドセーフである必要はない（PHPはシングルスレッド）
- 深度超過時の警告ログ出力
- 深度カウンターのリセット機能

**見積もり**: 2時間

---

### 3. HookRedisStorageの実装

**ファイル**: `src/Storage/HookRedisStorage.php`（約150行）

HookStorageInterfaceの実装クラスです。RedisConnectionをラップし、深度チェックロジックを実装します。

**主な機能**:
- RedisConnectionのラッパー
- 深度チェックによる無限再帰防止
- 深度制限到達時の直接実行モード
- PSR-3ロガー統合

**実装内容**:
```php
class HookRedisStorage implements HookStorageInterface
{
    private RedisConnection $connection;
    private HookContext $context;
    private LoggerInterface $logger;

    public function __construct(
        RedisConnection $connection,
        HookContext $context,
        LoggerInterface $logger
    );

    public function set(string $key, string $value, int $ttl): bool;
    public function get(string $key): string|false;
    public function delete(string $key): bool;
}
```

**実装ポイント**:
- 深度超過時は直接RedisConnectionを呼ぶ
- 深度超過時には警告ログを出力
- エラーハンドリングの統一
- 既存のRedisConnectionの動作を維持

**見積もり**: 3時間

---

### 4. HookRedisStorageTestの作成

**ファイル**: `tests/Storage/HookRedisStorageTest.php`（約200行）

HookRedisStorageの単体テストです。

**テストケース**:

1. **基本操作のテスト**
   - `testSetReturnsTrue`: 正常なset操作
   - `testGetReturnsValue`: 正常なget操作
   - `testDeleteReturnsTrue`: 正常なdelete操作
   - `testGetReturnsFalseWhenKeyNotExists`: 存在しないキーのget

2. **深度管理のテスト**
   - `testDepthIncrementsOnSet`: set操作で深度がインクリメント
   - `testDepthDecrementsAfterOperation`: 操作後に深度がデクリメント
   - `testMaxDepthPreventsInfiniteRecursion`: 最大深度で直接実行に切り替わる
   - `testWarningLoggedWhenMaxDepthReached`: 最大深度到達時に警告ログ

3. **エラーハンドリングのテスト**
   - `testSetHandlesRedisException`: Redis例外の適切な処理
   - `testGetHandlesConnectionFailure`: 接続失敗時の処理

4. **統合動作のテスト**
   - `testMultipleOperationsWithinDepthLimit`: 複数操作が正常動作
   - `testContextResetBetweenOperations`: 操作間でコンテキストがリセット

**実装ポイント**:
- RedisConnectionをモック化
- LoggerをTestHandlerで検証
- 実際のRedisは使用しない（単体テスト）
- PHPUnit 9.6準拠

**見積もり**: 3時間

---

## 技術的考慮事項

### 1. 深度制限のデフォルト値

**決定**: 3階層
- 理由: 通常のユースケース（例: LoggingHook → ReadTimestampHook → Redis）で十分
- 設定により変更可能

### 2. 深度超過時の動作

**決定**: 直接RedisConnectionを呼び、警告ログを出力
- 理由: 完全に失敗するよりも、機能は継続させる
- ログで異常を検知可能

### 3. パフォーマンスへの影響

**予想**: 深度チェックのオーバーヘッドは無視できるレベル
- int型のインクリメント/デクリメントのみ
- 必要に応じてPhase 6でベンチマーク実施

## 完了条件

- [ ] 全ファイルが作成され、PHPStan strict rulesをパス
- [ ] PHP CS Fixerでコードスタイルが統一
- [ ] 全単体テストがパス（カバレッジ > 90%）
- [ ] コードレビュー完了

## 見積もり

- **コード量**: 約480行（実装 約280行、テスト 約200行）
- **工数**: 約9時間
  - HookStorageInterface: 1時間
  - HookContext: 2時間
  - HookRedisStorage: 3時間
  - テストコード: 3時間

## 関連情報

- **親issue**: #29
- **設計ドキュメント**: `ISSUE-29-UPDATED.md`
- **次のPhase**: Phase 2 - インターフェース拡張

## ラベル

- enhancement
- priority: low
- type: implementation
- area: storage
- phase: 1/6
