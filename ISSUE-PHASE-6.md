# [Phase 6] HookStorage: 品質保証

## 概要

HookStorageパターンの実装全体について、品質保証を行います。静的解析、コードスタイル、テスト、カバレッジ、パフォーマンスを確認し、本番環境での使用に耐えられる品質を確保します。

## 親タスク

- **親issue**: #29 - フック内Redis操作が他フックを通らない問題の解決（HookStorageパターン導入）
- **Phase**: 6/6（最終Phase）
- **依存関係**: Phase 1-5の全てが完了していること
- **後続Phase**: なし（完了後、本番リリース準備）

## 目的

実装全体の品質を確認し、本番環境で安全に使用できることを保証します。

## 実施タスク

### 1. PHPStan 静的解析

**目的**: 型エラー、未定義変数、到達不可能コードなどを検出

**実行コマンド**:
```bash
composer phpstan
```

**チェック項目**:
- [ ] PHPStan Level Max で **エラーなし**
- [ ] Strict rules準拠
- [ ] 新規追加コードでの警告なし
- [ ] 既存コードへの影響なし

**対応**:
- エラーが検出された場合は修正
- 必要に応じて型アノテーションを追加
- `@phpstan-ignore-next-line` は最小限に

**見積もり**: 1時間

---

### 2. PHP CS Fixer コードスタイルチェック

**目的**: PSR-12準拠、コードスタイルの統一

**実行コマンド**:
```bash
composer cs-check
composer cs-fix  # 自動修正
```

**チェック項目**:
- [ ] PSR-12準拠
- [ ] インデントの統一（スペース4つ）
- [ ] 改行コードの統一
- [ ] trailing whitespace なし
- [ ] ファイル末尾の改行あり

**対応**:
- `composer cs-fix` で自動修正
- 自動修正できない箇所は手動修正

**見積もり**: 0.5時間

---

### 3. 全テストの実行

**目的**: 新規機能と既存機能の両方が正常動作することを確認

#### 3-1. Redis環境のセットアップ

```bash
# Dockerが利用可能な場合
docker compose -f docker/docker-compose.yml up -d
./docker/healthcheck.sh

# Dockerが利用できない場合
redis-server --daemonize yes --port 6379
redis-cli ping  # 'PONG'が返ればOK
```

**チェック項目**:
- [ ] Redisが起動している
- [ ] 接続可能

#### 3-2. テスト実行

```bash
composer test
```

**チェック項目**:
- [ ] 全テストがパス（375 + 新規テスト）
- [ ] スキップされたテストの理由を確認
- [ ] 新規追加テストが実行されている
- [ ] 既存テストに影響がない

**期待される結果**:
```
OK, but incomplete, skipped, or risky tests!
Tests: 380+, Assertions: 1500+, Skipped: 2, Incomplete: 1
```

**対応**:
- 失敗したテストは原因を特定し修正
- スキップされたテストは適切か確認
- テスト実行時間が極端に長くなっていないか確認

**見積もり**: 2時間

---

### 4. テストカバレッジの確認

**目的**: 新規コードのテストカバレッジを確認

**実行コマンド**:
```bash
composer coverage        # テキスト形式
composer coverage-report # HTML形式（coverage/html/）
```

**チェック項目**:
- [ ] 新規追加コードのカバレッジ > 90%
- [ ] `src/Storage/` 配下のカバレッジ > 90%
- [ ] 既存コードのカバレッジが低下していない
- [ ] 未カバーのエッジケースがないか確認

**重点確認箇所**:
- HookRedisStorage.php
- HookContext.php
- ReadTimestampHook.php（新実装部分）
- DoubleWriteHook.php（新実装部分）

**対応**:
- カバレッジが低い場合はテストを追加
- 未カバーのエッジケースをテスト

**見積もり**: 1時間（追加テスト作成含む）

---

### 5. パフォーマンステスト

**目的**: HookStorage導入によるパフォーマンス影響を測定

#### 5-1. 簡易ベンチマーク

**テストシナリオ**:
- セッション書き込み + 読み込み 1000回
- HookStorageあり/なしで比較

**ベンチマークスクリプト作成**:
```php
// tests/Benchmark/HookStorageBenchmark.php（新規作成）

<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Benchmark;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;

class HookStorageBenchmark
{
    private const ITERATIONS = 1000;

    public function run(): void
    {
        echo "HookStorage Performance Benchmark\n";
        echo "Iterations: " . self::ITERATIONS . "\n\n";

        // Baseline: HookStorageなし
        $baseline = $this->benchmarkWithoutHookStorage();
        echo sprintf("Baseline (without HookStorage): %.3f seconds\n", $baseline);

        // HookStorageあり
        $withHookStorage = $this->benchmarkWithHookStorage();
        echo sprintf("With HookStorage: %.3f seconds\n", $withHookStorage);

        // オーバーヘッド計算
        $overhead = (($withHookStorage - $baseline) / $baseline) * 100;
        echo sprintf("\nOverhead: %.2f%%\n", $overhead);

        if ($overhead < 10) {
            echo "✓ Overhead is acceptable (< 10%)\n";
        } else {
            echo "⚠ Overhead is higher than expected (>= 10%)\n";
        }
    }

    private function benchmarkWithoutHookStorage(): float
    {
        $connection = $this->createConnection();
        $handler = new RedisSessionHandler(
            $connection,
            new PhpSerializeSerializer()
        );
        $handler->open('', '');

        $startTime = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $sessionId = "bench_baseline_$i";
            $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
            $handler->read($sessionId);
        }
        return microtime(true) - $startTime;
    }

    private function benchmarkWithHookStorage(): float
    {
        $connection = $this->createConnection();
        $logger = new Logger('bench', [new NullHandler()]);
        $handler = new RedisSessionHandler(
            $connection,
            new PhpSerializeSerializer()
        );

        // ReadTimestampHookを追加（HookStorage使用）
        $handler->addReadHook(new ReadTimestampHook(
            $connection,
            $logger,
            'bench:ts:',
            3600
        ));

        $handler->open('', '');

        $startTime = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $sessionId = "bench_with_storage_$i";
            $handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
            $handler->read($sessionId);
        }
        return microtime(true) - $startTime;
    }

    private function createConnection(): RedisConnection
    {
        $redis = new Redis();
        $config = new RedisConnectionConfig(
            host: 'localhost',
            port: 6379,
            prefix: 'bench:'
        );
        $logger = new Logger('bench', [new NullHandler()]);
        $connection = new RedisConnection($redis, $config, $logger);
        $connection->connect();
        return $connection;
    }
}

// 実行
if (PHP_SAPI === 'cli') {
    $benchmark = new HookStorageBenchmark();
    $benchmark->run();
}
```

**実行**:
```bash
php tests/Benchmark/HookStorageBenchmark.php
```

**チェック項目**:
- [ ] オーバーヘッドが10%以内
- [ ] 極端なパフォーマンス低下がない
- [ ] メモリ使用量が増加していない

**対応**:
- オーバーヘッドが大きい場合は原因を特定
- 必要に応じて最適化

**見積もり**: 1.5時間

---

### 6. コードレビュー対応予備時間

**目的**: レビューフィードバックへの対応

**想定される指摘事項**:
- コードの可読性改善
- エッジケースの追加テスト
- ドキュメントの補足
- 命名の改善

**チェック項目**:
- [ ] 全レビューコメントに対応
- [ ] 対応後に再度テスト実行
- [ ] 再レビュー依頼

**見積もり**: 2時間

---

### 7. 最終確認チェックリスト

**Phase 1-5の完了確認**:
- [ ] Phase 1: HookStorage基盤実装が完了
- [ ] Phase 2: インターフェース拡張が完了
- [ ] Phase 3: 既存フック対応が完了
- [ ] Phase 4: 統合テストが完了
- [ ] Phase 5: ドキュメント・サンプルが完了

**品質基準**:
- [ ] `composer phpstan` - エラーなし
- [ ] `composer cs-check` - エラーなし
- [ ] `composer test` - 全テストパス
- [ ] カバレッジ > 90%（新規コード）
- [ ] パフォーマンスオーバーヘッド < 10%

**ドキュメント**:
- [ ] 全ドキュメントが完成
- [ ] サンプルコードが動作する
- [ ] Markdown lintをパス

**Git操作**:
- [ ] 全変更がコミット済み
- [ ] コミットメッセージが適切
- [ ] ブランチが最新の main/master をベースにしている

---

## 技術的考慮事項

### 1. 環境依存のテスト

- Redisのバージョン違いによる動作差異
- PHP 7.4 と PHP 8.x での動作確認
- CI環境での動作確認

### 2. パフォーマンス測定の信頼性

- 測定環境によるばらつき
- 複数回実行して平均を取る
- あくまで目安として扱う

### 3. カバレッジの解釈

- 100%を目指さない（過度なテストは避ける）
- エラーハンドリングのパスは重要
- エッジケースの網羅性

## 完了条件

- [ ] PHPStan strict rules - **エラーなし**
- [ ] PHP CS Fixer - **エラーなし**
- [ ] 全テスト実行 - **全てパス**
- [ ] カバレッジ - **新規コード > 90%**
- [ ] パフォーマンス - **オーバーヘッド < 10%**
- [ ] ドキュメント - **完成・動作確認済み**
- [ ] コードレビュー - **完了**
- [ ] 全変更がコミット・プッシュ済み

## 見積もり

- **工数**: 約5.5時間
  - PHPStan: 1時間
  - PHP CS Fixer: 0.5時間
  - テスト実行・確認: 2時間
  - カバレッジ確認: 1時間
  - パフォーマンステスト: 1.5時間
  - レビュー対応予備: 2時間（Phase全体）

**実質**: レビューフィードバック次第で +2-4時間

## 関連情報

- **親issue**: #29
- **前Phase**: Phase 5 - ドキュメントとサンプル
- **次Phase**: なし（完了後、リリース準備）
- **設計ドキュメント**: `ISSUE-29-UPDATED.md`

## 完了後のアクション

1. **PRの作成**: 全Phaseの変更をまとめてPR作成
2. **リリースノート作成**: 変更内容をまとめる
3. **CHANGELOG.md更新**: バージョン履歴に追記
4. **マイルストーンの完了**: GitHub上でマイルストーンを閉じる

## ラベル

- enhancement
- priority: low
- type: qa
- area: quality-assurance
- phase: 6/6
