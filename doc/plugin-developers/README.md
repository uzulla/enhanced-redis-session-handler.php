# プラグイン開発者向けドキュメント

このディレクトリには、enhanced-redis-session-handler.phpのプラグイン（Hook、Filter、Serializer等）を作成する開発者向けのドキュメントが含まれています。

## プラグインとは？

このライブラリは、以下のインターフェースを実装することで機能を拡張できます：

- **SessionIdGeneratorInterface** - カスタムセッションID生成ロジック
- **SessionSerializerInterface** - カスタムシリアライズ形式
- **ReadHookInterface** - セッション読み込み時の処理
- **WriteHookInterface** - セッション書き込み時の処理
- **WriteFilterInterface** - セッション書き込みの可否判断

## ドキュメント

- **[creating-hooks.md](creating-hooks.md)** - ReadHook/WriteHookの作成方法
- **[creating-filters.md](creating-filters.md)** - WriteFilterの作成方法
- **[creating-serializers.md](creating-serializers.md)** - SessionSerializerの作成方法
- **[creating-session-id-generators.md](creating-session-id-generators.md)** - SessionIdGeneratorの作成方法

## クイックスタート

### 簡単なWriteHookの例

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteHookInterface;

class MyCustomHook implements WriteHookInterface
{
    public function beforeWrite(string $sessionId, array $data): array
    {
        // データに何か追加
        $data['custom_field'] = 'custom_value';
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        // 書き込み後の処理
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        // エラーハンドリング
    }
}

// 使用方法
$handler->addWriteHook(new MyCustomHook());
```

### 簡単なWriteFilterの例

```php
use Uzulla\EnhancedRedisSessionHandler\Hook\WriteFilterInterface;

class MyCustomFilter implements WriteFilterInterface
{
    public function shouldWrite(string $sessionId, array $data): bool
    {
        // 特定条件で書き込みをキャンセル
        if (isset($data['read_only_mode']) && $data['read_only_mode'] === true) {
            return false; // 書き込みキャンセル
        }
        return true; // 書き込み許可
    }
}

// 使用方法
$handler->addWriteFilter(new MyCustomFilter());
```

## 重要な設計原則

### 1. 軽量な実装

Hook/Filterは頻繁に呼ばれるため、処理は最小限に：

```php
// ✓ 良い例
public function beforeWrite(string $sessionId, array $data): array
{
    $data['timestamp'] = time();
    return $data;
}

// ✗ 悪い例
public function beforeWrite(string $sessionId, array $data): array
{
    // 重いDB操作
    $result = $this->db->query('SELECT ...');
    return $data;
}
```

### 2. エラーハンドリング

例外をスローしない、またはスローする場合は適切にハンドリング：

```php
public function beforeWrite(string $sessionId, array $data): array
{
    try {
        // 何らかの処理
    } catch (Exception $e) {
        // ログに記録して、データはそのまま返す
        $this->logger->error('Hook error', ['error' => $e->getMessage()]);
        return $data; // 処理を継続
    }
}
```

### 3. セキュリティ

セッションIDをログに記録する場合は必ずマスキング：

```php
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

$this->logger->info('Processing session', [
    'session_id' => SessionIdMasker::mask($sessionId), // ✓ 正しい
]);
```

## 関連ドキュメント

### 開発者向け
プラグインの内部動作を深く理解したい場合は、[developers/](../developers/)ディレクトリを参照してください。

### ライブラリ利用者向け
ライブラリの基本的な使い方は、[users/](../users/)ディレクトリまたはルートの[README.md](../../README.md)を参照してください。

## サンプルコード

標準実装のコードが参考になります：

- `src/Hook/LoggingHook.php` - WriteHookの実装例
- `src/Hook/EmptySessionFilter.php` - WriteFilterの実装例
- `src/Serializer/PhpSerializeSerializer.php` - Serializerの実装例
- `src/SessionId/SecureSessionIdGenerator.php` - SessionIdGeneratorの実装例

## コミュニティ

- **Issue**: バグ報告や機能提案は[GitHubのIssue](https://github.com/uzulla/enhanced-redis-session-handler.php/issues)で
- **PR**: プルリクエストは歓迎します。[contributing.md](../developers/contributing.md)を参照してください
