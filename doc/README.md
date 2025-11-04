# ドキュメント

## 対象者別ドキュメント

### 📚 [ライブラリ利用者向け](users/)

ライブラリを使用してアプリケーションを開発する方向けのドキュメント：

- [SessionHandlerFactory使用ガイド](users/factory-usage.md)
- [Redis/ValKey統合仕様](users/redis-integration.md)

### 🔌 [プラグイン開発者向け](plugin-developers/)

Hook、Filter、Serializerなどのプラグインを作成する方向けのドキュメント：

- [Hook作成ガイド](plugin-developers/creating-hooks.md)
- [Filter作成ガイド](plugin-developers/creating-filters.md)
- [Serializer作成ガイド](plugin-developers/creating-serializers.md)
- [SessionIdGenerator作成ガイド](plugin-developers/creating-session-id-generators.md)

### 🛠️ [開発者向け](developers/)

ライブラリ本体の開発に参加する方向けのドキュメント：

- [アーキテクチャ設計書](developers/architecture.md)
- [実装詳細ドキュメント](developers/implementation/)
  - [Serializer機構](developers/implementation/serializer.md)
  - [Hook/Filter機構](developers/implementation/hooks-and-filters.md)
  - [PreventEmptySessionCookie機能](developers/implementation/prevent-empty-cookie.md)

## クイックリンク

- [ルートREADME](../README.md) - プロジェクト概要
- [開発環境セットアップ](../DEVELOPMENT.md) - 開発環境の構築方法
- [CHANGELOG](../CHANGELOG.md) - 変更履歴
- [LICENSE](../LICENSE) - ライセンス情報
