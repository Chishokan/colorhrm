# migrations

Xサーバー版 Color HRM のDBマイグレーション置き場です。
番号順に、phpMyAdmin の「SQL」タブへ貼り付けて **1回だけ** 実行してください。

| ファイル | 内容 |
|---|---|
| `001_phase1-2.sql` | フェーズ1（権限・マイページ）＋フェーズ2（研修）。`users.staff_id` 追加、`staff` の16列化、`training_items` / `training_progress` 作成。**本番適用済み（2026-06-12）**。 |

## 実行順
1. 既存の `schema.sql`（tenants / users / staff）を流す
2. `001_phase1-2.sql` を流す

## 注意
- MySQL 5.7 は `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` 非対応のため、
  `001_phase1-2.sql` の `ALTER` 部分は **一度だけ** 実行する想定です。
  再実行すると `Duplicate column` エラーが出ますが、その行はスキップ済みとして無視して構いません。
- `CREATE TABLE IF NOT EXISTS` 部分は再実行しても安全です。
