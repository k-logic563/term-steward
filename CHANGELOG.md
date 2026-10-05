# Changelog

## 0.1.1

- WordPress.org向けの公開説明とgettext原文を英語化
- WordPress.org Language Packを使う構成へ変更し、同梱翻訳ロードを削除
- 配布ZIPから`.po`・`.mo`・`.pot`・`.l10n.php`を除外
- 配布ZIPに`composer.json`を追加

## 0.1.0

- 製品識別子をTerm Stewardへ統一し、旧名称の別プラグインと同居できる独立したnamespace、hook、Ajax、asset、DB、optionを採用
- 標準カテゴリー・タグの検索、絞り込み、並べ替え、ページネーション
- 名称と明示的な slug の変更、同一 taxonomy 内での統合、完全未使用タームの削除
- 実行前プレビュー、複数計画の一括開始、有界バッチ、中断後の再開
- 操作履歴、変更ジャーナル、安全確認付き Undo
- 日本語の管理画面とアクセシビリティ対応
