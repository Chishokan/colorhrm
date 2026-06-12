# デプロイ手順（Xサーバー）

Color HRM（Xサーバー版）を本番 `https://chishokan.co.jp/colorhrm/` に反映する手順です。
初回と2回目以降（更新）に分けて記載します。

---

## 0. 前提・環境情報

| 項目 | 値 |
| :-- | :-- |
| 公開URL | https://chishokan.co.jp/colorhrm/ |
| 配置パス | `/chishokan.co.jp/public_html/colorhrm/` |
| MySQLホスト | **mysql8055.xserver.jp**（★`localhost` 不可） |
| DB名 | chishokan_colorhrm |
| DBユーザー | chishokan_chrm |

> ⚠️ ハマりどころ（必ず守る）
> 1. **DBホストは `mysql8055.xserver.jp`**。`localhost` にすると `Access denied ...@'localhost'` で全画面500/白画面。
> 2. **デプロイは必ず「ファイルのアップロード」**（FTP/SFTP もしくはファイルマネージャの［アップロード］）。
>    Web編集画面で新規ファイルを作って中身を貼り付ける方法は**保存されず空ファイル**になり、白画面の原因になる。
> 3. **MySQL 5.7 は `ADD COLUMN IF NOT EXISTS` 非対応**。マイグレーションは**1回だけ**実行（再実行は Duplicate column）。
> 4. **白画面＝PHP致命的エラー**（本番は `display_errors` OFF）。原因切り分けは下記「トラブルシュート」参照。

---

## 1. アップロードするファイル

`app/` 配下の **PHP 一式**を `colorhrm/` へ置きます。

**アップロードする**
```
db.php  auth.php  helpers.php
index.php  mypage.php  login.php  logout.php
training.php  training_master.php  users.php
config.php   ← サーバー上にのみ作る実値版（下記2参照）
```

**アップロードしない**
```
config.php.example   （テンプレートのため不要）
README.md            （説明用）
migrations/ 配下      （SQLは phpMyAdmin で実行。アップロード不要）
.gitignore / docs/    （リポジトリ管理用）
```

---

## 2. config.php を作る（実DB接続情報・サーバー上のみ）

`config.php` は Git 管理外。サーバー上で `config.php.example` を元に実値版を作ります。

`app/config.php`（サーバーの `colorhrm/config.php`）の中身:
```php
<?php
return [
  'db_host'    => 'mysql8055.xserver.jp', // ★localhost不可
  'db_name'    => 'chishokan_colorhrm',
  'db_user'    => 'chishokan_chrm',
  'db_pass'    => '（XサーバーのMySQL設定で設定したパスワード）',
  'db_charset' => 'utf8mb4',
];
```
> ローカルで作って FTP で上げてもよいし、ファイルマネージャの［アップロード］で上げてもOK。
> （Web編集で新規作成→貼り付けは保存されないので不可）

---

## 3. 初回デプロイ手順

1. **DB作成**：サーバーパネル →［MySQL設定］で DB（chishokan_colorhrm）と DBユーザー（chishokan_chrm）を作成し、ユーザーにDBへのアクセス権を付与。
2. **スキーマ適用**：phpMyAdmin で対象DBを選び［SQL］タブに以下を順に貼り付けて実行。
   - `schema.sql`（PoC初期：tenants / users / staff）※適用済みならスキップ
   - `migrations/001_phase1-2.sql`（フェーズ1〜2）※適用済みならスキップ
   - `migrations/002_phase3.sql`（フェーズ3：申告/承認列）← **今回の新規**
3. **ファイルアップロード**：`app/` 配下のPHP一式を `colorhrm/` へアップロード（上記1）。
4. **config.php 配置**：実値版を `colorhrm/config.php` に置く（上記2）。
5. **初期管理者作成**：管理者ユーザーが未作成なら作る（`setup.php` を使う場合は **使用後すぐ削除**）。
   既に `admin@chishokan.local` がある場合は不要。
6. **動作確認**：`https://chishokan.co.jp/colorhrm/login.php` → ログイン → 講師一覧。
   admin なら ナビに「研修管理 / 研修マスター / ユーザー管理」が出る。

---

## 4. 2回目以降（更新）の手順

コードだけ変えた場合（スキーマ変更なし）:
1. 変更した `app/` 配下のPHPを `colorhrm/` へ**上書きアップロード**。
2. ブラウザで対象画面を再読み込みして確認。

スキーマ変更を伴う場合:
1. 先に phpMyAdmin で**新しい番号のマイグレーション**（例 `003_*.sql`）を1回だけ実行。
2. その後にPHPをアップロード（順序を守る。列が無い状態で新コードが動くとエラーになるため）。

> `config.php` は**上書きしない**（実値が消えるため）。常にアップロード対象から除外。

---

## 5. 今回（フェーズ3）の更新でやること

1. phpMyAdmin で **`migrations/002_phase3.sql` を1回実行**。
2. `colorhrm/` へ以下をアップロード：
   - 新規：`helpers.php` `mypage.php` `training.php` `training_master.php` `users.php`
   - 更新：`index.php`（共通ナビ対応）
3. admin でログイン →「研修マスター」で項目登録 →「ユーザー管理」で各講師に `staff_id` を紐付け →
   teacher でログインして「マイページ」から申告 → admin で承認、まで通しで確認。

---

## 6. トラブルシュート

- **白画面（何も出ない）**：PHP致命的エラー。多くは `config.php` の `db_host` が `localhost` のまま、
  または `config.php` が空（Web編集で作って保存されていない）。アップロードで置き直す。
- **`Access denied for user 'chishokan_chrm'@'localhost'`**：`db_host` を `mysql8055.xserver.jp` に。
- **`Duplicate column ...`**：マイグレーションの再実行。その行は適用済みなので無視してよい。
- **エラー内容を見たい**：一時的な診断PHP（`ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);` を先頭に置いたファイル）をアップして実エラーを確認。**確認後は必ず削除**。

---

## 7. セキュリティ・チェックリスト

- [ ] `config.php` は Git に上げない（`.gitignore` 済み）
- [ ] `setup.php` / `_test.php` は使用後にサーバーから**削除**
- [ ] 初期管理者パスワード `admin1234` を「ユーザー管理」画面から**変更**
- [ ] 診断用に上げた一時PHPは**削除**

---

## 8. 自動デプロイ（GitHub Actions）

`main` に push すると、`app/` 配下が Xサーバーへ FTPS で自動アップロードされます
（ワークフロー: `.github/workflows/deploy.yml`）。手動アップロードの代替です。

### 8.1 初期設定（1回だけ）

GitHub リポジトリの **Settings → Secrets and variables → Actions** で登録:

| 種別 | 名前 | 値 |
| :-- | :-- | :-- |
| Secret | `FTP_SERVER` | FTPホスト名（例: `sv8550.xserver.jp`。サーバーパネル［FTPアカウント設定］のホスト） |
| Secret | `FTP_USERNAME` | FTPユーザー名 |
| Secret | `FTP_PASSWORD` | FTPパスワード |
| Variable（任意） | `FTP_SERVER_DIR` | 配置先。既定 `/chishokan.co.jp/public_html/colorhrm/`。**末尾スラッシュ必須** |

> FTPアカウントは サーバーパネル →［FTPアカウント設定］で作成・確認。
> `FTP_SERVER_DIR` はそのFTPアカウントのホーム基準のパス。ホームが既に `public_html` の場合は
> `/colorhrm/` のように短くなることがあるので、初回は **dry-run** で確認するのが安全。

### 8.2 使い方

- **自動**：`main` に `app/` の変更を含む push（PRのマージ含む）をすると走る。
- **手動**：Actions タブ → 「Deploy to Xserver」→ Run workflow。`Dry run` で差分のみ確認できる。
  - ⚠️ **この手動ボタンは、ワークフローが `main` に乗ってから（＝初回マージ後）しか表示されません**
    （GitHubの仕様）。**初回デプロイはマージ時に本番アップロードが走ります**（Dry run は使えません）。

### 8.2.1 初回デプロイの安全な順序

1. `FTP_SERVER_DIR` の値を確定（**ここだけ要注意**）。
   FTPクライアントで既存の `colorhrm/` を開き、表示されるパスを確認するのが確実。
   FTPアカウントのホームが web 公開領域なら `/colorhrm/` のように短くなる場合がある。
2. Secrets（`FTP_SERVER` / `FTP_USERNAME` / `FTP_PASSWORD`）と必要なら Variable `FTP_SERVER_DIR` を登録。
3. PR を `main` にマージ → 自動デプロイ。Actions のログで上がったファイルを確認。
4. 2回目以降は、手動 Run workflow の `Dry run` で事前確認できるようになる。

> 初回の配置先パスが不安な場合は、**初回だけ手動FTPアップロード**し、以降を自動デプロイに任せる手もある。

### 8.3 仕様・注意

- 対象は `app/` の中身のみ。`config.php`（実値）/ `config.php.example` / `README.md` は**除外**（上書き・削除されない）。
- **差分アップロード**：サーバー上の `.ftp-deploy-sync-state.json` で前回との差分のみ転送。
- **DBマイグレーションは対象外**。スキーマ変更がある時は、先に phpMyAdmin で該当SQLを実行してから push すること
  （列が無い状態で新コードが動くとエラーになるため）。
- FTPS で接続できない場合は、ワークフローの `protocol: ftps` を `ftp` に変更。
- 開発ブランチ（`claude/...`）では走らない。本番反映は `main` へのマージが起点。

