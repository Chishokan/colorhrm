-- ============================================================
-- Color HRM マイグレーション 008
-- フェーズE：細分化した権限フラグ（GAS版 viewRecruitment / viewStaffList）
--   - view_recruitment : staff に採用（応募者/ダッシュボード）閲覧を許可
--   - view_staff_list  : teacher に講師一覧の閲覧を許可
-- phpMyAdmin で1回実行。Duplicate column が出たら適用済みとして無視。
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN view_recruitment TINYINT NOT NULL DEFAULT 0 AFTER role,
  ADD COLUMN view_staff_list  TINYINT NOT NULL DEFAULT 0 AFTER view_recruitment;

-- 既存の staff ロールは、これまで採用を見られていたので既定で許可に寄せる（運用維持）
UPDATE users SET view_recruitment = 1 WHERE role = 'staff';
-- ============================================================
