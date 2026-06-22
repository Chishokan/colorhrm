# app

Xサーバー版 Color HRM のデプロイ対象ファイル一式（PoC 本体）です。
本番反映時は **この `app/` 配下のファイルを**、Xサーバーの
`/chishokan.co.jp/public_html/colorhrm/` へアップロードします
（ファイルマネージャの「アップロード」または FTP/SFTP）。

| ファイル | 役割 |
|---|---|
| `db.php` | DB接続（PDO）。`config.php` を require |
| `auth.php` | 独自認証（セッション）。`current_user` / `require_login` / `login_attempt` / `logout` / `h()` |
| `helpers.php` | 共通ヘルパー（ロール別ナビ・`require_role` / CSRF・カラー/ステータスのバッジ） |
| `dashboard.php` | ダッシュボード（admin/staff）。応募/採用/要対応サマリー |
| `candidates.php` | 応募者一覧（admin/staff）。検索・フィルタ |
| `candidate.php` | 応募者 詳細/編集/新規（admin/staff）＋採用決定→講師化＋履歴書アップロード/OCR |
| `resume_view.php` | 履歴書画像の認証付き配信（直アクセスは uploads/resumes/.htaccess で遮断） |
| `index.php` | 講師一覧（admin/staff、要ログイン）。詳細リンク＋達成率バー＋講師追加ボタン |
| `staff_new.php` | 講師の新規追加（admin/staff）。最小項目で作成→詳細画面へ |
| `staff_detail.php` | 講師詳細・編集（admin/staff）。プロフィール／カラー昇格／退職復職／育成達成率／1on1面談／質問回答参照 |
| `mypage.php` | マイページ（teacher が自分の研修進捗を見る・自己申告） |
| `training.php` | 研修管理（admin/staff）。承認インボックス＋講師ごとの進捗編集 |
| `training_master.php` | 研修マスター管理（admin）。研修項目（部門×対象カラー）のCRUD＋種別/モジュール（教材）紐付け |
| `classrooms.php` | 教室マスター管理（admin）。配属教室/担当教室の選択肢 |
| `import.php` | データ移行（admin）。応募者CSVを candidates へ取り込み（プレビュー→確定） |
| `staff_io.php` | 講師情報CSV入出力（admin）。全講師エクスポート＋CSVインポート（id/メールで更新・無ければ新規） |
| `questions.php` | 質問管理（admin）。講師への質問CRUD（全員/特定講師） |
| `lessons.php` | 研修動画/資料 管理（admin）。module_key 別にCRUD |
| `lessons_view.php` | 研修コンテンツ・ビューア（module_key の動画/資料を順番表示） |
| `evidence_view.php` | テスト証跡画像の認証付き配信 |
| `users.php` | ユーザー管理（admin）。作成・ロール/講師紐付け・パスワード変更 |
| `login.php` | ログイン画面 |
| `logout.php` | ログアウト |
| `config.php.example` | DB接続情報のテンプレート。`cp config.php.example config.php` で実値版を作る |

> フェーズ3（自己申告＋承認）は `helpers.php` / `mypage.php` / `training.php` /
> `training_master.php` / `users.php` で実装。
> 動作には `migrations/002_phase3.sql` の適用が必要です（`training_progress` の申告/承認列）。
>
> フェーズ4-1/4-2（採用）は `dashboard.php` / `candidates.php` / `candidate.php` で実装。
> 動作には `migrations/003_phase4.sql` の適用が必要（`candidates` テーブル）。OCR（4-3）は方針決定後。

## アップロードの注意

- `config.php`（実値）は **Git管理外**。サーバー上にのみ置く。
- `config.php.example` は **アップロード不要**（テンプレートのため）。
- `setup.php`（初期管理者作成）/ `_test.php`（DB診断）は、生成して使ったら **必ず削除**する。
- DBホストは `localhost` 不可。`mysql8055.xserver.jp` のように指定する（`config.php.example` 参照）。

詳細は `../docs/HANDOFF.md` を参照。
