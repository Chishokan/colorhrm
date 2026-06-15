# 冨松先生（GAS版担当）への引き継ぎ・依頼書

宛先: 冨松先生がお使いの Claude / 冨松先生
差出: Color HRM **Xサーバー版（PHP + MySQL）** 担当（純平）side
最終更新: 2026-06-15

---

## 0. この文書の目的

Color HRM には現在2系統あります。

- **GAS版**（冨松先生）: Google Apps Script + スプレッドシート。現行運用。
- **Xサーバー版**（純平）: PHP + MySQL。`https://chishokan.co.jp/colorhrm/` で稼働中。GAS版とは独立に育てています。

Xサーバー版を前へ進めるために、**冨松先生側からいただきたいものが3点**あります（§3）。
この文書を冨松先生の Claude にそのまま渡せば、必要な作業内容が伝わるように書いています。

---

## 1. Xサーバー版の現状（参考）

本番稼働中（フェーズ1〜6）。GAS版の設計仕様書に沿って移植しています。

| 機能 | 状態 |
| :-- | :-- |
| 独自ログイン＋権限（admin / staff / teacher） | 稼働 |
| 講師マスター（staff）・講師一覧 | 稼働 |
| 研修マスター / 研修進捗（自己申告→承認フロー） | 稼働 |
| 採用（candidates）・選考・**採用決定→講師化** | 稼働 |
| 履歴書アップロード＋OCR（OCRはAPIキー設定で有効化） | 稼働 |
| 応募者CSVインポート（データ移行） | 稼働 |

- リポジトリ: GitHub `Chishokan/colorhrm`（PHPは `app/`、SQLは `migrations/` `seeds/`、資料は `docs/`）
- DB: tenants / users / staff / training_items / training_progress / candidates（全テーブルに `tenant_id`）

---

## 2. データモデルの対応（GAS Sheets ↔ Xサーバー MySQL）

両系統で概念は揃えています。列名は GAS=camelCase、Xサーバー=snake_case。

| GASシート | Xサーバーtable | 備考 |
| :-- | :-- | :-- |
| Candidates（28列） | `candidates` | 採用パイプライン。CSVで移行（§3-A） |
| Staff（16列） | `staff` | 採用決定で candidates から自動生成 |
| TrainingItems | `training_items` | `order`→`sort_order`（予約語回避） |
| TrainingProgress | `training_progress` | status に「申告中/差戻し」を追加（v2機能を先行実装） |
| Users | `users` | GASはSSO、Xサーバーは独自ログイン（email/password_hash） |

---

## 3. 冨松先生にお願いしたいこと（優先度順）

### A.【データ移行】Candidates（応募者）データのエクスポート

Xサーバー版にCSVインポート機能が出来たので、**既存の応募者データを移せます**。

- **やること**: GAS版（または元スプレッドシート）の **Candidates シートを CSV でエクスポート**。
- **形式**:
  - 文字コード **UTF-8**（Excel経由なら UTF-8 か Shift_JIS。どちらでも取り込み側で吸収します）
  - 1行目は見出し。**見出しは日本語のままでOK**（氏名 / 年齢 / 部署 / 校舎 / 担当 / 選考結果 / 応募月 / 応募日 / 入社日 …）。
    取り込み側で日本語・camelCase・snake_case を自動マッピングします。
  - 日付は `YYYY/MM/DD` か `YYYY-MM-DD`、真偽値は `1/0` か `〇/はい` 等でOK（正規化します）。
- **注意**: 応募者の**個人情報**を含みます。受け渡し方法（共有範囲・保管）は事前にご相談ください。
- 受領後、純平がインポート画面でプレビュー→取り込みます（`no`列が無くても自動採番）。

> 参考: 取り込みの正準フォーマット（snake_case見出し）テンプレートも用意済みです。
> 必要なら共有しますが、**日本語見出しのまま素直にエクスポートいただくのが最短**です。

### B.【移植ゴールの確定】GAS版の現状スナップショット

設計仕様書（2026-06-06）に**未反映の後付けサービス**があります。Xサーバー版に移植するか判断するため、内容を教えてください。

- 対象: **`MeetingService.gs` / `LessonService.gs` / `QuestionService.gs`**
- 知りたいこと（各サービスについて1〜数行で可）:
  1. 何をする機能か（目的・ユースケース）
  2. 扱うデータ（どのシート/列を読み書きするか）
  3. 主な入出力・画面（あれば）
  4. 現在の利用状況（実運用中か、試験的か）
- 可能なら `.gs` 本体も共有いただけると、移植要否・規模を見積もれます。

### C.【方針決定】OCRの実行基盤（Vision API）

GAS版は Google Drive 内蔵OCR（無料）。Xサーバー版（PHP）はそれが使えないため **Google Cloud Vision API** を代替に実装済み（キーを入れれば有効化）。

- 決めたいこと:
  1. **どのGCPアカウント/プロジェクト**で Vision API を使うか
  2. **APIキー**の発行（請求先の紐付け）
  3. **月予算の上限**（目安: 1,000ページ/月まで無料、以降 約$1.5/1,000ページ）
- 決定後、純平が `config.php` にキーを設定すれば、応募者詳細の「OCRで読み取り」が有効になります。
- 未決の間も、履歴書の**アップロード・閲覧・手入力**は使えます（OCRだけ保留）。

---

## 4. 受け渡しサマリ（チェックリスト）

冨松先生側で用意 → 純平へ受け渡し：

- [ ] **A. Candidates CSV**（UTF-8 / 日本語見出し可 / PII取扱い相談）
- [ ] **B. Meeting/Lesson/Question の機能要約**（＋可能なら `.gs`）
- [ ] **C. OCR方針**（GCPアカウント・APIキー・月予算）

純平側で対応：

- [ ] 受領CSVをインポート画面で取り込み（プレビュー→確定）
- [ ] B の内容を見て移植要否・計画を提示
- [ ] C 決定後 `config.php` にキー設定しOCR有効化

---

## 5. 連絡・参照

- 本番: `https://chishokan.co.jp/colorhrm/`
- リポジトリ: `Chishokan/colorhrm`（`docs/HANDOFF.md`・`docs/DEPLOY.md`・`docs/PHASE4_DESIGN.md` も参照）
- デプロイ: `main` への反映で Xサーバーへ自動デプロイ（GitHub Actions / FTPS）

以上です。まずは **A（応募者CSV）** が頂ければデータ移行を進められます。
**B・C** は並行で、決まり次第で構いません。
