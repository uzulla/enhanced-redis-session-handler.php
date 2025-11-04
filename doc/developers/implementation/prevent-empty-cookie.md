# PreventEmptySessionCookie - 実装詳細

## 概要

PreventEmptySessionCookie機能は、空セッションの場合にセッションCookieをクライアントに送信しないようにする仕組みです。これにより、不要なRedisアクセスを削減し、パフォーマンスを向上させます。

## 問題の背景

### 通常のセッション動作

```
1. ユーザーアクセス（初回、Cookieなし）
   ↓
2. session_start() を呼ぶ
   ↓
3. PHPが新しいセッションIDを生成
   ↓
4. セッションCookieをクライアントに送信（自動）
   ↓
5. アプリケーションが$_SESSIONに何も書き込まない
   ↓
6. session_write_close()
   ↓
7. 空のセッションデータがRedisに書き込まれる
```

### 問題点

1. **不要なRedis書き込み**: 空セッションでもRedisにデータを保存
2. **不要なCookie送信**: クライアントに無意味なセッションCookieを送信
3. **次回アクセス時の無駄なRedis読み込み**: 空セッションのIDでRedisに問い合わせ

```
次回アクセス時:

1. ユーザーアクセス（セッションCookie送信）
   ↓
2. session_start()
   ↓
3. PHPがCookieのセッションIDを読み取る
   ↓
4. Redis GET session:xxx ← 不要なアクセス！
   ↓
5. 空データが返ってくる
```

## 解決策の設計

### 基本方針

```
┌──────────────────────────────────────────┐
│ 1. 空セッションはRedisに書き込まない      │
│    → EmptySessionFilterで実現            │
└──────────────────────────────────────────┘
              ↓
┌──────────────────────────────────────────┐
│ 2. 空セッションの場合、Cookieを削除       │
│    → PreventEmptySessionCookieで実現     │
└──────────────────────────────────────────┘
```

### 実装アプローチ

1. **EmptySessionFilter**: 空セッションの書き込みをスキップ（WriteFilter）
2. **PreventEmptySessionCookie**: 空セッションだった場合、Cookieを削除（shutdown_function）

## 実装詳細

### クラス構造

```php
namespace Uzulla\EnhancedRedisSessionHandler\Session;

class PreventEmptySessionCookie
{
    // 過去の有効期限オフセット（86400秒 = 1日）
    private const PAST_EXPIRATION_OFFSET_SECONDS = 86400;

    // 初期化済みフラグ
    private static bool $initialized = false;

    // EmptySessionFilterインスタンス
    private static ?EmptySessionFilter $filter = null;

    // ロガー
    private static ?LoggerInterface $logger = null;

    // 公開メソッド
    public static function setup(
        RedisSessionHandler $handler,
        LoggerInterface $logger
    ): void;

    public static function checkAndCleanup(): void;

    public static function reset(): void;
}
```

### setup() - 初期化

```php
public static function setup(
    RedisSessionHandler $handler,
    LoggerInterface $logger
): void {
    if (self::$initialized) {
        return; // 重複呼び出しを防ぐ
    }

    self::$logger = $logger;

    // EmptySessionFilterを作成・登録
    self::$filter = new EmptySessionFilter($logger);
    $handler->addWriteFilter(self::$filter);

    // セッションハンドラを登録
    session_set_save_handler($handler, true);

    // 新規セッション（Cookieなし）の場合、shutdown_functionを登録
    if (!isset($_COOKIE[session_name()])) {
        register_shutdown_function([self::class, 'checkAndCleanup']);
        $logger->debug('Registered empty session cleanup handler');
    }

    self::$initialized = true;
}
```

**ポイント**:
- `$initialized`フラグで重複呼び出しを防止
- 新規セッション判定: `!isset($_COOKIE[session_name()])`
- 既存セッション（Cookieあり）では`checkAndCleanup()`を登録しない（不要）

### checkAndCleanup() - クリーンアップ

```php
public static function checkAndCleanup(): void
{
    // セッションが有効でない、またはFilterが未設定なら何もしない
    if (session_status() !== PHP_SESSION_ACTIVE || self::$filter === null) {
        return;
    }

    // EmptySessionFilterが「最後の書き込みが空だった」と判定している場合
    if (self::$filter->wasLastWriteEmpty()) {
        // session_destroy()を呼び出す
        // これにより、write()メソッドが呼ばれなくなる
        session_destroy();

        // ヘッダーが未送信なら、Cookieを削除
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            $sessionName = session_name();
            if ($sessionName === false) {
                throw new LogicException('session_name() returned false');
            }

            // 過去の有効期限でCookieを上書き（削除）
            $options = [
                'expires' => time() - self::PAST_EXPIRATION_OFFSET_SECONDS,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ];

            $cookieSet = setcookie($sessionName, '', $options);
            if (!$cookieSet && self::$logger !== null) {
                self::$logger->warning(
                    'Failed to set cookie for empty session cleanup',
                    ['session_name' => $sessionName]
                );
            }
        }
    }
}
```

**ポイント**:
- `session_status()`: セッションが有効か確認
- `wasLastWriteEmpty()`: EmptySessionFilterの判定結果を利用
- `session_destroy()`: write()の呼び出しを防ぐ（Redis書き込みスキップ）
- `setcookie()`: 過去の有効期限で上書き = Cookie削除

### reset() - リセット

```php
public static function reset(): void
{
    self::$initialized = false;
    self::$filter = null;
    self::$logger = null;
}
```

**用途**: テストコードでの状態リセット

## 動作フロー

### 空セッションの場合

```
┌───────────────────────────────────────────┐
│ 1. ユーザーアクセス（初回、Cookieなし）    │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 2. PreventEmptySessionCookie::setup()     │
│    - EmptySessionFilterを登録             │
│    - shutdown_functionを登録              │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 3. session_start()                        │
│    - PHPが新しいセッションIDを生成        │
│    - Set-Cookieヘッダーが予約される       │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 4. アプリケーション処理                    │
│    - $_SESSIONに何も書き込まない          │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 5. session_write_close()                  │
│    - RedisSessionHandler::write()呼び出し │
│    - EmptySessionFilter::shouldWrite()    │
│      → false (空セッション)               │
│    - Redis書き込みスキップ                │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 6. shutdown_function実行                  │
│    - checkAndCleanup()呼び出し            │
│    - wasLastWriteEmpty() → true           │
│    - session_destroy()                    │
│    - setcookie(..., expires: 過去)        │
│      → Cookie削除                         │
└───────────────────────────────────────────┘
```

### データがあるセッションの場合

```
┌───────────────────────────────────────────┐
│ 1. ユーザーアクセス（初回、Cookieなし）    │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 2. PreventEmptySessionCookie::setup()     │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 3. session_start()                        │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 4. アプリケーション処理                    │
│    - $_SESSION['user_id'] = 123;          │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 5. session_write_close()                  │
│    - EmptySessionFilter::shouldWrite()    │
│      → true (データあり)                  │
│    - Redisに書き込み実行                  │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 6. shutdown_function実行                  │
│    - checkAndCleanup()呼び出し            │
│    - wasLastWriteEmpty() → false          │
│    - 何もしない（Cookieはそのまま）       │
└───────────────────────────────────────────┘
                 ↓
┌───────────────────────────────────────────┐
│ 次回アクセス時                             │
│    - CookieからセッションIDを取得         │
│    - RedisからデータをGET                 │
│    - セッション復元                       │
└───────────────────────────────────────────┘
```

## 使用方法

### 基本的な使い方

```php
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\SessionConfig;
use Uzulla\EnhancedRedisSessionHandler\SessionHandlerFactory;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\Session\PreventEmptySessionCookie;
use Psr\Log\NullLogger;

// ロガー
$logger = new NullLogger();

// SessionHandlerを作成
$config = new SessionConfig(
    new RedisConnectionConfig(),
    new DefaultSessionIdGenerator(),
    (int)ini_get('session.gc_maxlifetime'),
    $logger
);

$factory = new SessionHandlerFactory($config);
$handler = $factory->build();

// PreventEmptySessionCookieを設定
PreventEmptySessionCookie::setup($handler, $logger);

// セッション開始
session_start();

// アプリケーション処理
// $_SESSIONが空ならCookieは削除される
// $_SESSIONにデータがあればCookieは送信される
```

### 開発環境での使用（デバッグログ付き）

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('session');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// ... SessionHandler作成 ...

PreventEmptySessionCookie::setup($handler, $logger);
session_start();

// DEBUGログが出力される:
// - "Registered empty session cleanup handler" (新規セッション時)
// - "Empty session detected, write operation cancelled" (空セッション時)
// - "Session has data, write operation allowed" (データあり時)
```

## 設計上の議論と選択

### なぜshutdown_functionを使うのか？

**代替案**: `session_write_close()`の後に手動でCookieを削除

```php
session_write_close();
if (empty($_SESSION)) {
    setcookie(session_name(), '', time() - 86400);
}
```

**問題点**:
- アプリケーションコードの全箇所で実装が必要
- 忘れやすい
- `session_write_close()`が自動実行される場合（shutdown時）に対応できない

**shutdown_functionの利点**:
- 自動的に実行される
- アプリケーションコードの変更不要
- `session_write_close()`が呼ばれるタイミングに関わらず動作

### なぜsession_destroy()を呼ぶのか？

`session_destroy()`は以下を行います：

1. セッションデータを削除
2. **write()メソッドの呼び出しをスキップ**

2番目がポイントです。`session_destroy()`を呼ぶと、PHPはshutdown時に`write()`を呼び出しません。これにより、Redisへの書き込みが完全にスキップされます。

### Cookie削除の方法

**過去の有効期限で上書き**:

```php
setcookie(session_name(), '', [
    'expires' => time() - 86400, // 1日前
    // その他のパラメータは現在の設定を維持
]);
```

**なぜ`header_remove('Set-Cookie')`を使わないのか**:
- 他のCookieまで削除されるリスク
- セッションCookieを特定して削除するのが困難
- 過去の有効期限での上書きが標準的な方法

## セキュリティ考慮事項

### タイミング攻撃の防止

Cookie削除は`headers_sent()`をチェックしてから実行：

```php
if (!headers_sent()) {
    setcookie(...);
}
```

これにより、ヘッダー送信後のCookie設定エラーを防ぎます。

### ログ出力

EmptySessionFilterは内部で`SessionIdMasker`を使用：

```php
$this->logger->debug('Empty session detected', [
    'session_id' => SessionIdMasker::mask($sessionId),
]);
```

### セッション固定攻撃への影響

この機能はセッション固定攻撃の対策には影響しません。通常通り`session_regenerate_id()`を使用してください。

## パフォーマンス影響

### メリット

1. **Redis書き込み削減**: 空セッションの書き込みをスキップ
2. **Redis読み込み削減**: 次回アクセス時の無駄な読み込みを防ぐ
3. **ネットワーク帯域削減**: 不要なCookie送信を防ぐ

### デメリット

- shutdown_functionの追加オーバーヘッド（極小）
- EmptySessionFilterの判定コスト（極小）

**結論**: メリットがデメリットを大きく上回る

## テスト

### ユニットテスト

```php
public function testSetupRegistersFilterAndShutdownFunction(): void
{
    $handler = $this->createMock(RedisSessionHandler::class);
    $logger = new NullLogger();

    // Filterが登録されることを確認
    $handler->expects($this->once())
        ->method('addWriteFilter')
        ->with($this->isInstanceOf(EmptySessionFilter::class));

    PreventEmptySessionCookie::setup($handler, $logger);
}

public function testCheckAndCleanupDestroysEmptySession(): void
{
    // モックでテスト（実装省略）
}
```

### E2Eテスト

```php
public function testEmptySessionCookieIsRemoved(): void
{
    // 実際のPHPセッションを使用したテスト
    // （実装省略）
}
```

## トラブルシューティング

### Cookieが削除されない

**チェックポイント**:
1. `headers_sent()`が`true`になっていないか？
   - 解決: 出力バッファリングを使用、またはヘッダー送信前に`session_start()`
2. `session_destroy()`が複数回呼ばれていないか？
   - 解決: `session_status()`で確認
3. ログを確認（DEBUGレベル）

### Redis書き込みがスキップされない

**チェックポイント**:
1. `EmptySessionFilter`が正しく登録されているか？
2. `$_SESSION`が本当に空か？（`count($_SESSION) === 0`）
3. ログを確認

## 制限事項

### 既存セッション（Cookieあり）には適用されない

既にセッションCookieが存在する場合、`checkAndCleanup()`は登録されません。これは意図的な設計です。

**理由**:
- 既存セッションが空になることは稀
- パフォーマンスオーバーヘッドを避ける

### ヘッダー送信後は無効

ヘッダーが既に送信されている場合、Cookieを削除できません：

```php
echo "Content"; // ヘッダー送信
session_start();
// → PreventEmptySessionCookieは機能しない
```

**解決策**: 出力バッファリングを使用

```php
ob_start();
echo "Content";
session_start();
ob_end_flush();
// → PreventEmptySessionCookieが機能する
```

## まとめ

PreventEmptySessionCookie機能は、以下を実現します：

1. **空セッションの最適化**: 不要なRedis書き込み・Cookie送信を防止
2. **パフォーマンス向上**: 次回アクセス時の無駄なRedis読み込みを削減
3. **透過的な動作**: アプリケーションコードの変更不要
4. **安全性**: セキュリティやセッション機能に影響なし

## 関連ドキュメント

- [hooks-and-filters.md](hooks-and-filters.md) - EmptySessionFilterの詳細
- [../architecture.md](../architecture.md) - 全体設計
