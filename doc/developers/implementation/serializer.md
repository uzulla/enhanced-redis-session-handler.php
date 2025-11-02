# Serializer機構 - 実装詳細

## 概要

Serializer機構は、PHPセッションデータのシリアライズ形式を柔軟に切り替えるための仕組みです。この機能により、PHPの`session.serialize_handler`設定に対応した複数のシリアライズ形式をサポートします。

## 設計の背景

### 問題：SessionHandlerInterfaceとHook/Filterの型不一致

PHPの`SessionHandlerInterface`では：

```php
interface SessionHandlerInterface {
    public function read(string $id): string|false;
    public function write(string $id, string $data): bool;
}
```

- `read()`は**文字列**を返す
- `write()`は**文字列**を受け取る

一方、Hook/Filterでは配列形式でデータを扱いたい：

```php
interface WriteHookInterface {
    public function beforeWrite(string $sessionId, array $data): array;
}

interface WriteFilterInterface {
    public function shouldWrite(string $sessionId, array $data): bool;
}
```

### 解決策：Serializerによる変換

Serializerは、文字列⇔配列の変換を担当します：

```
Redis → 文字列 → [Serializer.decode] → 配列 → Hook処理 → [Serializer.encode] → 文字列 → Redis
```

## SessionSerializerInterface

### インターフェース定義

```php
namespace Uzulla\EnhancedRedisSessionHandler\Serializer;

interface SessionSerializerInterface
{
    /**
     * セッションデータ文字列を連想配列にデシリアライズ
     *
     * @param string $data 生のセッションデータ
     * @return array<string, mixed> セッション変数の連想配列
     * @throws SessionDataException データ形式が不正な場合
     */
    public function decode(string $data): array;

    /**
     * 連想配列をセッションデータ文字列にシリアライズ
     *
     * @param array<string, mixed> $data セッション変数の連想配列
     * @return string シリアライズされたセッションデータ
     * @throws SessionDataException シリアライズ失敗時
     */
    public function encode(array $data): string;

    /**
     * Serializerの名前を取得
     *
     * @return string (例: 'php', 'php_serialize')
     */
    public function getName(): string;
}
```

### メソッド詳細

#### decode(string $data): array

**目的**: 文字列形式のセッションデータを配列に変換

**入力**: Redisから取得した文字列データ
**出力**: `$_SESSION`に相当する連想配列
**例外**: `SessionDataException` - データが破損している場合

**使用例**:
```php
$serializer = new PhpSerializeSerializer();
$array = $serializer->decode('a:1:{s:7:"user_id";i:123;}');
// Result: ['user_id' => 123]
```

#### encode(array $data): string

**目的**: 配列形式のセッションデータを文字列に変換

**入力**: `$_SESSION`に相当する連想配列
**出力**: Redisに保存する文字列データ
**例外**: `SessionDataException` - シリアライズ失敗時

**使用例**:
```php
$serializer = new PhpSerializeSerializer();
$string = $serializer->encode(['user_id' => 123]);
// Result: 'a:1:{s:7:"user_id";i:123;}'
```

#### getName(): string

**目的**: Serializerの識別名を取得

**戻り値**: 'php' または 'php_serialize'

## 標準実装

### PhpSerializeSerializer

PHPの`session.serialize_handler = 'php_serialize'`形式に対応します。

#### 特徴
- PHP 7.0+のデフォルト形式
- 標準の`serialize()` / `unserialize()`を使用
- シンプルで理解しやすい
- 配列全体を一つのシリアライズデータとして扱う

#### データ形式

```
a:2:{s:4:"user";s:4:"John";s:7:"user_id";i:123;}
```

上記は以下の配列をシリアライズしたもの：

```php
[
    'user' => 'John',
    'user_id' => 123
]
```

#### 実装コード

```php
class PhpSerializeSerializer implements SessionSerializerInterface
{
    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        set_error_handler(static function (): bool {
            return true; // エラーを抑制
        });
        try {
            $unserialized = unserialize($data, ['allowed_classes' => true]);
        } finally {
            restore_error_handler();
        }

        if ($unserialized === false && $data !== 'b:0;') {
            throw new SessionDataException('Failed to unserialize session data');
        }

        if (!is_array($unserialized)) {
            throw new SessionDataException(
                'Session data is not an array, got: ' . gettype($unserialized)
            );
        }

        return $unserialized;
    }

    public function encode(array $data): string
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $serialized = serialize($data);
        } finally {
            restore_error_handler();
        }

        return $serialized;
    }

    public function getName(): string
    {
        return 'php_serialize';
    }
}
```

#### エラーハンドリング

- `unserialize()`失敗時は`SessionDataException`をスロー
- 空文字列は空配列として扱う
- デシリアライズ結果が配列でない場合はエラー

### PhpSerializer

PHPの`session.serialize_handler = 'php'`形式に対応します。

#### 特徴
- PHP 5.xまでのデフォルト形式
- カスタムフォーマット: `key|serialized_value`
- 複雑なパーサーが必要
- レガシー互換性のために提供

#### データ形式

```
user|s:4:"John";user_id|i:123;
```

上記は以下の配列を表現：

```php
[
    'user' => 'John',
    'user_id' => 123
]
```

#### フォーマット仕様

各変数は`key|serialized_value`の形式：

```
key1|s:3:"abc";key2|i:123;key3|N;
```

- `key|`: 変数名 + パイプ区切り
- `s:3:"abc"`: 文字列型（長さ:値）
- `i:123`: 整数型
- `N`: NULL値
- `b:0` / `b:1`: 真偽値
- `d:1.5`: 浮動小数点数
- `a:2:{...}`: 配列
- `O:...:{...}`: オブジェクト

#### 実装の複雑性

PhpSerializerは、パイプ区切りのカスタムフォーマットをパースする必要があります：

```php
class PhpSerializer implements SessionSerializerInterface
{
    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        $result = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            // キー名を読み取る（パイプまで）
            $pos = strpos($data, '|', $offset);
            if ($pos === false) {
                // 残りが空白のみなら終了
                $rest = substr($data, $offset);
                if ($rest === '' || trim($rest) === '') {
                    break;
                }
                throw new SessionDataException(
                    'Malformed session data: missing "|" after key at offset ' . $offset
                );
            }

            $key = substr($data, $offset, $pos - $offset);
            $offset = $pos + 1;

            // シリアライズされた値を読み取る
            $parsed = $this->consumeSerializedValue($data, $offset);
            if ($parsed === null) {
                throw new SessionDataException(
                    'Malformed serialized value for key "' . $key . '" at offset ' . $offset
                );
            }

            [$serializedString, $nextOffset] = $parsed;

            // デシリアライズ
            set_error_handler(static function (): bool {
                return true;
            });
            try {
                $value = unserialize($serializedString, ['allowed_classes' => true]);
            } finally {
                restore_error_handler();
            }

            if ($value === false && $serializedString !== 'b:0;') {
                throw new SessionDataException(
                    'unserialize failed for key "' . $key . '" with data: ' . $serializedString
                );
            }

            $result[$key] = $value;
            $offset = $nextOffset;
        }

        return $result;
    }

    public function encode(array $data): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            $keyString = is_int($key) ? (string)$key : $key;

            set_error_handler(static function (): bool {
                return true;
            });
            try {
                $serialized = serialize($value);
            } finally {
                restore_error_handler();
            }

            $out .= $keyString . '|' . $serialized;
        }
        return $out;
    }

    public function getName(): string
    {
        return 'php';
    }
}
```

#### consumeSerializedValue() メソッド

PHPのシリアライズ形式をパースする内部メソッド：

```php
private function consumeSerializedValue(string $data, int $start): ?array
{
    $type = $data[$start];

    switch ($type) {
        case 'N': // NULL: N;
            return ['N;', $start + 2];

        case 'b': // 真偽値: b:0; or b:1;
        case 'i': // 整数: i:123;
        case 'd': // 浮動小数点数: d:1.5;
            $semicolonPos = strpos($data, ';', $start);
            $serialized = substr($data, $start, $semicolonPos - $start + 1);
            return [$serialized, $semicolonPos + 1];

        case 's': // 文字列: s:3:"abc";
            preg_match('/\\As:(\\d+):"/', substr($data, $start), $m);
            $strlen = (int)$m[1];
            $prefixLen = strlen('s:' . $m[1] . ':"');
            $contentEnd = $start + $prefixLen + $strlen;
            $serialized = substr($data, $start, $contentEnd + 2 - $start);
            return [$serialized, $contentEnd + 2];

        case 'a': // 配列: a:2:{...}
        case 'O': // オブジェクト: O:8:"ClassName":2:{...}
        case 'C': // カスタムシリアライズ: C:8:"ClassName":...
            // 中括弧のバランスを取ってパース
            // （実装省略）
    }
}
```

**実装のポイント**:
- 各型のフォーマットを正確にパース
- 文字列の場合は長さ指定を考慮
- 配列・オブジェクトは中括弧のネストに対応
- エスケープ文字の処理

## RedisSessionHandlerでの使用

### デフォルト設定

```php
class RedisSessionHandler
{
    private SessionSerializerInterface $serializer;

    public function __construct(
        RedisConnection $connection,
        SessionIdGeneratorInterface $idGenerator,
        LoggerInterface $logger,
        int $maxLifetime,
        ?SessionSerializerInterface $serializer = null
    ) {
        // デフォルトはPhpSerializeSerializer
        $this->serializer = $serializer ?? new PhpSerializeSerializer();
    }
}
```

### read()での使用

```php
public function read(string $id): string|false
{
    // Redisから文字列を取得
    $stringData = $this->connection->get($id);

    if ($stringData === false) {
        return '';
    }

    // 文字列 → 配列に変換
    $arrayData = $this->serializer->decode($stringData);

    // ReadHook処理（配列を操作）
    foreach ($this->readHooks as $hook) {
        // ...
    }

    // 配列 → 文字列に戻してPHPに返す
    return $this->serializer->encode($arrayData);
}
```

### write()での使用

```php
public function write(string $id, string $data): bool
{
    // PHPから受け取った文字列 → 配列に変換
    $arrayData = $this->serializer->decode($data);

    // WriteFilter処理
    foreach ($this->writeFilters as $filter) {
        if (!$filter->shouldWrite($id, $arrayData)) {
            return false; // 書き込みキャンセル
        }
    }

    // WriteHook処理（配列を操作）
    foreach ($this->writeHooks as $hook) {
        $arrayData = $hook->beforeWrite($id, $arrayData);
    }

    // 配列 → 文字列に変換してRedisに保存
    $stringData = $this->serializer->encode($arrayData);
    return $this->connection->set($id, $stringData, $this->getTTL());
}
```

## カスタムSerializer作成

### 実装例：JSONSerializer

```php
namespace MyApp\Serializer;

use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

class JsonSerializer implements SessionSerializerInterface
{
    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        $decoded = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SessionDataException(
                'JSON decode error: ' . json_last_error_msg()
            );
        }

        if (!is_array($decoded)) {
            throw new SessionDataException('Decoded JSON is not an array');
        }

        return $decoded;
    }

    public function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        return $encoded;
    }

    public function getName(): string
    {
        return 'json';
    }
}
```

### 使用方法

```php
use MyApp\Serializer\JsonSerializer;

// SessionConfigでカスタムSerializerを指定
$config = new SessionConfig(
    $connectionConfig,
    new JsonSerializer(),  // カスタムSerializer
    $idGenerator,
    $maxLifetime,
    $logger
);

// SessionHandlerFactoryでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();
```

## セキュリティ考慮事項

### unserialize()の安全性

両実装とも`unserialize()`を使用するため、以下の対策を実施：

```php
unserialize($data, ['allowed_classes' => true]);
```

- `allowed_classes => true`: 全クラスのデシリアライズを許可（デフォルトの動作）
- セッションデータは信頼できるソース（Redis）からのみ取得
- ユーザー入力を直接デシリアライズしない

### エラー抑制

```php
set_error_handler(static function (): bool {
    return true; // エラーを抑制
});
try {
    $value = unserialize($data);
} finally {
    restore_error_handler();
}
```

- デシリアライズエラーを例外に変換
- エラーログの汚染を防ぐ
- 適切な例外メッセージで通知

## パフォーマンス比較

### PhpSerializeSerializer
- **長所**: シンプルで高速、標準関数のみ使用
- **短所**: なし
- **推奨**: 新規プロジェクトではこちらを推奨

### PhpSerializer
- **長所**: レガシー互換性
- **短所**: パーサーのオーバーヘッド、複雑な実装
- **推奨**: レガシーシステムとの互換性が必要な場合のみ

## テスト

### PhpSerializeSerializerのテスト

```php
public function testPhpSerializeRoundTrip(): void
{
    $serializer = new PhpSerializeSerializer();
    $data = ['user_id' => 123, 'name' => 'John'];

    $encoded = $serializer->encode($data);
    $decoded = $serializer->decode($encoded);

    $this->assertEquals($data, $decoded);
}

public function testEmptyData(): void
{
    $serializer = new PhpSerializeSerializer();
    $decoded = $serializer->decode('');
    $this->assertEquals([], $decoded);
}

public function testMalformedData(): void
{
    $this->expectException(SessionDataException::class);
    $serializer = new PhpSerializeSerializer();
    $serializer->decode('invalid data');
}
```

### PhpSerializerのテスト

```php
public function testPhpFormatRoundTrip(): void
{
    $serializer = new PhpSerializer();
    $data = ['user_id' => 123, 'name' => 'John'];

    $encoded = $serializer->encode($data);
    // 結果: user_id|i:123;name|s:4:"John";

    $decoded = $serializer->decode($encoded);
    $this->assertEquals($data, $decoded);
}

public function testComplexData(): void
{
    $serializer = new PhpSerializer();
    $data = [
        'scalar' => 'test',
        'number' => 123,
        'null' => null,
        'bool' => true,
        'array' => [1, 2, 3],
    ];

    $encoded = $serializer->encode($data);
    $decoded = $serializer->decode($encoded);

    $this->assertEquals($data, $decoded);
}
```

## まとめ

Serializer機構は、以下を実現します：

1. **型変換の明確化**: 文字列⇔配列の変換を一箇所で管理
2. **Hook/Filterの実装を簡素化**: 配列形式で直感的に操作可能
3. **複数形式のサポート**: レガシーシステムとの互換性を維持
4. **拡張性**: カスタムSerializerの実装が容易

## 関連ドキュメント

- [hooks-and-filters.md](hooks-and-filters.md) - Hook/Filterでの配列操作
- [session-handler.md](session-handler.md) - RedisSessionHandlerでの使用方法
- [../architecture.md](../architecture.md) - 全体設計
