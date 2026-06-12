# app

Xサーバー版 Color HRM のデプロイ対象ファイル一式（PoC 本体）です。
本番反映時は **この `app/` 配下のファイルを**、Xサーバーの
`/chishokan.co.jp/public_html/colorhrm/` へアップロードします
（ファイルマネージャの「アップロード」または FTP/SFTP）。

| ファイル | 役割 |
|---|---|
| `db.php` | DB接続（PDO）。`config.php` を require |
| `auth.php` | 独自認証（セッション）。`current_user` / `require_login` / `login_attempt` / `logout` / `h()` |
| `index.php` | 講師一覧（要ログイン） |
| `login.php` | ログイン画面 |
| `logout.php` | ログアウト |
| `config.php.example` | DB接続情報のテンプレート。`cp config.php.example config.php` で実値版を作る |

## アップロードの注意

- `config.php`（実値）は **Git管理外**。サーバー上にのみ置く。
- `config.php.example` は **アップロード不要**（テンプレートのため）。
- `setup.php`（初期管理者作成）/ `_test.php`（DB診断）は、生成して使ったら **必ず削除**する。
- DBホストは `localhost` 不可。`mysql8055.xserver.jp` のように指定する（`config.php.example` 参照）。

詳細は `../docs/HANDOFF.md` を参照。
