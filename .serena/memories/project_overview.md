# プロジェクト概要

## プロジェクト名
enhanced-redis-session-handler.php

## 目的
PHPのセッション管理をRedis/ValKeyで実装する拡張可能なセッションハンドラライブラリ。SessionHandlerInterfaceを実装し、プラグイン機構とフック機能により高いカスタマイズ性と拡張性を提供する。

## プロジェクトタイプ
Composerライブラリ（`composer.lock`は非コミット）

## 技術スタック

### 言語・バージョン
- PHP 7.4以上
- ext-redis 5.0以上

### バックエンド
- Redis 5.0以上
- ValKey 7.2.5以上（テストはValKey 9.0.0で実施）

### 主要依存関係
- `psr/log` ^1.1 || ^2.0 || ^3.0

### 開発ツール
- PHPUnit 9.6+（テストフレームワーク）
- PHPStan 2.0+（静的解析、最大レベル + strict rules）
- PHP CS Fixer 3.64+（コードスタイルチェッカー、PSR-12準拠）
- Monolog（ロギング、開発時のみ）

## 名前空間
`Uzulla\EnhancedRedisSessionHandler`

## 主な特徴
- SessionHandlerInterface + SessionUpdateTimestampHandlerInterface完全実装
- プラグイン可能なセッションIDジェネレータ
- フック機構（ReadHook/WriteHook/WriteFilter）
- 水平スケーリング対応
- ファクトリーパターンによるインスタンス生成

## 対象ユーザー
- 水平スケーリングが必要なPHPアプリケーション開発者
- セッション管理をカスタマイズしたい開発者
- 高可用性が求められるWebサービスの運用者
