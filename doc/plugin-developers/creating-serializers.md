# Serializer作成ガイド

## 概要

Serializerは、セッションデータを文字列⇔配列に変換する仕組みです。PHPの異なる`session.serialize_handler`形式に対応したり、独自のシリアライズ形式（JSON、MessagePackなど）を実装できます。

## なぜSerializerが必要か？

### PHPのSessionHandlerInterface

```php
interface SessionHandlerInterface {
    public function read(string $id): string|false;  // 文字列を返す
    public function write(string $id, string $data): bool;  // 文字列を受け取る
}
```

### Hook/Filterは配列で扱いたい

```php
interface WriteHookInterface {
    public function beforeWrite(string $sessionId, array $data): array;  // 配列
}

interface WriteFilterInterface {
    public function shouldWrite(string $sessionId, array $data): bool;  // 配列
}
```

### Serializerが橋渡し

```
Redis → 文字列 → [Serializer.decode] → 配列 → Hook処理 → [Serializer.encode] → 文字列 → Redis
```

## SessionSerializerInterface

### インターフェース

```php
namespace Uzulla\EnhancedRedisSessionHandler\Serializer;

interface SessionSerializerInterface
{
    /**
     * セッションデータ文字列を配列にデシリアライズ
     *
     * @param string $data 生のセッションデータ
     * @return array<string, mixed> セッション変数の連想配列
     * @throws \Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException
     */
    public function decode(string $data): array;

    /**
     * 配列をセッションデータ文字列にシリアライズ
     *
     * @param array<string, mixed> $data セッション変数の連想配列
     * @return string シリアライズされたセッションデータ
     * @throws \Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException
     */
    public function encode(array $data): string;

    /**
     * Serializerの名前を取得
     *
     * @return string (例: 'php', 'php_serialize', 'json')
     */
    public function getName(): string;
}
```

## 標準実装

### PhpSerializeSerializer（推奨）

PHPの`session.serialize_handler = 'php_serialize'`形式（PHP 7.0+のデフォルト）：

```php
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

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

**データ形式例**:
```
a:2:{s:7:"user_id";i:123;s:4:"name";s:4:"John";}
```

## カスタム実装

### 例1: JSONSerializer

```php
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

class JsonSerializer implements SessionSerializerInterface
{
    private int $encodeFlags;
    private int $decodeFlags;

    public function __construct(
        int $encodeFlags = JSON_THROW_ON_ERROR,
        int $decodeFlags = JSON_THROW_ON_ERROR
    ) {
        $this->encodeFlags = $encodeFlags;
        $this->decodeFlags = $decodeFlags;
    }

    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        try {
            $decoded = json_decode($data, true, 512, $this->decodeFlags);
        } catch (\JsonException $e) {
            throw new SessionDataException(
                'JSON decode error: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new SessionDataException(
                'Decoded JSON is not an array, got: ' . gettype($decoded)
            );
        }

        return $decoded;
    }

    public function encode(array $data): string
    {
        try {
            return json_encode($data, $this->encodeFlags);
        } catch (\JsonException $e) {
            throw new SessionDataException(
                'JSON encode error: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function getName(): string
    {
        return 'json';
    }
}
```

**データ形式例**:
```json
{"user_id":123,"name":"John"}
```

**使用例**:

```php
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

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

session_set_save_handler($handler, true);
session_start();
```

### 例2: MessagePackSerializer

```php
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

class MessagePackSerializer implements SessionSerializerInterface
{
    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        if (!extension_loaded('msgpack')) {
            throw new SessionDataException('msgpack extension is not loaded');
        }

        $decoded = msgpack_unpack($data);

        if ($decoded === false) {
            throw new SessionDataException('MessagePack decode error');
        }

        if (!is_array($decoded)) {
            throw new SessionDataException(
                'Decoded MessagePack is not an array, got: ' . gettype($decoded)
            );
        }

        return $decoded;
    }

    public function encode(array $data): string
    {
        if (!extension_loaded('msgpack')) {
            throw new SessionDataException('msgpack extension is not loaded');
        }

        $encoded = msgpack_pack($data);

        if ($encoded === false) {
            throw new SessionDataException('MessagePack encode error');
        }

        return $encoded;
    }

    public function getName(): string
    {
        return 'msgpack';
    }
}
```

### 例3: 圧縮付きSerializer

```php
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

class CompressedSerializer implements SessionSerializerInterface
{
    private SessionSerializerInterface $innerSerializer;
    private int $compressionLevel;
    private int $compressionThreshold;

    public function __construct(
        ?SessionSerializerInterface $innerSerializer = null,
        int $compressionLevel = 6,
        int $compressionThreshold = 1024
    ) {
        $this->innerSerializer = $innerSerializer ?? new PhpSerializeSerializer();
        $this->compressionLevel = $compressionLevel;
        $this->compressionThreshold = $compressionThreshold;
    }

    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        // 圧縮データか確認（プレフィックスで判定）
        if (substr($data, 0, 5) === 'GZIP:') {
            $compressed = substr($data, 5);
            $decompressed = gzuncompress($compressed);

            if ($decompressed === false) {
                throw new SessionDataException('Failed to decompress session data');
            }

            return $this->innerSerializer->decode($decompressed);
        }

        // 非圧縮データ
        return $this->innerSerializer->decode($data);
    }

    public function encode(array $data): string
    {
        $serialized = $this->innerSerializer->encode($data);

        // サイズが閾値以下なら圧縮しない
        if (strlen($serialized) < $this->compressionThreshold) {
            return $serialized;
        }

        // 圧縮
        $compressed = gzcompress($serialized, $this->compressionLevel);

        if ($compressed === false) {
            throw new SessionDataException('Failed to compress session data');
        }

        // プレフィックスを付けて返す
        return 'GZIP:' . $compressed;
    }

    public function getName(): string
    {
        return 'compressed_' . $this->innerSerializer->getName();
    }
}
```

**使用例**:

```php
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

// JSON + 圧縮
$serializer = new CompressedSerializer(
    new JsonSerializer(),
    6,    // 圧縮レベル
    2048  // 2KB以上で圧縮
);

// SessionConfigでカスタムSerializerを指定
$config = new SessionConfig(
    $connectionConfig,
    $serializer,  // カスタムSerializer
    $idGenerator,
    $maxLifetime,
    $logger
);

// SessionHandlerFactoryでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

### 例4: 暗号化付きSerializer

```php
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

class EncryptedSerializer implements SessionSerializerInterface
{
    private SessionSerializerInterface $innerSerializer;
    private string $encryptionKey;
    private string $cipher;

    public function __construct(
        string $encryptionKey,
        ?SessionSerializerInterface $innerSerializer = null,
        string $cipher = 'AES-256-GCM'
    ) {
        $this->encryptionKey = $encryptionKey;
        $this->innerSerializer = $innerSerializer ?? new PhpSerializeSerializer();
        $this->cipher = $cipher;
    }

    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        // フォーマット: base64(iv:tag:ciphertext)
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new SessionDataException('Failed to base64 decode encrypted data');
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3) {
            throw new SessionDataException('Invalid encrypted data format');
        }

        [$iv, $tag, $ciphertext] = $parts;

        $decrypted = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new SessionDataException('Failed to decrypt session data');
        }

        return $this->innerSerializer->decode($decrypted);
    }

    public function encode(array $data): string
    {
        $serialized = $this->innerSerializer->encode($data);

        $ivLength = openssl_cipher_iv_length($this->cipher);
        if ($ivLength === false) {
            throw new SessionDataException('Invalid cipher: ' . $this->cipher);
        }

        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = '';

        $encrypted = openssl_encrypt(
            $serialized,
            $this->cipher,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new SessionDataException('Failed to encrypt session data');
        }

        // フォーマット: base64(iv:tag:ciphertext)
        return base64_encode($iv . ':' . $tag . ':' . $encrypted);
    }

    public function getName(): string
    {
        return 'encrypted_' . $this->innerSerializer->getName();
    }
}
```

**使用例**:

```php
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

$encryptionKey = getenv('SESSION_ENCRYPTION_KEY');

$serializer = new EncryptedSerializer(
    $encryptionKey,
    new JsonSerializer()
);

// SessionConfigでカスタムSerializerを指定
$config = new SessionConfig(
    $connectionConfig,
    $serializer,  // カスタムSerializer
    $idGenerator,
    $maxLifetime,
    $logger
);

// SessionHandlerFactoryでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

## ベストプラクティス

### 1. エラーハンドリング

デコード/エンコードエラーは`SessionDataException`をスロー：

```php
public function decode(string $data): array
{
    try {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new SessionDataException(
            'JSON decode error: ' . $e->getMessage(),
            0,
            $e
        );
    }
}
```

### 2. 空データの処理

空文字列は空配列として扱う：

```php
public function decode(string $data): array
{
    if ($data === '') {
        return []; // 空配列を返す
    }

    // デコード処理
}
```

### 3. 型チェック

デコード結果が配列であることを確認：

```php
public function decode(string $data): array
{
    $decoded = /* デコード処理 */;

    if (!is_array($decoded)) {
        throw new SessionDataException(
            'Decoded data is not an array, got: ' . gettype($decoded)
        );
    }

    return $decoded;
}
```

### 4. エラー抑制

PHPの`unserialize()`等のエラーを適切に処理：

```php
set_error_handler(static function (): bool {
    return true; // エラーを抑制
});
try {
    $value = unserialize($data);
} finally {
    restore_error_handler();
}

if ($value === false && $data !== 'b:0;') {
    throw new SessionDataException('Unserialize failed');
}
```

### 5. 互換性の維持

フォーマットを変更する場合は、バージョン情報を含める：

```php
public function encode(array $data): string
{
    $payload = [
        'version' => 1,
        'data' => $data,
    ];

    return json_encode($payload);
}

public function decode(string $data): array
{
    $payload = json_decode($data, true);

    // バージョンチェック
    if (!isset($payload['version']) || $payload['version'] !== 1) {
        throw new SessionDataException('Unsupported format version');
    }

    return $payload['data'];
}
```

## パフォーマンス考慮事項

### 1. シリアライズ形式の選択

- **PHP serialize**: 標準、すべての型をサポート
- **JSON**: 人間が読める、デバッグしやすい、一部の型に制限
- **MessagePack**: バイナリ、高速、コンパクト

### 2. 圧縮

大きなセッションデータには圧縮が有効：

```php
// 閾値を適切に設定
$serializer = new CompressedSerializer(
    new PhpSerializeSerializer(),
    6,    // 圧縮レベル（1-9）
    2048  // 2KB以上で圧縮
);
```

### 3. ベンチマーク

```php
$data = ['user_id' => 123, 'data' => str_repeat('x', 10000)];

// PHP serialize
$start = microtime(true);
$serialized = serialize($data);
$time1 = microtime(true) - $start;

// JSON
$start = microtime(true);
$json = json_encode($data);
$time2 = microtime(true) - $start;

echo "PHP serialize: {$time1}s, size: " . strlen($serialized) . "\n";
echo "JSON: {$time2}s, size: " . strlen($json) . "\n";
```

## セキュリティ考慮事項

### 1. unserialize()の安全性

```php
// ✓ 正しい：allowed_classesを指定
$data = unserialize($string, ['allowed_classes' => true]);

// または、特定のクラスのみ許可
$data = unserialize($string, ['allowed_classes' => [MyClass::class]]);
```

### 2. 暗号化

機密情報を含む場合は暗号化：

```php
$serializer = new EncryptedSerializer(
    getenv('SESSION_ENCRYPTION_KEY'),
    new PhpSerializeSerializer()
);
```

### 3. 入力検証

デコード前に基本的な検証：

```php
public function decode(string $data): array
{
    // 異常に大きいデータを拒否
    if (strlen($data) > 10 * 1024 * 1024) { // 10MB
        throw new SessionDataException('Session data too large');
    }

    // デコード処理
}
```

## テスト

### ラウンドトリップテスト

```php
use PHPUnit\Framework\TestCase;

class JsonSerializerTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $serializer = new JsonSerializer();
        $data = ['user_id' => 123, 'name' => 'John'];

        $encoded = $serializer->encode($data);
        $decoded = $serializer->decode($encoded);

        $this->assertEquals($data, $decoded);
    }

    public function testEmptyData(): void
    {
        $serializer = new JsonSerializer();

        $decoded = $serializer->decode('');
        $this->assertEquals([], $decoded);
    }

    public function testMalformedData(): void
    {
        $this->expectException(SessionDataException::class);

        $serializer = new JsonSerializer();
        $serializer->decode('invalid json{');
    }

    public function testNonArrayData(): void
    {
        $this->expectException(SessionDataException::class);

        $serializer = new JsonSerializer();
        $serializer->decode('"not an array"');
    }
}
```

## トラブルシューティング

### データが復元できない

**確認ポイント**:
1. encode()とdecode()の形式が一致しているか？
2. 空データの処理が正しいか？
3. エラー抑制により例外が隠れていないか？

### パフォーマンスが悪い

**確認ポイント**:
1. 圧縮レベルが高すぎないか？
2. 暗号化のオーバーヘッドが大きくないか？
3. データサイズが大きすぎないか？

## まとめ

- **Serializer**: 文字列⇔配列の変換を担当
- **標準実装**: PhpSerializeSerializerを使用（推奨）
- **カスタム実装**: JSON、MessagePack、圧縮、暗号化など
- **エラーハンドリング**: SessionDataExceptionをスロー
- **パフォーマンス**: 適切な形式と圧縮設定

## 関連ドキュメント

- [../developers/implementation/serializer.md](../developers/implementation/serializer.md) - Serializer機構の詳細
- [creating-hooks.md](creating-hooks.md) - Hook作成ガイド
