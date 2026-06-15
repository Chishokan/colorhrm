# フェーズ4 設計：採用（candidates）＋ OCR代替

最終更新: 2026-06-12 / 対象: Xサーバー版（PHP + MySQL）

GAS版の設計仕様書「Color HRM 設計仕様書」§4 Candidates（28列）・§6 OCR仕様、および
`CandidateService.gs` / `OcrService.gs` の実装に忠実に合わせた、Xサーバー版フェーズ4の設計です。

> 位置づけ：採用申込 → 選考 → 採用決定 → 講師化（staff自動生成）までの「採用パイプライン」。
> フェーズ1〜3で作った staff / training の **手前**にあたる工程。

---

## 1. スコープ

### 含む（v1）
- 応募者の一覧（検索・フィルタ）／詳細／登録／編集
- 選考ステータス管理（`selection_result`）
- 採用決定 → 講師登録（`staff` を自動生成、`candidate_id` で紐付け）
- 履歴書アップロード → OCR → 確認・修正 → 反映（**OCRはVision API代替。下記§6**）
- ダッシュボード（今月の応募数・採用数・要対応件数）

### 含まない（後続）
- 既存スプレッドシートからの一括データ移行 → **フェーズ6**（冨松さんからのエクスポート後）
- OCRの高度な項目抽出（学歴・職歴の構造化）→ v2

---

## 2. データモデル（`candidates`）

`migrations/003_phase4.sql`（ドラフト・未適用）に DDL。GAS版28列を snake_case に統一し、`tenant_id` を付与。

| GAS列 | 本テーブル列 | 型 | 備考 |
| :-- | :-- | :-- | :-- |
| id (UUID) | `id` | INT AI | 本PoCは INT 主キー運用 |
| no | `no` | INT | 通し番号 |
| appliedMonth/Day | `applied_month` / `applied_day` | INT | 応募月日 |
| name / age / note | `name` / `age` / `note` | — | |
| assignee | `assignee` | VARCHAR | 担当者名 |
| employmentType | `employment_type` | VARCHAR | 雇用形態 |
| department / school / jobType | `department` / `school` / `job_type` | VARCHAR | |
| recruitingMedia / referrer | `recruiting_media` / `referrer` | VARCHAR | |
| referralRewardPaid / specialRecruiting | `referral_reward_paid` / `special_recruiting` | TINYINT | |
| interviewDate | `interview_date` | DATE | |
| selectionResult | `selection_result` | VARCHAR | §3 参照 |
| hireDate / threeMonthCheckDate | `hire_date` / `three_month_check_date` | DATE | |
| continued | `continued` | VARCHAR(4) | 〇/✕ |
| continuationRewardPaid / initialResponse | `continuation_reward_paid` / `initial_response` | TINYINT | |
| resumeFileId | `resume_file` | VARCHAR | **Drive ID → サーバー保存パスに変更** |
| ocrExtracted / convertedToStaff | `ocr_extracted` / `converted_to_staff` | TINYINT | |
| createdAt / updatedAt | `created_at` / `updated_at` | DATETIME | |

---

## 3. 選考ステータスとパイプライン

`selection_result` の値（GAS仕様の合否結果に準拠）:

```
（空＝未選考） / 採用 / 不採用(書類) / 不採用(面接後)
/ 辞退(面接前) / 辞退(面接後) / お断り / 音信不通 / その他
```

パイプライン:
```
応募登録（手動 or OCR）
  → 初期対応（initial_response）
  → 面接（interview_date）
  → 選考結果（selection_result）
      └─ 「採用」→ 入社日(hire_date) → [採用決定] → 講師化（staff生成）
      └─ 3か月後 → three_month_check_date / continued（〇/✕）
```

---

## 4. 採用決定 → 講師化（convert_to_staff）

`CandidateService.gs#convertToStaff` を移植。ガード条件と生成内容をそのまま踏襲:

- **前提**：`selection_result === '採用'` かつ `converted_to_staff = 0`（二重登録防止）
- **生成**：`staff` に1行 INSERT
  - `candidate_id` = candidates.id（紐付け）
  - `name` / `school` / `hire_date` / `employment_type` / `recruiting_media` / `referrer` を引き継ぎ
  - `department`（candidates）→ `staff.departments` に格納（※GASは単数 department、本staffは複数形 departments 列）
  - `color_rank = 'WHITE'`、`target_rank = 'GREEN'`、`is_active = 1`
- **後処理**：candidates.`converted_to_staff = 1` に更新
- staff 側の受け皿列（candidate_id / employment_type / hire_date / recruiting_media / referrer）は
  **フェーズ1（001）で追加済み**。新規ALTER不要。

> 講師化された応募者は、そのまま既存の `index.php`（講師一覧）/ `training.php`（研修）に乗る。
> 採用→育成が1本につながる。

---

## 5. 画面構成（既存PHPの流儀に合わせる）

`require auth.php → helpers.php → require_login()`、`require_role`、CSRF、`render_header/footer` を踏襲。

| 画面 | ファイル（案） | ロール | 内容 |
| :-- | :-- | :-- | :-- |
| 応募者一覧 | `candidates.php` | admin/staff | 検索（氏名・備考）＋フィルタ（部署・選考結果・担当者）。応募月日で降順 |
| 応募者詳細/編集 | `candidate.php?id=` | admin/staff | 全項目編集・選考ステータス更新・履歴書アップロード/OCR・[採用決定] |
| 応募者新規 | `candidate.php`（id無し） | admin/staff | 手動登録（OCRなしでも可） |
| ダッシュボード | `dashboard.php` | admin/staff | 今月の応募数／採用数／要対応（未選考・初期対応未） |

- ナビ（`helpers.php#nav_links_for`）に admin/staff 向け「採用」「ダッシュボード」を追加。
- 一覧の絞り込みは `CandidateService.gs#list` のフィルタ（department / selectionResult / assignee / keyword）を踏襲。

---

## 6. OCR代替設計（最重要・要方針決定）

GAS版は Google Drive 内蔵OCR（画像→Googleドキュメント変換 → テキスト抽出）で**無料**。
Xサーバー（PHP）には同等機能が無いため**代替が必要**。`OcrService.gs#parseResumeText` の
**パース部（正規表現）はそのまま移植可能**で、テキスト抽出の前段だけ差し替える。

### 抽出ロジック（移植・共通）
`parseResumeText` の正規表現をPHPへ移植：
- 電話：`/0\d{1,4}[-\s]?\d{2,4}[-\s]?\d{4}/`
- メール：`/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/`
- 生年月日：`/(昭和|平成|令和)?\s*(\d+)\s*年\s*(\d+)\s*月\s*(\d+)\s*日/`
- 氏名：「ふりがな/フリガナ/氏 名」の次行
- 志望動機 / 自己PR：見出し行の直後数行

### テキスト抽出（前段）の選択肢

| 案 | 方式 | 費用 | 実装難度 | 備考 |
| :-- | :-- | :-- | :-- | :-- |
| **A（推奨）** | **Google Cloud Vision API**（`DOCUMENT_TEXT_DETECTION`）をPHP cURLで呼ぶ | 1,000ページ/月まで無料、以降 ~$1.5/1,000ページ | 低 | 日本語精度高。APIキー/GCPプロジェクト/予算合意が必要 |
| B | Tesseract OCR をサーバーで実行 | 無料 | 高 | Xサーバー（共有）に導入可否が不明・日本語精度が劣る |
| C | 手入力のみ（OCRなし） | 無料 | 最低 | まずCRUDだけ先行。OCRは後付け |

> ⚠️ **要決定（冨松さんと合意）**：Vision API を使うGCPアカウント・APIキー・予算。
> 引き継ぎメモ§でも「OCRの方針合意」が保留事項。
> → 設計上は **OCRをプラグイン化**し、未決でも C（手入力）で先行できる構成にする。

### 処理フロー（案A）
1. 応募者詳細から履歴書画像（JPG/PNG）をアップロード
2. サーバーの**Web公開外**ディレクトリに保存（§8）。`resume_file` にパス記録
3. Vision API へ画像を送信 → `fullTextAnnotation.text` を取得
4. `parseResumeText`（移植版）でフィールド抽出
5. 確認画面でプレビュー → 担当者が修正 → candidates に反映、`ocr_extracted = 1`

---

## 7. 追加・変更するファイル（案）

```
app/candidates.php       応募者一覧（検索・フィルタ）
app/candidate.php        応募者 詳細/編集/新規 + 採用決定 + 履歴書アップロード
app/dashboard.php        ダッシュボード（サマリー）
app/ocr.php              OCR実行エンドポイント（Vision API呼び出し・パース）※方針決定後
app/helpers.php          ナビに「採用 / ダッシュボード」を追加（既存に追記）
migrations/003_phase4.sql candidates テーブル（ドラフト→確定後に適用）
```

---

## 8. セキュリティ・個人情報（PII）

応募者情報・履歴書は**個人情報**。Xサーバー上での扱いに注意:

- 履歴書画像は **`public_html` の外**（Web非公開）に保存し、ダウンロードは認証付きPHP経由（`require_login` + 担当者チェック）で配信。
  - 例：`/home/chishokan/colorhrm_private/resumes/` に保存、`resume_view.php?id=` でストリーミング。
  - 公開ディレクトリに置く場合は `.htaccess` で `Deny from all` ＋ PHP経由配信。
- 一覧/詳細は `require_role(['admin','staff'])`。teacher からは不可視。
- アップロードは MIME/拡張子チェック（JPG/PNG）とサイズ上限。
- Vision API キーは `config.php`（Git管理外）に置く。コードに直書きしない。
- `.gitignore` に `/uploads/` は追加済み。Web非公開の保存先も Git 管理外。

---

## 9. 実装の段階計画

OCRの方針決定を待たずに着手できるよう、3段に分割:

1. **4-1：candidates CRUD＋一覧/詳細＋ダッシュボード**（OCRなし・手入力）✅ 実装済
   - `003_phase4.sql` 適用 → `candidates.php` / `candidate.php` / `dashboard.php`
2. **4-2：採用決定 → 講師化**（`convert_to_staff`）✅ 実装済
   - 既存 staff/training にそのまま接続
3. **4-3：履歴書アップロード＋OCR** ✅ 実装済（OCR本処理はAPIキー設定で有効化）
   - `candidate.php`：履歴書アップロード（JPG/PNG・8MB・MIME検証）
   - `resume_view.php`：認証付き配信（`uploads/resumes/.htaccess` で直アクセス遮断＝PII対策）
   - `helpers.php`：`vision_ocr_text()`（Vision API）＋ `parse_resume_text()`（GAS版移植）
   - **OCRは `config.php` の `vision_api_key` が空なら無効**（アップロード/閲覧は使用可）。
     キーを設定すると候補者詳細の「OCRで読み取り」が有効化（氏名を自動入力、他は参考表示）。

---

## 10. 未決事項・要確認

- [ ] **OCR方針**：Vision API のGCPアカウント・APIキー・予算（冨松さんと合意）。未決なら 4-3 を後回し。
- [ ] **`department` の単複**：candidates は単数、staff は `departments`（複数形・カンマ区切り運用）。
      講師化時は単数→そのまま `departments` に格納でよいか。
- [ ] **ダッシュボードの集計定義**：「要対応」の定義（未選考？初期対応未？面接日超過？）。
- [ ] **既存Candidatesデータ移行**：列マッピングはフェーズ6。エクスポート時期の確定。
- [ ] **履歴書保存先**：Web非公開ディレクトリのパス（Xサーバーのホーム配下）を確定。
