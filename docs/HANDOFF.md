# Color HRM（Xサーバー版）デプロイ & 引き継ぎメモ

最終更新: 2026-06-12 / 作成: フェーズ0〜2 完了時点

このメモは、Color HRM（人事部の人材管理アプリ）の **Xサーバー版（PHP + MySQL）** を、ここから先 **Claude Code で続ける**ための引き継ぎ資料です。

> ⚠️ 本ファイルには管理者ログインやDB接続情報など機微な値が含まれます。公開リポジトリには置かないこと。

## 0. 体制・役割分担

- **純平さん（このメモの担当）**: Xサーバー版（PHP + MySQL）への移行・実装
- **冨松さん**: 現行 **GAS版** の保守・デプロイ（clasp / jfagofor2014@gmail.com）
- 両者はデプロイ経路が別系統で独立。Xサーバー版はGAS版とは別物として育てる。

元プロジェクト（Google Drive 共有フォルダ）: `color-hrm/`

- 設計仕様: `docs/superpowers/specs/2026-06-06-color-hrm-design.md`
- 実装計画: `docs/superpowers/plans/2026-06-06-color-hrm.md`
- PoCソース: `colorhrm-xserver/`（今回サーバーに上げたもの）

## 1. 本番環境（稼働中）

| 項目 | 値 |
| :-- | :-- |
| 公開URL | https://chishokan.co.jp/colorhrm/ |
| 配置パス | `/chishokan.co.jp/public_html/colorhrm/` |
| Webサーバー | sv8550.xserver.jp（サーバーID: chishokan） |
| **MySQLホスト** | **mysql8055.xserver.jp** ← localhost不可。最重要 |
| DB名 | chishokan_colorhrm |
| DBユーザー | chishokan_chrm |
| 管理者ログイン | admin@chishokan.local / admin1234 ← **要変更（下記TODO）** |

ログイン → 講師一覧（index.php）が MySQL から直接表示される、独自認証のPoCが稼働中。

## 2. デプロイ済みファイル（6つ）

`colorhrm/` に置いてあるのは次の6ファイルのみ:

- `db.php` — DB接続（PDO）。config.php を require
- `auth.php` — 独自認証（セッション）。current_user / require_login / login_attempt / h()
- `config.php` — DB接続情報 ★Git管理外・サーバー上のみ
- `index.php` — 講師一覧（要ログイン）
- `login.php` — ログイン画面
- `logout.php` — ログアウト

`setup.php`（初期管理者作成）と `_test.php`（DB診断）は**役目を終えたので削除済み**。再生成したら使用後は必ず削除すること。

## 3. ハマりどころ（重要な学び）

1. **DBホストは mysql8055.xserver.jp**。localhost だと `Access denied for user 'chishokan_chrm'@'localhost'` で全画面500/白画面になる。config.php の db_host は必ず mysql8055.xserver.jp。
2. **デプロイはファイルマネージャの「アップロード」で行う**。Web編集画面（新規ファイル→編集）にスクリプトで流し込んだ内容は**保存されない**（空ファイルになり、PHPが何も出力せず白画面になる）。アップロード、または実キーボード入力なら保存される。→ 今後はFTP/SFTP もしくは「アップロード」一択。
3. **MySQL 5.7 のため ADD COLUMN IF NOT EXISTS 非対応**。マイグレーションは**1回だけ**実行する（再実行は Duplicate column エラー）。
4. **白画面 = PHP致命的エラー（本番は display_errors OFF）**。原因切り分けは、`ini_set('display_errors',1)` を入れた一時的な診断PHPをアップして実際のエラー文を見るのが速い（使用後削除）。

## 4. 現在のDBスキーマ（5テーブル）

| テーブル | 内容 |
| :-- | :-- |
| tenants | テナント（外販下地。現在 id=1「智翔館グループ 個別指導部門」） |
| users | ログインアカウント。email / password_hash / role（admin/staff/teacher）/ **staff_id**（teacherのマイページ用紐付け） |
| staff | 講師。name departments school color_rank（WHITE→GREEN→BLUE→YELLOW→RED）target_rank mentor ほか + フェーズ1拡張列 |
| training_items | 研修マスター（部門 × 対象カラーごとの研修項目）。sort_order（orderは予約語回避） |
| training_progress | 研修進捗（講師 × 研修項目）。status（未着手/受講済/合格/不合格/対象外） |

全テーブルに tenant_id あり（将来の外販を見据えたマルチテナント下地）。

### staff のフェーズ1拡張列

candidate_id / employment_type / hire_date / color_certified_date / recruiting_media / referrer / created_at

## 5. 適用済みマイグレーション

1. `schema.sql` — PoC初期（tenants / users / staff 作成 + サンプル講師8名）
2. `001_phase1-2.sql` — フェーズ1〜2（本日適用）:
   - users.staff_id 追加
   - staff に7列追加
   - training_items / training_progress 作成

サンプル講師8名（吉田りあ 他）は**動作確認用**。本番運用前に実データへ差し替える（フェーズ6）。

## 6. これからの作業（Claude Code 側）

### フェーズ3: 研修の自己申告 + 承認フロー（スキーマ未適用）

training_progress に下記を追加して実装予定:

- declared_by INT NULL / declared_at DATETIME NULL
- approved_by INT NULL / approved_at DATETIME NULL
- status に「申告中」「差戻し」を追加

### 実装したい画面・機能（優先度メモ）

- **マイページ**（teacher が users.staff_id 経由で自分の情報・研修進捗を見る）
- **研修進捗UI**（training_items / training_progress の入力・一覧）
- **ユーザー管理**（アカウント作成・role設定。ここで管理者パスワード変更も）
- **講師の編集/登録**（staff のCRUD）
- サンプル講師 → 実データへの移行

### GAS版の後付けサービス（仕様書に未反映）

GAS版には MeetingService / LessonService / QuestionService が後付けされている。仕様書に載っていないので、移植時は**冨松さんから現状スナップショットをもらう**こと。

## 7. セキュリティ TODO

- **管理者パスワードを admin1234 から変更**（固定値のまま放置しない）
- setup.php / _test.php をサーバーから削除（完了）
- config.php は**Git管理外**を維持（.gitignore 済み。config.php.example のみ追跡）
- 診断・セットアップ用PHPは使用後必ず削除

## 8. Git運用メモ

- .gitignore: config.php 除外、config.php.example は追跡
- migrations/ に SQL を置く運用（001_phase1-2.sql など連番）
- 本番反映はリポジトリ → ファイルマネージャ「アップロード」（または FTP/SFTP）
