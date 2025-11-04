# Filter作成ガイド

## 概要

WriteFilterは、セッションの書き込み操作自体をキャンセルするかどうかを判断する仕組みです。WriteHookがデータを変換するのに対し、WriteFilterは「書き込むか/書き込まないか」を判断します。

## WriteHookとの違い

```
┌────────────────────────────────┐
│ WriteFilter                    │
│ - shouldWrite()がfalseを返す    │
│ - 書き込み処理全体をスキップ    │
│ - 例: 空セッションは書き込まない │
└────────────────────────────────┘
              ↓ (true)
┌────────────────────────────────┐
│ WriteHook                      │
│ - beforeWrite()でデータを変換   │
│ - 例: 暗号化、圧縮、検証        │
└────────────────────────────────┘
```

## WriteFilterInterface

### インターフェース

```php
namespace Uzulla\EnhancedRedisSessionHandler\Hook;

interface WriteFilterInterface
{
    /**
     * セッションデータを書き込むべきかを判断
     *
     * @param string $sessionId
     * @param array<string, mixed> $data デシリアライズされたセッションデータ
     * @return bool true=書き込む、false=書き込まない
     */
    public function shouldWrite(string $sessionId, array $data): bool;
}
```

## 実装例

### 例1: 空セッションフィルター

標準実装の`EmptySessionFilter`：

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Psr\Log\LoggerInterface;

class EmptySessionFilter implements WriteFilterInterface
{
    private LoggerInterface $logger;
    private bool $lastWriteWasEmpty = false;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        $isEmpty = count($data) === 0;
        $this->lastWriteWasEmpty = $isEmpty;

        if ($isEmpty) {
            $this->logger->debug(
                'Empty session detected, write operation cancelled',
                ['session_id' => SessionIdMasker::mask($sessionId)]
            );
            return false; // 書き込みキャンセル
        }

        $this->logger->debug(
            'Session has data, write operation allowed',
            [
                'session_id' => SessionIdMasker::mask($sessionId),
                'data_keys' => array_keys($data),
            ]
        );
        return true; // 書き込み許可
    }

    /**
     * 最後の書き込みが空だったかチェック
     */
    public function wasLastWriteEmpty(): bool
    {
        return $this->lastWriteWasEmpty;
    }
}
```

**使用例**:

```php
$filter = new EmptySessionFilter($logger);
$handler->addWriteFilter($filter);

// セッションが空の場合
$_SESSION = [];
session_write_close();
// → Redisへの書き込みがスキップされる

// セッションにデータがある場合
$_SESSION = ['user_id' => 123];
session_write_close();
// → Redisに書き込まれる
```

### 例2: 読み取り専用モードフィルター

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;

class ReadOnlyModeFilter implements WriteFilterInterface
{
    private bool $readOnlyMode;

    public function __construct(bool $readOnlyMode = false)
    {
        $this->readOnlyMode = $readOnlyMode;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        if ($this->readOnlyMode) {
            error_log("Session write blocked: read-only mode enabled");
            return false; // 読み取り専用モードでは書き込まない
        }

        return true; // 通常モードでは書き込む
    }

    public function setReadOnlyMode(bool $enabled): void
    {
        $this->readOnlyMode = $enabled;
    }

    public function isReadOnlyMode(): bool
    {
        return $this->readOnlyMode;
    }
}
```

**使用例**:

```php
$filter = new ReadOnlyModeFilter();
$handler->addWriteFilter($filter);

// 通常の動作
$_SESSION['data'] = 'value';
session_write_close(); // 書き込まれる

// メンテナンスモードに入る
$filter->setReadOnlyMode(true);

$_SESSION['new_data'] = 'new_value';
session_write_close(); // 書き込まれない（キャンセル）

// メンテナンス終了
$filter->setReadOnlyMode(false);
```

### 例3: データサイズ制限フィルター

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Psr\Log\LoggerInterface;

class DataSizeLimitFilter implements WriteFilterInterface
{
    private int $maxSizeBytes;
    private LoggerInterface $logger;

    public function __construct(int $maxSizeBytes, LoggerInterface $logger)
    {
        $this->maxSizeBytes = $maxSizeBytes;
        $this->logger = $logger;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        $serialized = serialize($data);
        $size = strlen($serialized);

        if ($size > $this->maxSizeBytes) {
            $this->logger->warning(
                'Session data exceeds size limit, write cancelled',
                [
                    'session_id' => SessionIdMasker::mask($sessionId),
                    'size' => $size,
                    'limit' => $this->maxSizeBytes,
                ]
            );
            return false; // サイズ超過、書き込みキャンセル
        }

        return true; // サイズ内、書き込み許可
    }
}
```

**使用例**:

```php
// 最大1MBまで許可
$filter = new DataSizeLimitFilter(1024 * 1024, $logger);
$handler->addWriteFilter($filter);

// 小さいデータ
$_SESSION['user_id'] = 123;
session_write_close(); // 書き込まれる

// 大きいデータ
$_SESSION['large_data'] = str_repeat('x', 2 * 1024 * 1024); // 2MB
session_write_close(); // 書き込まれない
```

### 例4: 特定キー存在チェックフィルター

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;

class RequiredKeyFilter implements WriteFilterInterface
{
    private array $requiredKeys;

    public function __construct(array $requiredKeys)
    {
        $this->requiredKeys = $requiredKeys;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        // 必須キーがすべて存在するかチェック
        foreach ($this->requiredKeys as $key) {
            if (!isset($data[$key])) {
                error_log("Session write blocked: required key '{$key}' not found");
                return false; // 必須キーが無い場合は書き込まない
            }
        }

        return true; // すべての必須キーがある場合は書き込む
    }
}
```

**使用例**:

```php
// user_idが必須
$filter = new RequiredKeyFilter(['user_id']);
$handler->addWriteFilter($filter);

// user_idなし
$_SESSION = ['name' => 'John'];
session_write_close(); // 書き込まれない

// user_idあり
$_SESSION = ['user_id' => 123, 'name' => 'John'];
session_write_close(); // 書き込まれる
```

### 例5: 時間帯制限フィルター

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;

class TimeBasedWriteFilter implements WriteFilterInterface
{
    private int $startHour;
    private int $endHour;

    public function __construct(int $startHour, int $endHour)
    {
        $this->startHour = $startHour;
        $this->endHour = $endHour;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        $currentHour = (int)date('H');

        // 指定時間帯外は書き込まない（例：メンテナンス時間帯）
        if ($currentHour >= $this->startHour && $currentHour < $this->endHour) {
            error_log("Session write blocked: maintenance window ({$this->startHour}:00-{$this->endHour}:00)");
            return false;
        }

        return true;
    }
}
```

**使用例**:

```php
// 深夜2時〜5時はメンテナンス時間帯として書き込み禁止
$filter = new TimeBasedWriteFilter(2, 5);
$handler->addWriteFilter($filter);
```

## 複数Filterの使用

複数のFilterを登録した場合、**すべて**が`true`を返した場合のみ書き込まれます：

```php
$handler->addWriteFilter(new EmptySessionFilter($logger));
$handler->addWriteFilter(new DataSizeLimitFilter(1024 * 1024, $logger));
$handler->addWriteFilter(new ReadOnlyModeFilter());

// すべてのFilterがtrueを返す → 書き込まれる
// いずれか1つでもfalseを返す → 書き込みキャンセル
```

**実行順序**:
- 登録順に実行される
- 最初に`false`を返したFilterで処理が中断される（早期終了）

## ベストプラクティス

### 1. セッションIDのマスキング

ログに記録する際は必ず`SessionIdMasker`を使用：

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$this->logger->warning('Write cancelled', [
    'session_id' => SessionIdMasker::mask($sessionId), // ✓ 正しい
]);
```

### 2. 明確な判定ロジック

Filterの判定ロジックは明確に：

```php
// ✓ 良い例：明確な判定
public function shouldWrite(string $sessionId, array $data): bool
{
    return count($data) > 0;
}

// ✗ 悪い例：複雑すぎる判定
public function shouldWrite(string $sessionId, array $data): bool
{
    $condition1 = /* 複雑な条件1 */;
    $condition2 = /* 複雑な条件2 */;
    $condition3 = /* 複雑な条件3 */;
    return $condition1 && $condition2 || $condition3;
}
```

### 3. ログ出力

書き込みをキャンセルする場合は、理由をログに記録：

```php
public function shouldWrite(string $sessionId, array $data): bool
{
    if (!$this->someCondition) {
        $this->logger->debug('Write cancelled: condition not met', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'reason' => 'specific reason here',
        ]);
        return false;
    }

    return true;
}
```

### 4. 状態管理

Filterの状態を管理する場合は、スレッドセーフに注意：

```php
class StatefulFilter implements WriteFilterInterface
{
    private bool $enabled = true;

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function shouldWrite(string $sessionId, array $data): bool
    {
        return $this->enabled;
    }
}
```

## WriteHookとの組み合わせ

FilterとHookを組み合わせて使用：

```php
// Filter: 空セッションはスキップ
$handler->addWriteFilter(new EmptySessionFilter($logger));

// Hook: データに処理を追加（空でない場合のみ実行される）
$handler->addWriteHook(new TimestampWriteHook());
$handler->addWriteHook(new AuditWriteHook($logger));
```

**実行順序**:
```
1. WriteFilter::shouldWrite() → falseならここで終了
2. WriteHook::beforeWrite() → データ変換
3. Redisに書き込み
4. WriteHook::afterWrite()
```

## テスト

### Filterのユニットテスト例

```php
use PHPUnit\Framework\TestCase;

class EmptySessionFilterTest extends TestCase
{
    public function testEmptyDataReturnsFalse(): void
    {
        $logger = new NullLogger();
        $filter = new EmptySessionFilter($logger);

        $result = $filter->shouldWrite('session123', []);

        $this->assertFalse($result);
        $this->assertTrue($filter->wasLastWriteEmpty());
    }

    public function testNonEmptyDataReturnsTrue(): void
    {
        $logger = new NullLogger();
        $filter = new EmptySessionFilter($logger);

        $result = $filter->shouldWrite('session123', ['user_id' => 123]);

        $this->assertTrue($result);
        $this->assertFalse($filter->wasLastWriteEmpty());
    }
}
```

### 統合テスト例

```php
public function testFilterCancelsWrite(): void
{
    $handler = new RedisSessionHandler(/* ... */);
    $filter = new EmptySessionFilter(new NullLogger());
    $handler->addWriteFilter($filter);

    // 空セッションを書き込もうとする
    $result = $handler->write('session123', ''); // 空文字列

    // Filterによりキャンセルされる
    $this->assertFalse($result);
}
```

## トラブルシューティング

### Filterが実行されない

**確認ポイント**:
1. `addWriteFilter()`で登録したか？
2. Filterのメソッドで例外が発生していないか？

### すべてのデータが書き込まれない

**確認ポイント**:
1. Filterが常に`false`を返していないか？
2. 複数のFilterがある場合、どれかが`false`を返していないか？
3. ログを確認してキャンセル理由を特定

### PreventEmptySessionCookieとの連携

`EmptySessionFilter`と`PreventEmptySessionCookie`を組み合わせて使用する場合：

```php
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;

$filter = new EmptySessionFilter($logger);
$handler->addWriteFilter($filter);

// PreventEmptySessionCookieがEmptySessionFilterを使用
PreventEmptySessionCookie::setup($handler, $logger);

session_start();
// $_SESSIONが空ならCookieも削除される
```

## まとめ

- **WriteFilter**: 書き込みの可否を判断
- **早期終了**: 不要な処理をスキップしてパフォーマンス向上
- **明確な判定**: シンプルで理解しやすいロジック
- **ログ出力**: キャンセル理由を記録
- **WriteHookとの違い**: Filterは可否判断、Hookはデータ変換

## 関連ドキュメント

- [creating-hooks.md](creating-hooks.md) - WriteHook作成ガイド
- [../developers/implementation/hooks-and-filters.md](../developers/implementation/hooks-and-filters.md) - Hook/Filter機構の詳細
- [../developers/implementation/prevent-empty-cookie.md](../developers/implementation/prevent-empty-cookie.md) - PreventEmptySessionCookie機能
