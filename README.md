# Term Steward

Term Steward は、WordPress 管理画面から標準カテゴリーと標準タグを安全に整理するためのプラグインです。変更前のプレビュー、有界バッチ、操作履歴、対応可能な Undo を通して、意図しない投稿変更を防ぎます。

- WordPress.org Contributors: `klogic563`
- GitHub: [k-logic563/term-steward](https://github.com/k-logic563/term-steward)
- サポート・不具合報告: [GitHub Issues](https://github.com/k-logic563/term-steward/issues)

## MVP でできること

- カテゴリーとタグの検索、並べ替え、未使用絞り込み、数値ページネーション
- 名称変更と、明示した場合だけの slug 変更
- 同一 taxonomy 内の既存タームへの統合
- WordPress 全体で完全に未使用なタームの削除
- カテゴリーとタグの計画をまとめたプレビューと一括実行
- 中断可能な有界バッチ、結果・警告・一部失敗の記録
- 操作履歴からの Undo プレビューと安全な Undo

relationship の変更対象は、標準投稿タイプ `post` の公開済み投稿だけです。固定ページ、カスタム投稿タイプ、下書き、非公開、予約、承認待ち、ゴミ箱、自動下書きの relationship は変更しません。

## MVP 対象外

AI 分類、本文解析、カスタム投稿タイプ、カスタム taxonomy、CSV 入出力、定期実行、Redo、複雑な計画編集、類似語の自動統合、SEO リダイレクト、マルチサイト全体の一括処理は対象外です。

## 対応環境

- WordPress 6.6 以上
- PHP 8.2 以上
- MySQL 8.0 以上、または MariaDB 10.11 以上

WordPress 6.6.2と7.1.1で検証済みです。WordPress.orgの`Tested up to`には、実測済みの最も高いメジャーバージョンである7.1を使用します。

## インストールと有効化

1. WordPress 管理画面の「プラグイン > 新規追加 > プラグインのアップロード」で配布 ZIP を選びます。
2. インストール完了後に Term Steward を有効化します。利用者側で Composer を実行する必要はありません。
3. 「ツール > Term Steward」を開きます。画面の利用にはカテゴリー管理と公開済み投稿を編集する権限が必要です。

本番サイトへ導入する前にデータベースとアップロードファイルをバックアップし、最初にステージング環境で確認してください。

## 基本操作

1. 「ツール > Term Steward」を開きます。
2. 「カテゴリー」または「タグ」タブを開きます。
3. 対象を選択します。
4. 処理パネルで名称変更、統合、削除のいずれかを設定します。
5. 「計画に追加」を押します。
6. 「操作計画」タブを開きます。
7. 「変更内容を確認」を押します。
8. プレビューモーダルで対象、変更後、影響数、削除または保持、警告を確認します。
9. 内容を変更せず閉じる場合は「キャンセル」、処理を開始する場合は「実行」を選びます。
10. 必要に応じて「操作履歴」から Undo します。

ブラウザを閉じるなどして実行が中断した場合は、同じ管理者で「操作計画」を開き、表示された再開操作から未処理項目を続行します。新しい計画を作り直したり、同じ操作を重ねて開始したりしないでください。

## 操作履歴と Undo

実行を開始した操作は現在の管理者の操作履歴に記録されます。対応可能な操作は履歴詳細から Undo 内容をプレビューし、別の有界バッチとして取り消せます。Undo は元操作が実際に変更した差分だけを対象とし、現在値や relationship が後から変わっている場合は上書きせず、取り消し不可または一部失敗として扱います。すべての状況で完全に元へ戻せることを保証する機能ではありません。

## ローカル開発

Docker Desktop（または Docker Engine と Compose v2）、GNU Make 互換の `make`、Git が必要です。

```sh
cp .env.example .env
make setup
```

既定の管理画面は `http://localhost:8080/wp-admin/`、Term Steward は「ツール > Term Steward」にあります。詳しい起動・シード・権限確認手順は [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) を参照してください。

## テスト

```sh
make check
```

このコマンドは PHPCS、JavaScript lint、PHPUnit と WordPress 統合テストを実行します。個別には `make phpcs`、`make lint-js`、`make test` を利用できます。

## 配布 ZIP の作成

Docker、Compose v2、`rsync`、`zip`、`shasum` が利用できる環境で次を実行します。

```sh
make dist
```

`dist/term-steward-0.1.1.zip` と対応する `.sha256` が生成されます。ビルドは隔離したステージへ本番ファイルだけをコピーし、`composer install --no-dev --prefer-dist --optimize-autoloader` で autoload を作成します。`composer.json`はWordPress.orgでソース構成を確認できるよう配布ZIPに含め、開発用dependencyを固定する`composer.lock`は含めません。

## 翻訳

配布ZIPに`.po`、`.mo`、`.pot`、`.l10n.php`は同梱しません。WordPress.org公開後の日本語翻訳はtranslate.wordpress.orgに登録し、WordPress.org Language Packとして配信する方針です。リポジトリ内の`languages/`は翻訳作業用の資産であり、`.distignore`と配布スクリプトの両方で配布対象外にしています。

## プラグインの有効化

ローカル Docker 環境では次を実行します。

```sh
make activate
docker compose run --rm wp-cli plugin status term-steward
```

## 安全上の注意

- 本番利用前に、WordPress のデータベースとアップロードファイルを必ずバックアップしてください。
- 実行前にプレビューの対象、変更内容、保持・削除予定、警告を確認してください。
- 公開済み標準投稿以外の relationship は対象外です。ただし、統合元や削除対象の安全判定では WordPress 全体の relationship を確認します。
- 対象外オブジェクトで使用中の統合元、子カテゴリーを持つ統合元、デフォルトカテゴリーは安全側に保持します。
- プレビュー後に状態が変わった操作は実行せず、新しい管理者変更を Undo で上書きしません。
- 下書き、非公開、予約投稿、固定ページ、カスタム投稿タイプで使用中の分類は「完全未使用」ではないため削除しません。

## 既知の制限

- 対象 taxonomy は標準カテゴリーと標準タグだけです。
- relationship の変更対象は公開済み標準投稿だけです。
- マルチサイト全体の一括処理、Redo、CSV 入出力、定期実行、SEO リダイレクトには対応しません。
- Undo は後から加えられた管理者の変更、削除済み投稿、名前・slug・親カテゴリーの競合を安全側で保持します。
- プラグインを無効化または WordPress 管理画面から削除しても、操作履歴テーブル、Operation Item テーブル、Change Journal テーブル、DB schema version option はデータベースに残ります。監査記録、中断・復旧情報、再インストール後の履歴確認、誤操作時の復旧情報を保護するためです。
- 0.1.1 には、これらのデータを完全削除する設定はありません。将来追加する可能性はありますが、現在は実装されていません。
- Term Steward は独立した`term_steward_*`永続化領域を使用します。旧開発名称に対応するテーブルやoptionは、別プラグインの所有物である可能性があるため、自動移行・読取・更新・削除しません。

## 不具合報告

再現手順、期待した結果、実際の結果、Term Steward・WordPress・PHP・データベース・ブラウザのバージョン、関連する画面のエラー文言を添えてください。パスワード、Cookie、nonce、API キー、個人情報、データベースの完全なダンプは添付しないでください。

報告先: [https://github.com/k-logic563/term-steward/issues](https://github.com/k-logic563/term-steward/issues)

開発ツリーそのものは配布成果物ではありません。利用時は `make dist` で生成し、検証済みチェックサムと一致する ZIP を使用してください。
