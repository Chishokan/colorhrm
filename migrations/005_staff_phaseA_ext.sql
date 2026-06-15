-- ============================================================
-- Color HRM マイグレーション 005（任意）
-- フェーズA 強化用：staff に退職日・給与フラグ・顔写真の列を追加。
--   ※ これらが無くてもフェーズA（講師詳細・昇格・退職）は動作します
--     （アプリが列の有無を判定して自動で対応）。列を足すと、退職日の記録・
--     給与対象フラグ・顔写真が使えるようになります。
--   ※ 004 が email 既存で失敗していたため、これらは未追加の想定。
--     もし「Duplicate column」が出た列は適用済みなので無視してください。
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE staff
  ADD COLUMN resign_date DATE NULL    AFTER is_active,
  ADD COLUMN use_payroll TINYINT NOT NULL DEFAULT 1 AFTER resign_date,
  ADD COLUMN photo_file  VARCHAR(255) DEFAULT '' AFTER email;
-- ============================================================
