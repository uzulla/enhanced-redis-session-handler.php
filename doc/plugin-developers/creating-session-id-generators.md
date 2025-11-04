# SessionIdGenerator作成ガイド

## 概要

SessionIdGeneratorは、セッションIDの生成ロジックをカスタマイズできる仕組みです。セキュリティ要件やデバッグのニーズに合わせて、独自の生成ロジックを実装できます。

## SessionIdGeneratorInterface

### インターフェース

```php
namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

interface SessionIdGeneratorInterface
{
    public function generate(): string;
}
```

非常にシンプルなインターフェースで、`generate()`メソッドのみを実装します。

## 標準実装

### 1. DefaultSessionIdGenerator

最もシンプルな実装：

```php
class DefaultSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
```

**特徴**:
- 16バイト（32文字の16進数文字列）を生成
- `random_bytes()`を使用した暗号学的に安全な乱数
- シンプルで高速

**使用例**:
```php
$generator = new DefaultSessionIdGenerator();
$sessionId = $generator->generate();
// 例: "a1b2c3d4e5f6789012345678abcdef01"
```

### 2. SecureSessionIdGenerator

セキュリティを強化した実装：

```php
class SecureSessionIdGenerator implements SessionIdGeneratorInterface
{
    public const MIN_LENGTH = 32;
    private int $length;

    public function __construct(int $length = 32)
    {
        if ($length < self::MIN_LENGTH) {
            throw new ConfigurationException(
                sprintf('Session ID length must be at least %d characters', self::MIN_LENGTH)
            );
        }
        if ($length % 2 !== 0) {
            throw new ConfigurationException('Session ID length must be an even number');
        }
        $this->length = $length;
    }

    public function generate(): string
    {
        $byteLength = (int)($this->length / 2);
        return bin2hex(random_bytes($byteLength));
    }
}
```

**特徴**:
- 長さをカスタマイズ可能（最小32文字）
- セキュリティ要件が高い環境向け
- 入力値の検証を実施

**使用例**:
```php
// デフォルト（32文字）
$generator = new SecureSessionIdGenerator();

// より長いセッションID（64文字）
$generator = new SecureSessionIdGenerator(64);
```

### 3. PrefixedSessionIdGenerator

カスタムプレフィックスを付加する実装：

```php
class PrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
    private string $prefix;
    private int $randomLength;

    public function __construct(string $prefix = 'app', int $randomLength = 32)
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('Prefix cannot be empty');
        }
        if (preg_match('/^[a-zA-Z0-9-]+$/', $prefix) !== 1) {
            throw new InvalidArgumentException(
                'Prefix can only contain alphanumeric characters and hyphens'
            );
        }
        $this->prefix = $prefix;
        $this->randomLength = $randomLength;
    }

    public function generate(): string
    {
        $byteLength = (int)($this->randomLength / 2);
        $randomPart = bin2hex(random_bytes($byteLength));
        return $this->prefix . '_' . $randomPart;
    }
}
```

**特徴**:
- プレフィックスでセッションIDを識別可能
- 複数アプリケーションでRedisを共有する場合に便利
- デバッグやログ分析が容易

**使用例**:
```php
$generator = new PrefixedSessionIdGenerator('myapp');
$sessionId = $generator->generate();
// 例: "myapp_a1b2c3d4e5f6789012345678abcdef01"

// プレフィックスを抽出
$parts = explode('_', $sessionId);
$prefix = $parts[0]; // "myapp"
```

### 4. TimestampPrefixedSessionIdGenerator

タイムスタンプをプレフィックスに付ける実装：

```php
class TimestampPrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
    private int $randomLength;

    public function __construct(int $randomLength = 32)
    {
        if ($randomLength < 16) {
            throw new InvalidArgumentException(
                'Random part length must be at least 16 characters'
            );
        }
        if ($randomLength % 2 !== 0) {
            throw new InvalidArgumentException(
                'Random part length must be an even number'
            );
        }
        $this->randomLength = $randomLength;
    }

    public function generate(): string
    {
        $timestamp = time();
        $byteLength = (int)($this->randomLength / 2);
        $randomPart = bin2hex(random_bytes($byteLength));
        return $timestamp . '_' . $randomPart;
    }
}
```

**特徴**:
- セッションID作成時刻が分かる
- デバッグやログ分析に便利
- **セキュリティ上の理由で本番環境では非推奨**

**使用例**:
```php
$generator = new TimestampPrefixedSessionIdGenerator();
$sessionId = $generator->generate();
// 例: "1730512345_a1b2c3d4e5f6789012345678abcdef01"

// タイムスタンプを抽出
$parts = explode('_', $sessionId);
$timestamp = (int)$parts[0];
echo date('Y-m-d H:i:s', $timestamp); // "2024-11-02 03:45:45"
```

## カスタム実装例

### 例1: UUID v4ベースのジェネレータ

```php
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;
use Ramsey\Uuid\Uuid;

class UuidSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generate(): string
    {
        // UUID v4を生成（ハイフンなし）
        return str_replace('-', '', Uuid::uuid4()->toString());
    }
}
```

**使用例**:
```php
$generator = new UuidSessionIdGenerator();
$sessionId = $generator->generate();
// 例: "550e8400e29b41d4a716446655440000"
```

### 例2: 環境ベースのプレフィックス

```php
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class EnvironmentPrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
    private string $environment;

    public function __construct(string $environment = null)
    {
        $this->environment = $environment ?? ($_ENV['APP_ENV'] ?? 'prod');
    }

    public function generate(): string
    {
        $randomPart = bin2hex(random_bytes(16));
        return $this->environment . '_' . $randomPart;
    }
}
```

**使用例**:
```php
// 環境変数から自動取得
$generator = new EnvironmentPrefixedSessionIdGenerator();
// 例: "dev_a1b2c3d4e5f6789012345678abcdef01"

// 明示的に指定
$generator = new EnvironmentPrefixedSessionIdGenerator('staging');
// 例: "staging_a1b2c3d4e5f6789012345678abcdef01"
```

### 例3: テナント識別子付きジェネレータ

```php
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class TenantAwareSessionIdGenerator implements SessionIdGeneratorInterface
{
    private string $tenantId;
    private int $randomLength;

    public function __construct(string $tenantId, int $randomLength = 32)
    {
        if ($tenantId === '') {
            throw new InvalidArgumentException('Tenant ID cannot be empty');
        }
        if (!preg_match('/^[a-z0-9-]+$/', $tenantId)) {
            throw new InvalidArgumentException(
                'Tenant ID can only contain lowercase alphanumeric characters and hyphens'
            );
        }
        $this->tenantId = $tenantId;
        $this->randomLength = $randomLength;
    }

    public function generate(): string
    {
        $byteLength = (int)($this->randomLength / 2);
        $randomPart = bin2hex(random_bytes($byteLength));
        return $this->tenantId . '_' . $randomPart;
    }
}
```

**使用例**:
```php
// マルチテナントアプリケーション
$tenantId = 'acme-corp';
$generator = new TenantAwareSessionIdGenerator($tenantId);
$sessionId = $generator->generate();
// 例: "acme-corp_a1b2c3d4e5f6789012345678abcdef01"
```

### 例4: チェックサム付きジェネレータ

```php
use Uzulla\EnhancedRedisSessionHandler\SessionId\SessionIdGeneratorInterface;

class ChecksumSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generate(): string
    {
        $randomPart = bin2hex(random_bytes(16));

        // 簡易チェックサム（最初の4文字のCRC32）
        $checksum = substr(dechex(crc32($randomPart)), 0, 4);

        return $randomPart . $checksum;
    }

    /**
     * セッションIDの整合性を検証
     */
    public function verify(string $sessionId): bool
    {
        if (strlen($sessionId) < 36) {
            return false;
        }

        $randomPart = substr($sessionId, 0, 32);
        $checksum = substr($sessionId, 32, 4);
        $expectedChecksum = substr(dechex(crc32($randomPart)), 0, 4);

        return $checksum === $expectedChecksum;
    }
}
```

**使用例**:
```php
$generator = new ChecksumSessionIdGenerator();
$sessionId = $generator->generate();
// 例: "a1b2c3d4e5f6789012345678abcdef01ab12"

// 検証
if ($generator->verify($sessionId)) {
    echo "Valid session ID";
}
```

## ベストプラクティス

### 1. 暗号学的に安全な乱数を使用

```php
// ✓ 正しい
public function generate(): string
{
    return bin2hex(random_bytes(16));
}

// ✗ 間違い（セキュリティリスク）
public function generate(): string
{
    return md5(uniqid(mt_rand(), true)); // 予測可能
}
```

### 2. 十分な長さを確保

```php
// ✓ 良い例：最小16バイト（32文字）
public function generate(): string
{
    return bin2hex(random_bytes(16)); // 32文字
}

// ✗ 悪い例：短すぎる（衝突リスク）
public function generate(): string
{
    return bin2hex(random_bytes(4)); // 8文字のみ
}
```

**推奨**:
- 最小：16バイト（32文字）
- 推奨：32バイト（64文字）以上（高セキュリティ環境）

### 3. 入力値の検証

```php
public function __construct(int $length = 32)
{
    // 長さの検証
    if ($length < 16) {
        throw new InvalidArgumentException('Length must be at least 16');
    }

    // 偶数であることの検証（bin2hex用）
    if ($length % 2 !== 0) {
        throw new InvalidArgumentException('Length must be even');
    }

    $this->length = $length;
}
```

### 4. プレフィックスは短く保つ

```php
// ✓ 良い例：短いプレフィックス
$generator = new PrefixedSessionIdGenerator('app'); // 3文字

// ✗ 悪い例：長すぎるプレフィックス
$generator = new PrefixedSessionIdGenerator('my-very-long-application-name'); // 28文字
```

**理由**: セッションIDが長すぎるとCookieサイズが増加し、HTTPヘッダーサイズ制限に影響する可能性があります。

### 5. プレフィックスに機密情報を含めない

```php
// ✓ 良い例
$generator = new PrefixedSessionIdGenerator('app');

// ✗ 悪い例（ユーザー情報を含む）
$generator = new PrefixedSessionIdGenerator("user{$userId}"); // 情報漏洩リスク
```

## セキュリティ考慮事項

### 1. 予測可能性の排除

セッションIDは予測不可能である必要があります：

```php
// ✓ 正しい：暗号学的に安全
return bin2hex(random_bytes(16));

// ✗ 間違い：予測可能
return time() . '_' . incrementCounter(); // タイミング攻撃に脆弱
```

### 2. タイムスタンプの使用に注意

```php
// ⚠️ デバッグ用途のみ
class TimestampPrefixedSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generate(): string
    {
        // タイムスタンプは予測可能な情報
        $timestamp = time();
        $randomPart = bin2hex(random_bytes(16));
        return $timestamp . '_' . $randomPart;
    }
}
```

**注意**: タイムスタンプは予測可能な情報のため、本番環境では使用しないでください。

### 3. 十分なエントロピーを確保

```php
// ✓ 良い例：128ビットのエントロピー
return bin2hex(random_bytes(16)); // 128ビット

// ✓ より良い例：256ビットのエントロピー
return bin2hex(random_bytes(32)); // 256ビット

// ✗ 悪い例：不十分なエントロピー
return bin2hex(random_bytes(4)); // 32ビットのみ
```

## 使用方法

### SessionHandlerFactoryでの使用

```php
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;

// カスタムジェネレータを作成
$generator = new SecureSessionIdGenerator(64);

// SessionConfigに渡す
$config = new SessionConfig(
    new RedisConnectionConfig(),
    $generator, // ここでカスタムジェネレータを指定
    7200,
    new NullLogger()
);

// ファクトリーでハンドラを作成
$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

session_set_save_handler($handler, true);
session_start();
```

### 直接RedisSessionHandlerに渡す

```php
$handler = new RedisSessionHandler(
    $connection,
    'session:',
    new SecureSessionIdGenerator(64), // カスタムジェネレータ
    $serializer,
    3600,
    $logger
);
```

## テスト

### ユニットテスト例

```php
use PHPUnit\Framework\TestCase;

class SecureSessionIdGeneratorTest extends TestCase
{
    public function testGenerateReturnsCorrectLength(): void
    {
        $generator = new SecureSessionIdGenerator(64);
        $sessionId = $generator->generate();

        $this->assertEquals(64, strlen($sessionId));
    }

    public function testGenerateReturnsUniqueValues(): void
    {
        $generator = new SecureSessionIdGenerator();

        $id1 = $generator->generate();
        $id2 = $generator->generate();

        $this->assertNotEquals($id1, $id2);
    }

    public function testConstructorValidatesMinimumLength(): void
    {
        $this->expectException(ConfigurationException::class);
        new SecureSessionIdGenerator(16); // MIN_LENGTH未満
    }

    public function testGeneratedIdIsHexadecimal(): void
    {
        $generator = new SecureSessionIdGenerator();
        $sessionId = $generator->generate();

        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $sessionId);
    }
}
```

### 衝突テスト

```php
public function testNoCollisionIn10000Generations(): void
{
    $generator = new SecureSessionIdGenerator();
    $generated = [];

    for ($i = 0; $i < 10000; $i++) {
        $id = $generator->generate();
        $this->assertNotContains($id, $generated, "Collision detected at iteration {$i}");
        $generated[] = $id;
    }
}
```

## トラブルシューティング

### セッションIDが短すぎる

**症状**: セキュリティ警告やセッションハイジャックのリスク

**解決策**:
```php
// 最小32文字を確保
$generator = new SecureSessionIdGenerator(32);
```

### プレフィックスが長すぎる

**症状**: Cookie サイズエラーやHTTPヘッダーサイズ超過

**解決策**:
```php
// プレフィックスを短く
$generator = new PrefixedSessionIdGenerator('app'); // 長いプレフィックスを避ける
```

### セッションIDの衝突

**症状**: 異なるユーザーが同じセッションを共有

**解決策**:
```php
// より長いセッションIDを使用
$generator = new SecureSessionIdGenerator(64);

// または、より多くのエントロピーを確保
return bin2hex(random_bytes(32)); // 64文字
```

## まとめ

- **SessionIdGeneratorInterface**: 単一の`generate()`メソッドを実装
- **セキュリティ**: 暗号学的に安全な乱数を使用
- **長さ**: 最小32文字、推奨64文字以上
- **プレフィックス**: 識別や名前空間分離に便利だが、短く保つ
- **タイムスタンプ**: デバッグ用途のみ、本番環境では非推奨

## 関連ドキュメント

- [creating-hooks.md](creating-hooks.md) - WriteHook作成ガイド
- [creating-filters.md](creating-filters.md) - WriteFilter作成ガイド
- [creating-serializers.md](creating-serializers.md) - Serializer作成ガイド
- [../developers/architecture.md](../developers/architecture.md) - アーキテクチャ設計書
