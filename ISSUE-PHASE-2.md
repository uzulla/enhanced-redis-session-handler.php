# [Phase 2] HookStorage: インターフェース拡張（後方互換性維持）

## 概要

既存のフックインターフェースにHookStorageサポートを追加し、RedisSessionHandlerがHookStorageを提供できるようにします。後方互換性を維持するため、オプショナル引数として実装します。

## 親タスク

- **親issue**: #29 - フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）
- **Phase**: 2/6
- **依存関係**: Phase 1（HookStorage基盤実装）が完了していること
- **後続Phase**: Phase 3（既存フックの対応）

## 目的

既存のフックインターフェースを拡張し、HookStorageを利用可能にします。後方互換性を維持するため、既存コードへの影響は最小限とします。

## 実装タスク

### 1. ReadHookInterfaceの拡張

**ファイル**: `src/Hook/ReadHookInterface.php`（約10行の変更）

**変更内容**:
```php
interface ReadHookInterface
{
    public function beforeRead(string $sessionId): void;

    /**
     * Called after reading session data from Redis.
     *
     * @param string $sessionId The session ID
     * @param string $data The session data read from Redis
     * @param HookStorageInterface|null $storage Optional storage for hook operations (NEW!)
     * @return string The modified session data
     */
    public function afterRead(
        string $sessionId,
        string $data,
        ?HookStorageInterface $storage = null  // 追加：オプショナル
    ): string;

    public function onReadError(string $sessionId, Throwable $e): ?string;
}
```

**実装ポイント**:
- `$storage` パラメータはオプショナル（デフォルト `null`）
- 既存実装への影響なし（後方互換性維持）
- PHPDoc を更新して使用方法を明記

**見積もり**: 0.5時間

---

### 2. WriteHookInterfaceの拡張

**ファイル**: `src/Hook/WriteHookInterface.php`（約10行の変更）

**検討事項**:
WriteHookはセッションデータの書き込みを扱いますが、フック内でさらにRedis操作が必要なケースは少ないため、Phase 2では **拡張しない** 方向で進めます。

必要になった場合は後のPhaseで追加します。

**決定**: Phase 2ではWriteHookInterfaceは変更なし

**見積もり**: 0時間（スキップ）

---

### 3. RedisSessionHandlerの更新

**ファイル**: `src/RedisSessionHandler.php`（約30行の追加）

**変更内容**:

1. **HookStorageインスタンスの生成**
```php
class RedisSessionHandler implements SessionHandlerInterface
{
    private RedisConnection $connection;
    private ?HookStorageInterface $hookStorage = null;

    public function __construct(
        RedisConnection $connection,
        SessionSerializerInterface $serializer,
        ?RedisSessionHandlerOptions $options = null
    ) {
        $this->connection = $connection;
        $this->serializer = $serializer;

        // HookStorageの初期化
        $hookContext = new HookContext();
        $this->hookStorage = new HookRedisStorage(
            $connection,
            $hookContext,
            $options?->getLogger() ?? new NullLogger()
        );

        // 既存のコード...
    }
}
```

2. **read()メソッドでHookStorageを渡す**
```php
public function read($id)
{
    assert(is_string($id));

    foreach ($this->readHooks as $hook) {
        $hook->beforeRead($id);
    }

    try {
        $data = $this->connection->get($id);

        if ($data === false) {
            return '';
        }

        foreach ($this->readHooks as $hook) {
            // HookStorageを渡す（NEW!）
            $data = $hook->afterRead($id, $data, $this->hookStorage);
        }

        return $data;
    } catch (Throwable $e) {
        // エラーハンドリング...
    }
}
```

**実装ポイント**:
- HookContextは各RedisSessionHandlerインスタンスで独立
- 既存の動作を変更しない
- ログ出力は既存のロガーを使用

**見積もり**: 2時間

---

### 4. RedisSessionHandlerTestの更新

**ファイル**: `tests/RedisSessionHandlerTest.php`（約50行の追加）

**テストケース**:

1. **HookStorageの提供テスト**
   - `testReadPassesHookStorageToAfterRead`: afterRead()にHookStorageが渡される
   - `testHookStorageIsNotNullInAfterRead`: 渡されるHookStorageがnullでない
   - `testMultipleHooksReceiveSameHookStorage`: 複数フックが同じインスタンスを受け取る

2. **後方互換性のテスト**
   - `testReadWorksWithOldHooksWithoutStorageParam`: 旧フック（storageパラメータなし）が正常動作
   - `testMixedOldAndNewHooksWork`: 旧フックと新フックが共存可能

3. **統合動作のテスト**
   - `testHookCanUseStorageToSetData`: フックがstorageを使用してデータを設定できる
   - `testStorageOperationsAreIsolated`: storage操作が適切に分離される

**実装ポイント**:
- モックフックを作成してテスト
- 実際のRedisConnectionを使用（統合テスト的要素）
- TestHandlerでログ出力を検証

**見積もり**: 1時間

---

## 後方互換性の確認

### 既存コードへの影響

**影響なし**:
- 既存のフック実装（ReadTimestampHook、LoggingHook等）はそのまま動作
- `$storage` パラメータがオプショナルのため、引数を受け取らない実装も有効
- 既存のテストはすべてパス

**移行パス**:
```php
// 旧実装（引き続き動作）
public function afterRead(string $sessionId, string $data): string
{
    // storage を使わない実装
    return $data;
}

// 新実装（推奨）
public function afterRead(
    string $sessionId,
    string $data,
    ?HookStorageInterface $storage = null
): string {
    if ($storage !== null) {
        // storage を使った実装
    }
    return $data;
}
```

## 技術的考慮事項

### 1. HookContextのスコープ

**決定**: RedisSessionHandlerインスタンスごとに独立したHookContextを持つ
- 理由: 複数のセッションハンドラインスタンスが共存する可能性
- グローバルな状態を避ける

### 2. HookStorageのnullチェック

**推奨**: フック実装では必ずnullチェックを実行
```php
if ($storage !== null) {
    // storage を使用
}
```

### 3. パフォーマンスへの影響

**予想**: HookStorageインスタンスの生成コストは無視できるレベル
- コンストラクタでの1回のみの生成
- 軽量オブジェクト

## 完了条件

- [ ] ReadHookInterfaceが拡張され、PHPDocが更新されている
- [ ] RedisSessionHandlerがHookStorageを生成・提供している
- [ ] read()メソッドでHookStorageが各フックに渡される
- [ ] 全既存テストがパス（後方互換性確認）
- [ ] 新規テストが追加され、全てパス
- [ ] PHPStan strict rulesをパス
- [ ] PHP CS Fixerでコードスタイルが統一
- [ ] コードレビュー完了

## 見積もり

- **コード量**: 約100行（実装 約50行、テスト 約50行）
- **工数**: 約4時間
  - ReadHookInterface拡張: 0.5時間
  - RedisSessionHandler更新: 2時間
  - テストコード: 1時間
  - レビュー・調整: 0.5時間

## 関連情報

- **親issue**: #29
- **前Phase**: Phase 1 - 設計・基盤実装
- **次Phase**: Phase 3 - 既存フックの対応
- **設計ドキュメント**: `ISSUE-29-UPDATED.md`

## ラベル

- enhancement
- priority: low
- type: implementation
- area: hooks
- phase: 2/6
