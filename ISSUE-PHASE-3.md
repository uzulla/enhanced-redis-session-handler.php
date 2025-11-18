# [Phase 3] HookStorage: 既存フックの対応

## 概要

主要な既存フック（ReadTimestampHook、DoubleWriteHook）をHookStorageに対応させます。後方互換性を維持しつつ、新しいHookStorage経由のRedis操作をサポートします。

## 親タスク

- **親issue**: #29 - フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）
- **Phase**: 3/6
- **依存関係**: Phase 2（インターフェース拡張）が完了していること
- **後続Phase**: Phase 4（統合テスト）

## 目的

既存フックがHookStorageを活用できるようにし、フック内のRedis操作が他のフックチェーンを通るようにします。

## 実装タスク

### 1. ReadTimestampHookの更新

**ファイル**: `src/Hook/ReadTimestampHook.php`（約40行の追加・変更）

**変更内容**:

1. **afterRead()メソッドのシグネチャ更新**
```php
public function afterRead(
    string $sessionId,
    string $data,
    ?HookStorageInterface $storage = null  // 追加
): string {
    if ($storage !== null) {
        // 新方式：HookStorageを使用（推奨）
        $this->recordTimestampViaStorage($sessionId, $storage);
    } else {
        // 旧方式：RedisConnectionを直接使用（後方互換性）
        $this->recordReadTimestamp($sessionId);
    }
    return $data;
}
```

2. **新しいメソッドの追加**
```php
/**
 * HookStorage経由でタイムスタンプを記録（推奨方式）
 *
 * この方式では、タイムスタンプの記録操作も他のフック（LoggingHook等）を通ります。
 *
 * @param string $sessionId
 * @param HookStorageInterface $storage
 */
private function recordTimestampViaStorage(
    string $sessionId,
    HookStorageInterface $storage
): void {
    try {
        $timestampKey = $this->timestampKeyPrefix . $sessionId;
        $timestamp = (string) time();
        $storage->set($timestampKey, $timestamp, $this->timestampTtl);

        $this->logger->debug('Recorded session read timestamp via HookStorage', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'timestamp' => $timestamp,
        ]);
    } catch (Throwable $e) {
        $this->logger->warning('Failed to record session read timestamp', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'exception' => $e,
        ]);
    }
}
```

3. **既存メソッドの保持**
```php
/**
 * RedisConnection直接でタイムスタンプを記録（従来方式）
 *
 * 後方互換性のため維持。新規実装では recordTimestampViaStorage() を使用してください。
 *
 * @deprecated 将来のバージョンで削除予定。HookStorage経由の使用を推奨。
 */
private function recordReadTimestamp(string $sessionId): void
{
    // 既存のコードをそのまま維持
    try {
        $timestampKey = $this->timestampKeyPrefix . $sessionId;
        $timestamp = (string) time();
        $this->connection->set($timestampKey, $timestamp, $this->timestampTtl);

        $this->logger->debug('Recorded session read timestamp', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'timestamp' => $timestamp,
        ]);
    } catch (Throwable $e) {
        $this->logger->warning('Failed to record session read timestamp', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'exception' => $e,
        ]);
    }
}
```

**実装ポイント**:
- 後方互換性の完全な維持
- 新方式を推奨するPHPDocコメント
- エラーハンドリングは既存と同様
- ログメッセージで方式を区別

**見積もり**: 2時間

---

### 2. ReadTimestampHookTestの更新

**ファイル**: `tests/Hook/ReadTimestampHookTest.php`（約80行の追加）

**新規テストケース**:

1. **HookStorage使用時のテスト**
   - `testAfterReadUsesHookStorageWhenProvided`: HookStorageが提供された場合に使用される
   - `testTimestampIsStoredViaHookStorage`: タイムスタンプがHookStorage経由で保存される
   - `testHookStorageSetIsCalledWithCorrectParameters`: 正しいパラメータでset()が呼ばれる
   - `testLogMessageIndicatesHookStorageUsage`: ログメッセージにHookStorage使用が記録される

2. **後方互換性のテスト**
   - `testAfterReadUsesDirectConnectionWhenStorageIsNull`: storageがnullの場合は直接接続を使用
   - `testOldBehaviorIsPreserved`: 既存の動作が保持される
   - `testBothMethodsProduceSameResult`: 両方式で同じ結果が得られる

3. **エラーハンドリングのテスト**
   - `testHandlesHookStorageException`: HookStorageの例外を適切に処理
   - `testWarningLoggedOnHookStorageFailure`: 失敗時に警告ログが出力される

**実装ポイント**:
- HookStorageをモック化
- TestHandlerでログ検証
- 既存テストは全て維持
- カバレッジ > 90%

**見積もり**: 2時間

---

### 3. DoubleWriteHookの更新

**ファイル**: `src/Hook/DoubleWriteHook.php`（約40行の追加・変更）

**検討事項**:
DoubleWriteHookはセカンダリRedisへの書き込みを行います。この書き込みもフックを通すべきか？

**決定**: オプションとして実装
- デフォルト: 直接書き込み（パフォーマンス重視）
- オプション: HookStorage経由（完全なフック実行）

**変更内容**:

1. **コンストラクタにオプション追加**
```php
private bool $useHookStorageForSecondary = false;

public function __construct(
    RedisConnection $secondaryConnection,
    int $ttl = 1440,
    bool $failOnSecondaryError = false,
    ?LoggerInterface $logger = null,
    bool $useHookStorageForSecondary = false  // NEW!
) {
    // 既存のコード...
    $this->useHookStorageForSecondary = $useHookStorageForSecondary;
}
```

2. **beforeWrite()でHookStorageを保存**
```php
private ?HookStorageInterface $currentStorage = null;

public function beforeWrite(
    string $sessionId,
    array $data,
    ?HookStorageInterface $storage = null  // 引数を追加
): array {
    $this->pendingWrites[$sessionId] = $data;
    $this->currentStorage = $storage;  // 保存
    return $data;
}
```

3. **afterWrite()で条件付き使用**
```php
public function afterWrite(string $sessionId, bool $success): void
{
    if (!$success) {
        // 既存のコード...
        return;
    }

    // ...

    try {
        $data = $this->pendingWrites[$sessionId];
        $serializedData = serialize($data);

        if ($this->useHookStorageForSecondary && $this->currentStorage !== null) {
            // HookStorage経由（フックを通る）
            $secondarySuccess = $this->currentStorage->set(
                $sessionId,
                $serializedData,
                $this->ttl
            );
        } else {
            // 直接書き込み（従来方式）
            $secondarySuccess = $this->secondaryConnection->set(
                $sessionId,
                $serializedData,
                $this->ttl
            );
        }

        // 既存のエラーハンドリング...
    } finally {
        unset($this->pendingWrites[$sessionId]);
        $this->currentStorage = null;  // クリーンアップ
    }
}
```

**実装ポイント**:
- デフォルトは従来の動作（破壊的変更なし）
- オプトインで新機能を有効化
- セカンダリ書き込みは通常フックを通す必要はない（別系統）

**見積もり**: 2時間

---

### 4. DoubleWriteHookTestの更新

**ファイル**: `tests/Hook/DoubleWriteHookTest.php`（約80行の追加）

**新規テストケース**:

1. **デフォルト動作のテスト（既存維持）**
   - `testSecondaryWriteUsesDirectConnectionByDefault`: デフォルトは直接接続
   - `testExistingBehaviorIsPreserved`: 既存の動作が保持される

2. **HookStorage使用時のテスト**
   - `testSecondaryWriteUsesHookStorageWhenEnabled`: オプション有効時はHookStorageを使用
   - `testHookStorageIsPassedToSecondaryWrite`: HookStorageが正しく渡される
   - `testSecondaryWriteThroughHookStorageLogs`: HookStorage経由の書き込みがログされる

3. **混在シナリオのテスト**
   - `testMixedStorageAndDirectWrites`: storage使用と直接書き込みの混在
   - `testStorageIsCleanedUpAfterWrite`: storage参照が適切にクリーンアップされる

**実装ポイント**:
- 既存テストは全て維持
- 新機能は追加テストで検証
- モックを使用して両パターンを検証

**見積もり**: 2時間

---

## FallbackReadHookについて

**決定**: Phase 3では対応しない

**理由**:
- FallbackReadHookは `onReadError()` 内で他のRedisConnectionからフォールバック読み取りを実行
- この操作を他のReadHookを通すと、無限再帰のリスクがある
  - ReadError → FallbackHook → ReadError → FallbackHook → ...
- フォールバックは特殊なケースとして、直接アクセスが適切

**将来的な検討**:
- 必要であれば、フォールバック専用のReadHookチェーンを別途実装する可能性

---

## 技術的考慮事項

### 1. 非推奨アノテーション

**決定**: `@deprecated` タグは使用するが、実際の削除は当面予定しない
- 理由: 安定性を重視、急いで削除する必要はない
- 将来のメジャーバージョンアップ時に検討

### 2. ログメッセージの区別

**推奨**: HookStorage使用時と直接接続使用時でログメッセージを区別
```php
// HookStorage使用時
'Recorded session read timestamp via HookStorage'

// 直接接続使用時
'Recorded session read timestamp'  // 既存のまま
```

### 3. パフォーマンスへの影響

**予想**: 影響は最小限
- 条件分岐（if文）のオーバーヘッドのみ
- 実際のRedis操作のコストが支配的

## 完了条件

- [ ] ReadTimestampHookがHookStorageに対応
- [ ] DoubleWriteHookがオプションでHookStorageに対応
- [ ] 全既存テストがパス（後方互換性確認）
- [ ] 新規テストが追加され、全てパス
- [ ] テストカバレッジ > 90%
- [ ] PHPStan strict rulesをパス
- [ ] PHP CS Fixerでコードスタイルが統一
- [ ] コードレビュー完了

## 見積もり

- **コード量**: 約240行（実装 約80行、テスト 約160行）
- **工数**: 約8時間
  - ReadTimestampHook更新: 2時間
  - ReadTimestampHookTest更新: 2時間
  - DoubleWriteHook更新: 2時間
  - DoubleWriteHookTest更新: 2時間

## 関連情報

- **親issue**: #29
- **前Phase**: Phase 2 - インターフェース拡張
- **次Phase**: Phase 4 - 統合テスト
- **設計ドキュメント**: `ISSUE-29-UPDATED.md`

## ラベル

- enhancement
- priority: low
- type: implementation
- area: hooks
- phase: 3/6
