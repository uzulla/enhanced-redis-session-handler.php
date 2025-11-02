# コントリビューションガイド

## 概要

enhanced-redis-session-handler.phpへのコントリビューションを歓迎します！このガイドでは、プルリクエストの作成方法、コミットメッセージ規約、レビュープロセスについて説明します。

## 始める前に

### 1. Issueの確認

大きな変更を実装する前に、まずIssueで議論することを推奨します：

1. [GitHub Issues](https://github.com/uzulla/enhanced-redis-session-handler.php/issues)を確認
2. 既存のIssueがない場合は新規作成
3. 実装方針について議論

**例外**: バグ修正や小さな改善は直接PRでもOKです。

### 2. 環境のセットアップ

```bash
# リポジトリをフォーク
# （GitHubのWebインターフェースから）

# クローン
git clone https://github.com/YOUR_USERNAME/enhanced-redis-session-handler.php.git
cd enhanced-redis-session-handler.php

# 依存関係をインストール
composer install

# Docker環境を起動（推奨）
docker compose -f docker/docker-compose.yml up -d

# テストが通ることを確認
composer test
```

## プルリクエストのワークフロー

### 1. ブランチの作成

```bash
# mainブランチから最新の状態を取得
git checkout main
git pull origin main

# 機能ブランチを作成
git checkout -b feature/your-feature-name

# または バグ修正の場合
git checkout -b fix/issue-123
```

**ブランチ命名規則**:
- `feature/` - 新機能
- `fix/` - バグ修正
- `refactor/` - リファクタリング
- `docs/` - ドキュメントのみの変更
- `test/` - テストのみの追加・修正

### 2. 実装

```bash
# コードを変更
# ...

# コミット前チェック
composer phpstan   # 静的解析
composer cs-check  # コードスタイル
composer test      # テスト

# 問題があれば修正
composer cs-fix    # コードスタイル自動修正
```

### 3. コミット

**コミットメッセージ規約**:

```
1行で変更の理由（WHY）を説明

詳細な説明が必要な場合はここに記述。
複数行でもOK。
```

**良いコミットメッセージの例**:

```bash
# ✓ 良い例：理由（WHY）を説明
git commit -m "空セッション書き込みを防ぐためEmptySessionFilterを追加"

git commit -m "接続リトライ時のexponential backoffを実装してRedis障害時の復旧を改善"

git commit -m "セッションIDのログ出力時にマスキングを追加してセキュリティを向上"

# ✗ 悪い例：何をしたか（WHAT）だけ
git commit -m "フィルタを追加"
git commit -m "リトライを実装"
git commit -m "マスキングを追加"
```

**コミットの粒度**:

```bash
# ✓ 良い例：独立した修正は別々にコミット
git add src/Hook/EmptySessionFilter.php tests/Hook/EmptySessionFilterTest.php
git commit -m "空セッション書き込みを防ぐためEmptySessionFilterを追加"

git add doc/plugin-developers/creating-filters.md
git commit -m "EmptySessionFilterの使用方法をドキュメントに追加"

# ✗ 悪い例：無関係な変更を1つにまとめる
git add .
git commit -m "複数の修正"
```

**コミット前の必須チェック**:

```bash
# 1. PHPStan
composer phpstan
# → エラーがないこと

# 2. PHP CS Fixer
composer cs-check
# → 違反がないこと（あれば composer cs-fix で修正）

# 3. テスト
composer test
# → 全テストがパス

# まとめて実行
composer check
```

### 4. プッシュ

```bash
# 初回プッシュ
git push -u origin feature/your-feature-name

# 2回目以降
git push
```

### 5. プルリクエストの作成

GitHubのWebインターフェースでPRを作成：

**PRタイトル**:
```
空セッション書き込みを防ぐEmptySessionFilterを追加
```

**PR説明テンプレート**:

```markdown
## 概要
空のセッションデータ（$_SESSION = []）をRedisに書き込まないようにするため、
EmptySessionFilterを実装しました。

## 変更内容
- EmptySessionFilter クラスを追加
- EmptySessionFilterTest を追加
- creating-filters.md にドキュメントを追加

## 動機
空のセッションをRedisに書き込むと、不要なキーが蓄積されパフォーマンスに
影響する可能性があるため。

## テスト
- ユニットテスト追加: EmptySessionFilterTest
- 統合テスト追加: PreventEmptySessionCookieIntegrationTest
- 全テストがパス: `composer test`

## 関連Issue
Closes #123
```

### 6. レビュー対応

**レビュー指摘への対応**:

```bash
# 各指摘事項ごとに個別のコミットを作成
git add src/Hook/EmptySessionFilter.php
git commit -m "レビュー指摘: wasLastWriteEmpty()メソッドにPHPDocを追加"

git add tests/Hook/EmptySessionFilterTest.php
git commit -m "レビュー指摘: テストケースにエッジケース（nullチェック）を追加"

# プッシュ
git push
```

**レビュー完了後**:

```markdown
## レビュー対応完了

以下の指摘に対応しました：

- [x] wasLastWriteEmpty()メソッドにPHPDocを追加
- [x] テストケースにエッジケースを追加
- [x] ドキュメントの誤字を修正

最新のコミット: abc1234

全てのチェックがパスしていることを確認しました。
```

## コミット規約詳細

### コミットメッセージの構成

```
<簡潔な説明（1行、50文字程度）>

<空行>

<詳細な説明（必要に応じて）>
- 箇条書きでも可
- 複数行でもOK

<空行>

<関連情報>
Closes #123
Related to #456
```

### 日本語での記述

このプロジェクトでは**日本語**でコミットメッセージを記述します：

```bash
# ✓ 正しい
git commit -m "セッションIDマスキング機能を追加してログ出力時のセキュリティを向上"

# ✗ 間違い（英語）
git commit -m "Add session ID masking feature"
```

### コミットのベストプラクティス

1. **理由（WHY）を説明する**:
```bash
# ✓ 良い例
"Redis接続失敗時の復旧を改善するためexponential backoffを実装"

# ✗ 悪い例
"backoffを実装"
```

2. **アトミックなコミット**:
```bash
# ✓ 良い例：1つの論理的な変更
git commit -m "EmptySessionFilterを追加"

# ✗ 悪い例：複数の無関係な変更
git commit -m "FilterとSerializerを追加、テストも修正"
```

3. **完結したコミット**:
```bash
# ✓ 良い例：テストも含める
git add src/Hook/EmptySessionFilter.php tests/Hook/EmptySessionFilterTest.php
git commit -m "EmptySessionFilterを追加"

# ✗ 悪い例：テストを別コミット
git commit -m "EmptySessionFilterを追加"
git commit -m "EmptySessionFilterのテストを追加"
```

## Git操作のベストプラクティス

### PRレビュー対応時

**推奨される対応方法**:

```bash
# 1. 各指摘事項ごとに個別のコミットを作成
git commit -m "レビュー指摘: メソッドにPHPDocを追加"

# 2. 全修正完了後、PRにまとめコメントを投稿

# 3. PHPStanとPHP CS Fixerが通過していることを確認
composer phpstan
composer cs-check
```

**避けるべき操作**:

```bash
# ✗ 悪い例：git commit --amend
git commit --amend -m "修正"
# → コミット履歴が書き換わり、レビュー差分が見にくくなる

# ✗ 悪い例：force push
git push --force
# → 他の開発者に影響を与える可能性
```

**例外**: 自分だけが作業しているブランチで、まだ誰もレビューしていない場合のみ`--amend`や`--force`を使用可能。

### mainブランチへのpush

**重要**: mainブランチへの直接pushは**禁止**です。

```bash
# ✗ 絶対にやってはいけない
git checkout main
git push origin main  # NG!!!
```

必ずプルリクエストを経由してください。

### force pushの制限

```bash
# ✗ main/masterへのforce pushは絶対禁止
git push --force origin main  # 危険!!!

# △ 自分のfeatureブランチのみ許可（慎重に）
git push --force origin feature/your-feature

# 警告が出た場合は必ず確認
```

## レビュープロセス

### レビュー観点

1. **機能性**:
   - 意図した動作をするか
   - エッジケースを考慮しているか
   - バグがないか

2. **テスト**:
   - 適切なテストが追加されているか
   - テストカバレッジが維持されているか
   - 全テストがパスするか

3. **コードスタイル**:
   - PSR-12に準拠しているか
   - PHPStan最大レベルをパスするか
   - 命名規則に従っているか

4. **ドキュメント**:
   - 必要なドキュメント更新があるか
   - PHPDocが適切か
   - 例が正しく動作するか

5. **後方互換性**:
   - 既存の機能を壊していないか
   - セマンティックバージョニングに従っているか

### レビューのタイムライン

- **初回レビュー**: 通常1-3日以内
- **フィードバック**: 具体的な改善提案
- **承認**: 全チェックがパスし、レビューアが承認
- **マージ**: メンテナーがマージ

### セルフレビューチェックリスト

PRを作成する前に、以下を確認してください：

```markdown
- [ ] PHPStan最大レベルがパス（`composer phpstan`）
- [ ] PHP CS Fixerがパス（`composer cs-check`）
- [ ] 全テストがパス（`composer test`）
- [ ] 新機能にはテストを追加
- [ ] 必要に応じてドキュメントを更新
- [ ] コミットメッセージが規約に従っている
- [ ] PRの説明が十分に詳細
```

## ライブラリとしての特性

### composer.lockの扱い

このプロジェクトは**ライブラリ**であるため：

- `composer.lock`はリポジトリに**コミットしない**
- CIは毎回最新の互換性のある依存関係を解決
- 破壊的変更を避け、後方互換性を重視

### セマンティックバージョニング

バージョン番号: `MAJOR.MINOR.PATCH`

- **MAJOR**: 破壊的変更
- **MINOR**: 後方互換性のある機能追加
- **PATCH**: 後方互換性のあるバグ修正

**例**:
```
1.2.3 → 1.2.4: バグ修正（PATCH）
1.2.3 → 1.3.0: 新機能追加（MINOR）
1.2.3 → 2.0.0: 破壊的変更（MAJOR）
```

## コントリビューションの種類

### バグ報告

Issueで報告する際の情報：

```markdown
## バグの説明
空のセッションデータが書き込まれてRedisにゴミが残る

## 再現手順
1. `$_SESSION = []` を設定
2. `session_write_close()` を実行
3. Redisを確認

## 期待される動作
空のセッションは書き込まれないべき

## 実際の動作
Redisにキーが作成される

## 環境
- PHP: 7.4.33
- ext-redis: 5.3.7
- Redis: 6.2.6
```

### 機能提案

```markdown
## 機能の概要
空セッション書き込みを防ぐフィルター機能

## 動機
不要なRedisキーの蓄積を防ぐ

## 提案される実装
WriteFilterInterfaceを実装したEmptySessionFilter

## 代替案
- WriteHookで実装
- SessionHandler内部で実装

## 影響範囲
- 新しいクラスの追加
- 既存機能への影響なし
```

### ドキュメント改善

```markdown
## 改善したいドキュメント
doc/plugin-developers/creating-filters.md

## 現在の問題
EmptySessionFilterの使用例が不足

## 提案される改善
実際の使用例とベストプラクティスを追加
```

### コード改善（リファクタリング）

```markdown
## リファクタリングの目的
RedisConnectionのリトライロジックを共通化

## 変更内容
- リトライロジックを専用メソッドに抽出
- テストを追加

## 影響範囲
- 内部実装のみ
- 公開APIは変更なし
```

## CI/CDとの連携

### GitHub Actionsのチェック

PRを作成すると、自動的に以下がチェックされます：

1. **PHPStan**: 静的解析
2. **PHP CS Fixer**: コードスタイル
3. **PHPUnit**: テスト実行

**全てのチェックがパス**しないとマージできません。

### ローカルでのチェック

```bash
# CI と同じチェックをローカルで実行
composer check

# 内訳:
# - composer phpstan
# - composer cs-check
# - composer test
```

## よくある質問

### Q: PRが大きすぎる場合は？

**A**: 複数のPRに分割してください：

```
PR #1: コアの実装（EmptySessionFilter）
PR #2: ドキュメント追加
PR #3: 統合テスト追加
```

### Q: レビューで大幅な変更が必要な場合は？

**A**: 新しいブランチで作り直すことも検討してください：

```bash
# 新ブランチを作成
git checkout -b feature/your-feature-v2

# 必要な変更を実装
# ...

# 新しいPRを作成
# 古いPRはクローズ
```

### Q: コミット履歴を綺麗にしたい場合は？

**A**: マージ後は気にしなくてOKです。maintainerがSquash Mergeで統合します。

### Q: 緊急のバグ修正は？

**A**: 通常のワークフローに従いますが、PRに`[urgent]`タグを付けてください：

```
[urgent] セッションID衝突を防ぐバグを修正
```

## まとめ

コントリビューションの流れ：

1. **Issue確認** → 議論
2. **ブランチ作成** → 実装
3. **コミット** → コミット規約に従う
4. **チェック** → phpstan + cs-check + test
5. **PR作成** → 詳細な説明
6. **レビュー対応** → 個別コミット
7. **マージ** → メンテナーが承認後

**重要なポイント**:
- コミットメッセージは日本語で、理由（WHY）を説明
- 必ず`composer check`をコミット前に実行
- レビュー指摘には個別コミットで対応
- main/masterへの直接push/force pushは禁止

## 関連ドキュメント

- [testing.md](testing.md) - テスト戦略と実行方法
- [code-style.md](code-style.md) - コーディング規約
- [architecture.md](architecture.md) - システムアーキテクチャ
- [GitHub Issues](https://github.com/uzulla/enhanced-redis-session-handler.php/issues)

## 質問・サポート

不明な点があれば：

1. [GitHub Issues](https://github.com/uzulla/enhanced-redis-session-handler.php/issues)で質問
2. [GitHub Discussions](https://github.com/uzulla/enhanced-redis-session-handler.php/discussions)で議論
3. メンテナーに直接連絡

あなたのコントリビューションをお待ちしています！
