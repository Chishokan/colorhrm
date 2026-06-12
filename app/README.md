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
| `index.php` | 講師一覧（admin/staff、要ログイン） |
| `mypage.php` | マイページ（teacher が自分の研修進捗を見る・自己申告） |
| `training.php` | 研修管理（admin/staff）。承認インボックス＋講師ごとの進捗編集 |
| `training_master.php` | 研修マスター管理（admin）。研修項目（部門×対象カラー）のCRUD |
| `users.php` | ユーザー管理（admin）。作成・ロール/講師紐付け・パスワード変更 |
| `login.php` | ログイン画面 |
| `logout.php` | ログアウト |
| `config.php.example` | DB接続情報のテンプレート。`cp config.php.example config.php` で実値版を作る |

> フェーズ3（自己申告＋承認）は `helpers.php` / `mypage.php` / `training.php` /
> `training_master.php` / `users.php` で実装。
> 動作には `migrations/002_phase3.sql` の適用が必要です（`training_progress` の申告/承認列）。

## アップロードの注意

- `config.php`（実値）は **Git管理外**。サーバー上にのみ置く。
- `config.php.example` は **アップロード不要**（テンプレートのため）。
- `setup.php`（初期管理者作成）/ `_test.php`（DB診断）は、生成して使ったら **必ず削除**する。
- DBホストは `localhost` 不可。`mysql8055.xserver.jp` のように指定する（`config.php.example` 参照）。

詳細は `../docs/HANDOFF.md` を参照。
