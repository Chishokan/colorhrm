# payroll（給与・シフトアプリ）

ColorHRM とは**別URL・同一DB**で動く給与・シフト管理アプリです。
本番配置先は Xサーバーの `/colorhrm.co.jp/public_html/colorhrm-pay/`、
URL は `https://chishokan.co.jp/colorhrm-pay/`。

- **DB**：ColorHRM と**同じ MySQL**を共有（`staff` / `users` / `pay_rates` を直接参照）。
- **ログイン**：同一ドメインのため `PHPSESSID` セッションが共有され、ColorHRM と**共通ログイン**。
- `config.php` は ColorHRM と**同じDB接続値**を入れる（Git管理外。`cp config.php.example config.php`）。

| ファイル | 役割 |
|---|---|
| `db.php` | DB接続（PDO）。ColorHRM と同じDB |
| `auth.php` | 独自認証（セッション共有）。`current_user` / `login_attempt` / `h` |
| `lib.php` | 給与アプリ用レイアウト・ナビ・CSRF・カラー/時給ヘルパー |
| `index.php` | ダッシュボード（admin/staff）。講師別の時給一覧＋各機能入口 |
| `shifts.php` | シフト申請（teacher）。自分のシフト可能を月ごとに申請/取消・確定状況確認 |
| `shifts_admin.php` | シフト管理（admin/staff）。申請の確定（授業分入力）/却下・確定シフトの追加/編集/削除 |
| `rates.php` | 時給表（WageRates）管理（admin）。カラー×部門の授業時給/運営時給 |
| `login.php` / `logout.php` | ログイン / ログアウト |
| `config.php.example` | DB接続テンプレート |

## 段階導入

- **D-1（実装済）**：時給表（`pay_rates`）の管理＋講師別時給算出。
  - DB：`migrations/009_phaseD1.sql`（`pay_rates` 25件）。ColorHRM 側で適用済みなら追加不要。
- **D-2（実装済）**：シフト申請→承認→確定（`shift_applications` / `shift_days`）。
  - DB：`migrations/010_phaseD2.sql`（`shift_applications` / `shift_days`）。
  - 稼働分＝開始〜終了の差。授業分は確定時に管理者が入力、運営分＝稼働分−授業分。
- **D-3（予定）**：給与計算＋振込一覧（時給×分 ＋ 交通費）。

## デプロイ

`/colorhrm-pay/` 用の **専用FTPアカウント**（ホーム＝colorhrm-pay）を作成し、
GitHub Secrets（`FTP_PAY_SERVER` / `FTP_PAY_USERNAME` / `FTP_PAY_PASSWORD`）を設定すると、
`.github/workflows/deploy-payroll.yml` で `payroll/` 配下が自動デプロイされます。
詳細は `../docs/PAYROLL_INTEGRATION.md`。
