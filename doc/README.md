# ドキュメント

enhanced-redis-session-handler.phpライブラリのドキュメントへようこそ。

## 対象者別ドキュメント

### 📚 [ライブラリ利用者向け](users/)

ライブラリを使用してアプリケーションを開発する方向けのドキュメント：

- [SessionHandlerFactory使用ガイド](users/factory-usage.md)
- [Redis/ValKey統合仕様](users/redis-integration.md)

**こんな方におすすめ**:
- Redis/ValKeyを使ったセッション管理を導入したい
- 水平スケーリング対応のセッションストレージが必要
- 基本的な使い方を知りたい

### 🔌 [プラグイン開発者向け](plugin-developers/)

Hook、Filter、Serializerなどのプラグインを作成する方向けのドキュメント：

- プラグイン開発の基礎（準備中）
- Hook/Filter作成ガイド（準備中）
- Serializer作成ガイド（準備中）

**こんな方におすすめ**:
- セッションデータの暗号化や圧縮を実装したい
- カスタムシリアライズ形式をサポートしたい
- セッション操作にカスタムロジックを追加したい

### 🛠️ [ライブラリ開発者・コラボレータ向け](developers/)

ライブラリ本体の開発に参加する方向けのドキュメント：

- [アーキテクチャ設計書](developers/architecture.md)
- [実装詳細ドキュメント](developers/implementation/)
  - [Serializer機構](developers/implementation/serializer.md)
  - [Hook/Filter機構](developers/implementation/hooks-and-filters.md)
  - [PreventEmptySessionCookie機能](developers/implementation/prevent-empty-cookie.md)

**こんな方におすすめ**:
- ライブラリにコントリビュートしたい
- バグを修正したい
- 新機能を追加したい
- アーキテクチャを深く理解したい

## クイックリンク

- [ルートREADME](../README.md) - プロジェクト概要
- [開発環境セットアップ](../DEVELOPMENT.md) - 開発環境の構築方法
- [CHANGELOG](../CHANGELOG.md) - 変更履歴
- [LICENSE](../LICENSE) - ライセンス情報

## ドキュメント構造

```
doc/
├── README.md (このファイル)
│
├── users/                      ライブラリ利用者向け
│   ├── README.md
│   ├── factory-usage.md
│   └── redis-integration.md
│
├── plugin-developers/          プラグイン開発者向け
│   └── README.md
│
└── developers/                 ライブラリ開発者向け
    ├── README.md
    ├── architecture.md
    └── implementation/
        ├── serializer.md
        ├── hooks-and-filters.md
        └── prevent-empty-cookie.md
```

## ドキュメントのアップデート

ドキュメントに誤りを見つけた場合や、改善提案がある場合は、[GitHubのIssue](https://github.com/uzulla/enhanced-redis-session-handler.php/issues)または[Pull Request](https://github.com/uzulla/enhanced-redis-session-handler.php/pulls)でお知らせください。

## バージョン

このドキュメントは、enhanced-redis-session-handler.php の最新版に対応しています。

古いバージョンを使用している場合は、対応するバージョンのドキュメントを参照してください。
