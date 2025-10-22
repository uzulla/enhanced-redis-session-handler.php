# 開発環境のセットアップ

このドキュメントでは、enhanced-redis-session-handler.phpの開発環境をセットアップする方法を説明します。

## 必要な環境

### PHP
- **バージョン**: 7.4以上
- **必須拡張機能**:
  - ext-redis
  - ext-dom
  - ext-xml
  - ext-curl
  - ext-mbstring

### Composer
- **バージョン**: 2.0以上

## セットアップ手順

### 1. PHPのインストール

#### Ubuntu/Debian
```bash
sudo apt-get update
sudo apt-get install -y php php-redis php-xml php-curl php-mbstring
```

#### macOS (Homebrew)
```bash
brew install php
brew install php-redis
```

### 2. Composerのインストール

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

### 3. 依存パッケージのインストール

プロジェクトディレクトリで以下のコマンドを実行します：

```bash
composer install
```

これにより、以下のツールがインストールされます：
- PHPUnit 9.5以上（テストフレームワーク）
- PHPStan 1.0以上（静的解析ツール）
- PHP CS Fixer 3.0以上（コードスタイルチェッカー）

## 開発ツールの使用方法

### テストの実行

```bash
# 全テストの実行
vendor/bin/phpunit

# カバレッジレポート付きでテストを実行
vendor/bin/phpunit --coverage-html coverage/html
```

### 静的解析の実行

```bash
# PHPStanによる静的解析
vendor/bin/phpstan analyse
```

### コードスタイルのチェックと修正

```bash
# コードスタイルのチェック
vendor/bin/php-cs-fixer fix --dry-run --diff

# コードスタイルの自動修正
vendor/bin/php-cs-fixer fix
```

## 設定ファイル

### phpunit.xml
PHPUnitの設定ファイル。テストディレクトリ、カバレッジ設定などが定義されています。

### phpstan.neon
PHPStanの設定ファイル。解析レベル（level 6）とパス設定が定義されています。

### .php-cs-fixer.php
PHP CS Fixerの設定ファイル。PSR-12準拠のコーディング規約が定義されています。

## トラブルシューティング

### ext-redisが見つからない場合

```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# macOS
brew install php-redis
```

### composer installが失敗する場合

必要なPHP拡張機能がインストールされているか確認してください：

```bash
php -m | grep -E "redis|dom|xml|curl|mbstring"
```

## 継続的インテグレーション

プロジェクトでは以下のチェックを実行することを推奨します：

1. **テスト**: `vendor/bin/phpunit`
2. **静的解析**: `vendor/bin/phpstan analyse`
3. **コードスタイル**: `vendor/bin/php-cs-fixer fix --dry-run`

これらのチェックは、プルリクエストを作成する前に必ず実行してください。
