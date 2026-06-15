# GAS版 → Xサーバー版（PHP/MySQL）移行計画

最終更新: 2026-06-15 / 出典: GASプロジェクト全15ソース＋frontendの実機レビュー

GAS版（Spreadsheet＝DB / Drive＝ファイル / Google SSO＝認証）の全機能を、構築済みのXサーバー版（PHP＋MySQL）へ移すための段取り。

---

## 1. 現状の対応表（GAS機能 → Xサーバー版）

| 領域 | GAS版の機能 | Xサーバー版の状況 |
| :-- | :-- | :-- |
| 認証 | Google SSO（パスワード無し）/ role 3種 | ✅ 独自ログインで置換。△ 権限フラグ `viewRecruitment`/`viewStaffList` は未実装（roleの粗い制御のみ） |
| 採用 | Candidates CRUD・convertToStaff・OCR | ✅ 実装済（OCRはVision APIキーで有効化） |
| 研修マスタ | TrainingItems CRUD（type/moduleKey含む） | ✅ CRUD実装。△ `type`(研究/テスト)・`module_key` は列はあるがUI未使用 |
| 研修進捗 | 自己申告→承認、テスト証跡提出、直接更新 | ✅ 申告/承認/差戻し実装。❌ **submitTest（テスト写真提出）** と type別出し分けは未実装 |
| 講師 | 一覧＋**詳細編集**（色/目標/メンター/期限/複数部門/写真/退職復職）、**updateColor(昇格)** | △ **一覧表示のみ**。❌ 講師詳細・編集・昇格・退職処理が未実装（最大の差分） |
| 育成進捗 | **達成率(goalSummary)** バー＋ダッシュボード**アラート**（期限超過/低達成） | ❌ 未実装 |
| マイページ | 本人情報・達成率・自己申告・**写真**・**質問回答**・給与リンク | ✅ 基本実装。❌ 写真／質問回答／達成率バー未 |
| ダッシュボード | KPI＋カラー別人数＋承認待ち＋**教室別カラーマトリクス**＋**目標アラート** | △ 基本KPIのみ。❌ マトリクス/アラート未 |
| ユーザー管理 | role/有効/権限フラグ/再共有 | ✅ 実装（再共有はMySQL化で不要）。△ 権限フラグ未 |
| データ移行 | Migration/ProgressImport/Setup/ColorSeed | ✅ 実データ取り込み済（candidates/staff/items/progress） |
| **面談(1on1)** | MeetingService（記録CRUD） | ❌ **未移行** |
| **研修コンテンツ** | LessonService（動画/資料 CRUD＋viewer、moduleKey連携） | ❌ **未移行** |
| **質問/回答** | QuestionService（全員/特定講師、回答） | ❌ **未移行** |
| **給与/時給** | WageRates表・usePayroll・別給与アプリ連携(リンク/メール) | ❌ **未移行**（計算実体は外部GASアプリに委譲） |
| 通知メール | ウェルカム/質問/案内（MailApp） | ❌ 未（任意） |

---

## 2. コア・ビジネスロジック（移植時の要点）

GAS実装から抽出した「移植で外せない」ロジック：

- **カラー序列**：`WHITE→GREEN→BLUE→YELLOW→RED`。
- **達成率(goalSummary)**：現カラーの**次**から**目標カラー**までに必要な研修項目（`共通`部門＋本人の各部門）のうち、`合格`または`対象外`の割合。`目標≦現状`なら対象外（"継続"）。
- **昇格は手動**：達成率は表示・アラート用。実際の昇格は `updateColor`（メンターがカラーを上げ、`color_certified_date`=当日を記録）。項目全合格で自動昇格はしない。
- **進捗ワークフロー**：`未着手→申告中→（承認）合格 /（差戻し）未着手`。研修(研究系)は自己申告、テスト系は**証跡写真**提出で `申告中`。
- **ダッシュボードアラート**：`目標期限 < 今日 かつ 達成率<100%` → danger（期限超過）／`達成率<50%` → warning。
- **時給**：カラー×部門で授業時給が変動（例 智翔館 YELLOW=1500/RED=2000、他部門 YELLOW=1200/RED=1500、オペ時給は一律1031）。**ただし給与計算は別アプリ（`PAYROLL_APP_URL`）**。本アプリはレート表示とリンク/メールのみ。

---

## 3. 追加が必要なデータモデル

既存（candidates/staff/training_items/training_progress/users）に加え：

| テーブル | 主な列 | 用途 |
| :-- | :-- | :-- |
| `meetings` | staff_id, meeting_date, mentor_name, content, next_date, created_at | 1on1記録 |
| `lessons` | module_key, sort_order, title, material, video_url, video_duration, note | 研修動画/資料 |
| `questions` | text, target_staff_id(NULL=全員), is_active, sort_order, created_at | 管理者→講師の質問 |
| `answers` | question_id, staff_id, answer, answered_at | 回答 |
| `pay_rates` | color, department, class_rate, ops_rate | 時給表（WageRates 25件） |

既存テーブルへの追加：`training_progress.evidence_file/submitted_by`（テスト証跡：004で追加済）、`staff.photo_file/use_payroll`（写真・給与対象：004相当。本番は未適用なら要追加）。
※ 関連保持のため各テーブルに `source_uid`（GAS UUID）も持たせると再取り込みが安全。

---

## 4. 移行フェーズ（推奨順）

### フェーズA：講師詳細・育成の中核 ★最優先・最大の差分
- `staff_detail.php`（admin/staff）：色/目標/メンター/目標期限/複数部門/email/写真/`use_payroll` の編集、**updateColor（昇格＋認定日）**、**退職/復職**。
- **達成率ロジックをサーバ側1箇所に集約**（`helpers`）。講師一覧の進捗バー・マイページ・ダッシュボードで共用。
- 研修チェックリストを講師詳細に内包（承認/差戻し/直接更新）。
- → これで「採用→講師化→育成（色を上げる）」が一気通貫で運用可能に。

### フェーズB：面談(Meeting) ＋ 質問/回答(Question/Answer)
- migration：`meetings` / `questions` / `answers`。
- 講師詳細に1on1記録（一覧・追加・削除）。
- 質問管理画面（admin）＋マイページで回答。
- 実データ取り込み（Meetings 1件・Questions 1件・Answers 0件）。

### フェーズC：研修コンテンツ(Lesson) ＋ テスト証跡提出
- migration：`lessons`。
- レッスン管理（admin・moduleKey別CRUD）＋ビューア（研修項目の `module_key` から動画/資料を表示）。
- **submitTest**：テスト型項目は証跡画像アップロード（履歴書アップロードの仕組みを再利用）→ `申告中`＋`evidence_file`。type(研究/テスト)で操作を出し分け。
- 実データ取り込み（Lessons 31件）。

### フェーズD：給与レート(PayRates) ＝ フェーズ5
- migration：`pay_rates`＋WageRates 25件取り込み。
- 時給表の表示・管理、`use_payroll` 表示、外部給与アプリへのリンク（`payroll_url` を config 化）。
- ※ 給与計算自体は外部アプリのままにするか要確認（GAS版もそうしている）。

### フェーズE：権限細分化・ダッシュボード強化・通知（仕上げ）
- `users` に `view_recruitment`/`view_staff_list` フラグ＋アクセス制御ミドルウェア。
- ダッシュボード：教室別カラーマトリクス、目標進捗 要注意リスト（アラート）。
- 通知メール（任意・PHP mail/SMTP）。

---

## 5. 移行時の技術的注意（Google依存の置換）

1. **ファイルストレージ**：写真/履歴書/証跡が Drive fileId。→ Xサーバーの `uploads/`（認証付き配信）に置換（履歴書で実装済の方式を流用）。
2. **OCR**：GASはDocs変換OCR。→ Vision API（実装済・キー設定で有効化）。
3. **メール**：MailApp → PHP mail/SMTP（任意）。
4. **スプレッドシート共有**：ユーザー追加時のDrive共有はMySQL化で**不要**（削除）。
5. **値ゆれ**：boolean(`TRUE`/true)・日付(ISO/`yyyy/MM/dd`)混在 → 取り込み時に正規化（実装済の正規化関数を踏襲）。
6. **複数部門**：`departments` はカンマ区切り。当面は文字列のまま（GAS互換）、将来は中間テーブル化を検討。
7. **達成率計算の重複**：GASは4箇所に重複。PHPでは**サーバ側1関数に集約**して再利用。

---

## 6. 着手前に決めたいこと

- **昇格運用**：GAS同様の「手動昇格（メンターが色を上げる）」を維持でよいか（推奨：維持）。
- **給与**：計算は外部給与アプリのまま／本アプリは時給表＋リンクのみ、でよいか（推奨：維持）。
- **権限フラグ**（viewRecruitment/viewStaffList）を移植するか、当面roleの粗い制御で進めるか。
- フェーズの着手順（推奨：A → B → C → D → E）。
