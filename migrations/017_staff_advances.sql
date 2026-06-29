-- ============================================================
-- Color HRM マイグレーション 017
-- 立替金（advance）：給与計算で講師×月ごとに手入力する立替金。
--   staff_advances … 編集用（月ごとに上書き）。
--   payslips.advance … 発行時点のスナップショット列。
--   ※ 未実施でも画面は動作する（立替金は0として扱い、保存時に案内を表示）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staff_advances (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  staff_id   INT NOT NULL,
  month      VARCHAR(7) NOT NULL,            -- YYYY-MM
  amount     INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_month (tenant_id, staff_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 給与明細スナップショットに立替金列を追加（交通費の次）。
ALTER TABLE payslips ADD COLUMN advance INT NOT NULL DEFAULT 0 AFTER transport;
-- ============================================================
