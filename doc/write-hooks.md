# 書き込みフック（Write Hooks）

書き込みフックは、セッション書き込み操作にカスタム機能を追加する強力な仕組みです。書き込みライフサイクルのさまざまな段階でセッション書き込みイベントを傍受し、反応することができます。

## 概要

`WriteHookInterface`は、セッション書き込み操作中のさまざまなタイミングで呼び出される3つのメソッドを定義します：

1. **beforeWrite**: セッションデータがRedisに書き込まれる前に呼び出される
2. **afterWrite**: 書き込み操作が完了した後に呼び出される（成功・失敗どちらでも）
3. **onWriteError**: 書き込み操作中に例外が発生した場合に呼び出される

## WriteHookInterface

```php
interface WriteHookInterface
{
    /**
     * セッションデータをRedisに書き込む前に呼び出されます。
     *
     * @param string $sessionId セッションID
     * @param array<string, mixed> $data デシリアライズされたセッションデータ
     * @return array<string, mixed> 変更されたセッションデータ
     */
    public function beforeWrite(string $sessionId, array $data): array;

    /**
     * セッションデータをRedisに書き込んだ後に呼び出されます。
     *
     * @param string $sessionId セッションID
     * @param bool $success 書き込み操作が成功したかどうか
     */
    public function afterWrite(string $sessionId, bool $success): void;

    /**
     * 書き込み操作中にエラーが発生した場合に呼び出されます。
     *
     * @param string $sessionId セッションID
     * @param \Throwable $exception 発生した例外
     */
    public function onWriteError(string $sessionId, \Throwable $exception): void;
}
```

## 組み込み実装

### LoggingHook

`LoggingHook`は、PSR-3互換のロガーを使用してセッション書き込み操作の包括的なロギングを提供します。

**機能:**
- セッション書き込みの開始、成功、失敗イベントをログ記録
- イベントごとに設定可能なログレベル
- オプションのセッションデータロギング（セキュリティのためデフォルトでは無効）
- 書き込み失敗時の詳細なエラー情報

**使用例:**

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LogLevel;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('/var/log/sessions.log', Logger::INFO));

$loggingHook = new LoggingHook(
    $logger,
    LogLevel::INFO,      // beforeWriteのログレベル
    LogLevel::INFO,      // afterWriteのログレベル
    LogLevel::ERROR,     // onWriteErrorのログレベル
    false                // セッションデータをログ記録（セキュリティのためfalse）
);

$handler->addWriteHook($loggingHook);
```

### DoubleWriteHook

`DoubleWriteHook`は、セッションデータをセカンダリRedisインスタンスに書き込み、冗長性とバックアップ機能を提供します。

**機能:**
- プライマリ書き込みが成功した後にセカンダリRedisに書き込み
- セカンダリストレージ用の設定可能なTTL
- オプションの失敗モード（セカンダリ書き込みエラー時に失敗するか続行するか）
- セカンダリ書き込み操作の包括的なロギング

**用途:**
- セッションデータのバックアップコピー作成
- データセンター間でのセッションレプリケーション
- 新しいRedisインスタンスへのセッション移行
- 高可用性セットアップ

**使用例:**

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;

$secondaryRedis = new Redis();
$secondaryConfig = new RedisConnectionConfig('backup-redis.example.com', 6379);
$secondaryConnection = new RedisConnection($secondaryRedis, $secondaryConfig, $logger);

$doubleWriteHook = new DoubleWriteHook(
    $secondaryConnection,
    1440,                // TTL（秒）
    false,               // セカンダリエラー時に失敗するか
    $logger
);

$handler->addWriteHook($doubleWriteHook);
```

## カスタムフックの作成

`WriteHookInterface`を実装することで、カスタムフックを作成できます：

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

class CustomAuditHook implements WriteHookInterface
{
    private $auditLogger;

    public function __construct($auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // 書き込み前に監査証跡をログ記録
        // 重要: セキュリティのため、ログ記録時は必ずセッションIDをマスキングすること
        $this->auditLogger->info('Session write initiated', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'user_id' => $data['user_id'] ?? null,
        ]);

        // データを未変更で返す（必要に応じて変更も可能）
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        // 書き込み後に監査証跡をログ記録
        if ($success) {
            $this->auditLogger->info('Session write completed', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
        }
    }

    public function onWriteError(string $sessionId, \Throwable $exception): void
    {
        // エラーを監査システムにログ記録
        $this->auditLogger->error('Session write failed', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'error' => $exception->getMessage(),
        ]);
    }
}
```

**セキュリティ注意**: ログ漏洩時のセッションハイジャックを防ぐため、セッションIDをログ記録する際は必ず`SessionIdMasker::mask()`を使用してください。

## フックの実行順序

複数のフックが登録されている場合、追加された順序で実行されます：

```php
$handler->addWriteHook($loggingHook);      // 最初に実行
$handler->addWriteHook($doubleWriteHook);  // 2番目に実行
$handler->addWriteHook($customHook);       // 3番目に実行
```

**beforeWrite**フックはチェーン化されます - 各フックは前のフックが返したデータを受け取ります：

```php
// フック1がデータを変更
public function beforeWrite(string $sessionId, array $data): array
{
    $data['processed_by_hook1'] = true;
    return $data;
}

// フック2はフック1で変更されたデータを受け取る
public function beforeWrite(string $sessionId, array $data): array
{
    // ここでは$data['processed_by_hook1']がtrueになっている
    $data['processed_by_hook2'] = true;
    return $data;
}
```

## エラーハンドリング

書き込み操作中に例外が発生した場合：

1. 登録されているすべてのフックで`onWriteError`メソッドが呼び出されます
2. エラーがログに記録されます
3. 書き込み操作は`false`を返します

フックは自身のエラーを適切に処理し、絶対に必要な場合を除いて例外をスローしないようにしてください。

## ベストプラクティス

1. **フックを軽量に保つ**: フックはセッション書き込みごとに呼び出されるため、処理を最小限に抑える
2. **エラーを適切に処理する**: フックの失敗がセッション機能を壊さないようにする
3. **適切なログレベルを使用する**: 本番環境での過剰なロギングを避ける
4. **セキュリティを意識する**: 機密性の高いセッションデータをログに記録しない
5. **十分にテストする**: エラー条件を含むさまざまなシナリオでフックをテストする
6. **動作を文書化する**: カスタムフックの動作を明確に文書化する

## パフォーマンスに関する考慮事項

- フックはセッション書き込み操作にオーバーヘッドを追加します
- 重い処理には可能な限り非同期処理を使用する
- 不要な書き込みをスキップするために書き込みフィルターの使用を検討する
- 本番環境でフックのパフォーマンスを監視する

## 関連項目

- [Factory Usage](./factory-usage.md) - SessionHandlerFactoryの使用方法について
- [Redis Integration](./redis-integration.md) - Redis/ValKey統合仕様について
- [Architecture](./architecture.md) - システム全体のアーキテクチャについて
- [Specification](./specification.md) - 機能仕様書
