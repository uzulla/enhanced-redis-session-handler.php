# [Phase 4] HookStorage: 統合テスト

## 概要

実際のRedisを使用して、HookStorageパターンが正しく動作することを確認する統合テストを実装します。フックの組み合わせ、無限再帰防止、深度制限など、実環境に近いシナリオをテストします。

## 親タスク

- **親issue**: #29 - フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）
- **Phase**: 4/6
- **依存関係**: Phase 3（既存フックの対応）が完了していること
- **後続Phase**: Phase 5（ドキュメントとサンプル）

## 目的

HookStorageパターンが実際のRedis環境で期待通りに動作し、フック内のRedis操作が適切に他のフックチェーンを通ることを確認します。

## 実装タスク

### 統合テストファイルの作成

**ファイル**: `tests/Integration/HookStorageIntegrationTest.php`（約250行）

**テストの性質**:
- 実際のRedis接続を使用
- 複数のフックを組み合わせて動作確認
- E2Eに近いシナリオテスト
- RedisSessionHandlerを実際に動作させる

---

### テストケース一覧

#### 1. 基本的なフック連鎖のテスト

**`testLoggingHookCapturesTimestampOperations`**

LoggingHook + ReadTimestampHook を組み合わせ、タイムスタンプ記録操作がログに記録されることを確認します。

```php
public function testLoggingHookCapturesTimestampOperations(): void
{
    // Setup
    $testHandler = new TestHandler();
    $logger = new Logger('test', [$testHandler]);

    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();
    $handler = new RedisSessionHandler($connection, $serializer);

    // LoggingHookを追加
    $loggingHook = new LoggingHook($logger);
    $handler->addWriteHook($loggingHook);

    // ReadTimestampHookを追加
    $timestampHook = new ReadTimestampHook(
        $connection,
        $logger,
        'test:timestamp:',
        3600
    );
    $handler->addReadHook($timestampHook);

    // Execute
    $handler->open('', '');
    $sessionId = 'test_session_' . uniqid();
    $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
    $handler->read($sessionId);

    // Assert: タイムスタンプ記録操作がログに記録されている
    $records = $testHandler->getRecords();
    $timestampLogs = array_filter($records, function ($record) {
        return str_contains($record['message'], 'timestamp');
    });
    self::assertNotEmpty($timestampLogs, 'Timestamp operations should be logged');
}
```

**検証ポイント**:
- LoggingHookがReadTimestampHook内のRedis操作を捕捉
- ログに「timestamp」関連のメッセージが含まれる
- フックの実行順序が正しい

---

#### 2. 無限再帰防止のテスト

**`testMaxDepthPreventsInfiniteRecursion`**

深度制限が正しく機能し、無限再帰が発生しないことを確認します。

```php
public function testMaxDepthPreventsInfiniteRecursion(): void
{
    $testHandler = new TestHandler();
    $logger = new Logger('test', [$testHandler]);

    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();
    $handler = new RedisSessionHandler($connection, $serializer);

    // 深度制限を低く設定（テスト用）
    $hookContext = new HookContext();
    $hookContext->setMaxDepth(2);

    // 再帰的な動作をするカスタムフックを作成
    $recursiveHook = new class($hookContext, $logger) implements ReadHookInterface {
        private HookContext $context;
        private LoggerInterface $logger;
        private int $callCount = 0;

        public function __construct(HookContext $context, LoggerInterface $logger)
        {
            $this->context = $context;
            $this->logger = $logger;
        }

        public function beforeRead(string $sessionId): void {}

        public function afterRead(
            string $sessionId,
            string $data,
            ?HookStorageInterface $storage = null
        ): string {
            $this->callCount++;
            if ($storage !== null && $this->callCount < 10) {
                // 意図的に再帰的にストレージを使用
                $storage->set("recursive_$this->callCount", 'test', 60);
            }
            return $data;
        }

        public function onReadError(string $sessionId, Throwable $e): ?string {
            return null;
        }
    };

    $handler->addReadHook($recursiveHook);

    // Execute & Assert: 無限再帰せずに完了する
    $handler->open('', '');
    $sessionId = 'test_session_' . uniqid();
    $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
    $result = $handler->read($sessionId);

    // 深度制限の警告ログが記録されている
    $warningLogs = array_filter($testHandler->getRecords(), function ($record) {
        return $record['level'] >= Logger::WARNING
            && str_contains($record['message'], 'depth');
    });
    self::assertNotEmpty($warningLogs, 'Depth limit warning should be logged');
}
```

**検証ポイント**:
- 深度制限に達すると直接実行モードに切り替わる
- 無限再帰が発生しない
- 警告ログが出力される

---

#### 3. 深度制限の動作確認テスト

**`testDepthLimitAllowsMultipleLevelsOfHooks`**

深度制限内で複数レベルのフックが正常に動作することを確認します。

```php
public function testDepthLimitAllowsMultipleLevelsOfHooks(): void
{
    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();
    $handler = new RedisSessionHandler($connection, $serializer);

    $testHandler = new TestHandler();
    $logger = new Logger('test', [$testHandler]);

    // Level 1: LoggingHook
    $loggingHook = new LoggingHook($logger);
    $handler->addWriteHook($loggingHook);

    // Level 2: ReadTimestampHook (storage使用)
    $timestampHook = new ReadTimestampHook(
        $connection,
        $logger,
        'test:ts:',
        3600
    );
    $handler->addReadHook($timestampHook);

    // Execute
    $handler->open('', '');
    $sessionId = 'test_session_' . uniqid();
    $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
    $handler->read($sessionId);

    // Assert: 両方のフックが正常に実行された
    $records = $testHandler->getRecords();
    self::assertGreaterThanOrEqual(2, count($records));

    // タイムスタンプがRedisに記録されている
    $timestampKey = 'test:ts:' . $sessionId;
    $timestamp = $connection->get($timestampKey);
    self::assertNotFalse($timestamp, 'Timestamp should be stored in Redis');
}
```

**検証ポイント**:
- 深度制限内（通常3階層）で複数フックが動作
- 各フックが期待通りの処理を実行
- Redis操作が実際に完了している

---

#### 4. 複数フックの連鎖テスト

**`testMultipleHooksChainCorrectly`**

3つ以上のフックが連鎖して正しく動作することを確認します。

```php
public function testMultipleHooksChainCorrectly(): void
{
    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();
    $handler = new RedisSessionHandler($connection, $serializer);

    $testHandler = new TestHandler();
    $logger = new Logger('test', [$testHandler]);

    // 3つのフックを追加
    $hook1 = new LoggingHook($logger, LogLevel::DEBUG, LogLevel::DEBUG);
    $hook2 = new ReadTimestampHook($connection, $logger, 'ts1:', 3600);
    $hook3 = new ReadTimestampHook($connection, $logger, 'ts2:', 3600);

    $handler->addReadHook($hook2);
    $handler->addReadHook($hook3);
    $handler->addWriteHook($hook1);

    // Execute
    $handler->open('', '');
    $sessionId = 'test_session_' . uniqid();
    $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
    $handler->read($sessionId);

    // Assert: 3つのフックすべてが実行された
    self::assertTrue($connection->exists('ts1:' . $sessionId));
    self::assertTrue($connection->exists('ts2:' . $sessionId));

    $logRecords = $testHandler->getRecords();
    self::assertNotEmpty($logRecords);
}
```

**検証ポイント**:
- 複数フックの順次実行
- 各フックが独立して動作
- フック間で干渉しない

---

#### 5. エラーハンドリングのテスト

**`testHookStorageErrorsAreHandledGracefully`**

HookStorage内でエラーが発生しても、セッション処理全体が失敗しないことを確認します。

```php
public function testHookStorageErrorsAreHandledGracefully(): void
{
    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();
    $handler = new RedisSessionHandler($connection, $serializer);

    $testHandler = new TestHandler();
    $logger = new Logger('test', [$testHandler]);

    // 失敗するフックを追加
    $failingHook = new class($logger) implements ReadHookInterface {
        private LoggerInterface $logger;

        public function __construct(LoggerInterface $logger)
        {
            $this->logger = $logger;
        }

        public function beforeRead(string $sessionId): void {}

        public function afterRead(
            string $sessionId,
            string $data,
            ?HookStorageInterface $storage = null
        ): string {
            if ($storage !== null) {
                // 不正なTTLでエラーを発生させる
                try {
                    $storage->set('fail_key', 'value', -1);
                } catch (Throwable $e) {
                    $this->logger->error('Hook operation failed', ['exception' => $e]);
                }
            }
            return $data;
        }

        public function onReadError(string $sessionId, Throwable $e): ?string {
            return null;
        }
    };

    $handler->addReadHook($failingHook);

    // Execute: エラーが発生してもセッション操作は成功する
    $handler->open('', '');
    $sessionId = 'test_session_' . uniqid();
    $result = $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
    self::assertTrue($result, 'Write should succeed despite hook error');

    $data = $handler->read($sessionId);
    self::assertNotEmpty($data, 'Read should succeed despite hook error');

    // エラーログが記録されている
    $errorLogs = array_filter($testHandler->getRecords(), function ($record) {
        return $record['level'] >= Logger::ERROR;
    });
    self::assertNotEmpty($errorLogs, 'Error should be logged');
}
```

**検証ポイント**:
- フック内のエラーがセッション処理を停止させない
- エラーが適切にログ記録される
- グレースフルなエラーハンドリング

---

#### 6. 後方互換性のテスト

**`testOldHooksStillWorkWithoutHookStorage`**

HookStorageを使用しない旧フックが引き続き動作することを確認します。

```php
public function testOldHooksStillWorkWithoutHookStorage(): void
{
    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();
    $handler = new RedisSessionHandler($connection, $serializer);

    // 旧フック（storageパラメータなし）
    $oldHook = new class implements ReadHookInterface {
        public function beforeRead(string $sessionId): void {}

        public function afterRead(string $sessionId, string $data): string
        {
            // storageパラメータを受け取らない旧実装
            return $data . '_modified';
        }

        public function onReadError(string $sessionId, Throwable $e): ?string {
            return null;
        }
    };

    $handler->addReadHook($oldHook);

    // Execute
    $handler->open('', '');
    $sessionId = 'test_session_' . uniqid();
    $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
    $result = $handler->read($sessionId);

    // Assert: 旧フックが正常に動作
    self::assertStringContains('_modified', $result);
}
```

**検証ポイント**:
- 旧フック実装が正常に動作
- 後方互換性の確認
- 新旧フックの混在が可能

---

#### 7. パフォーマンステスト（簡易版）

**`testHookStorageDoesNotSignificantlyImpactPerformance`**

HookStorage使用時のパフォーマンス影響を簡易的に測定します。

```php
public function testHookStorageDoesNotSignificantlyImpactPerformance(): void
{
    $connection = $this->createConnectedRedisConnection();
    $serializer = new PhpSerializeSerializer();

    // HookStorageなし（ベースライン）
    $handler1 = new RedisSessionHandler($connection, $serializer);
    $startTime1 = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $sessionId = "perf_test_1_$i";
        $handler1->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
        $handler1->read($sessionId);
    }
    $baseline = microtime(true) - $startTime1;

    // HookStorageあり
    $handler2 = new RedisSessionHandler($connection, $serializer);
    $logger = new Logger('test', [new TestHandler()]);
    $handler2->addReadHook(new ReadTimestampHook($connection, $logger));

    $startTime2 = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $sessionId = "perf_test_2_$i";
        $handler2->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
        $handler2->read($sessionId);
    }
    $withHookStorage = microtime(true) - $startTime2;

    // Assert: オーバーヘッドが20%以内（緩い制限）
    $overhead = (($withHookStorage - $baseline) / $baseline) * 100;
    self::assertLessThan(20, $overhead,
        "HookStorage overhead should be less than 20%, got {$overhead}%");
}
```

**検証ポイント**:
- パフォーマンスへの影響が許容範囲内
- 簡易的な測定（Phase 6で詳細測定）

---

### ヘルパーメソッド

```php
/**
 * テスト用のRedisConnection作成
 */
private function createConnectedRedisConnection(): RedisConnection
{
    $redis = new Redis();
    $config = new RedisConnectionConfig(
        host: $_ENV['SESSION_REDIS_HOST'] ?? 'localhost',
        port: (int)($_ENV['SESSION_REDIS_PORT'] ?? 6379),
        prefix: 'test:hook_storage:'
    );
    $logger = new Logger('test', [new TestHandler()]);
    $connection = new RedisConnection($redis, $config, $logger);
    $connection->connect();
    return $connection;
}

/**
 * テスト用データのクリーンアップ
 */
protected function tearDown(): void
{
    try {
        $connection = $this->createConnectedRedisConnection();
        $keys = $connection->scan('*');
        foreach ($keys as $key) {
            $connection->delete($key);
        }
    } catch (Throwable $e) {
        // Ignore cleanup errors
    }
    parent::tearDown();
}
```

---

## 技術的考慮事項

### 1. テスト環境

- 実際のRedisが必要（localhostまたはDocker）
- 環境変数で接続情報を設定
- テストデータは専用プレフィックスを使用

### 2. テストの独立性

- 各テストで異なるセッションIDを使用（`uniqid()`）
- tearDown()でテストデータをクリーンアップ
- テスト間で状態を共有しない

### 3. パフォーマンステストの注意

- 環境依存の要素が大きい
- あくまで簡易的な測定
- CI環境での実行も考慮

## 完了条件

- [ ] 統合テストファイルが作成されている
- [ ] 全7種類のテストケースが実装されている
- [ ] 全テストがパス（Redisが起動している環境で）
- [ ] テストカバレッジに貢献している
- [ ] PHPStan strict rulesをパス
- [ ] PHP CS Fixerでコードスタイルが統一
- [ ] コードレビュー完了

## 見積もり

- **コード量**: 約250行
- **工数**: 約4時間
  - テストケース実装: 3時間
  - デバッグ・調整: 1時間

## 関連情報

- **親issue**: #29
- **前Phase**: Phase 3 - 既存フックの対応
- **次Phase**: Phase 5 - ドキュメントとサンプル
- **設計ドキュメント**: `ISSUE-29-UPDATED.md`

## ラベル

- enhancement
- priority: low
- type: testing
- area: integration
- phase: 4/6
