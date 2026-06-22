-- ============================================================
-- Color HRM マイグレーション 014
-- 打刻（attendance）。確定シフトがある日に、出勤/退勤の時刻と教室を記録。
--   ※ 給与計算はシフト時間で行い、打刻時刻はあくまで遅刻/早退/欠勤の判定・表示用。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  staff_id   INT NOT NULL,
  work_date  DATE NOT NULL,
  clock_in   TIME NULL,
  in_room    VARCHAR(50) NOT NULL DEFAULT '',
  clock_out  TIME NULL,
  out_room   VARCHAR(50) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_date (tenant_id, staff_id, work_date),
  KEY idx_date (tenant_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
