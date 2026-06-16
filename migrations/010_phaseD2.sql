-- ============================================================
-- Color HRM マイグレーション 010
-- フェーズD-2：シフト申請→承認→確定。
--   shift_applications … 講師の「シフト可能（申請）」。status: 申請中/確定/却下
--   shift_days         … 確定シフト（日次）。授業分は管理者入力、運営分=総稼働分-授業分
--   ※ pay_rates 等と同じ MySQL（ColorHRM と共有DB）に作成する。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_applications (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL DEFAULT 1,
  staff_id    INT NOT NULL,
  work_date   DATE NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  note        VARCHAR(255) NOT NULL DEFAULT '',
  status      VARCHAR(20)  NOT NULL DEFAULT '申請中',  -- 申請中 / 確定 / 却下
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_staff_date  (staff_id, work_date),
  KEY idx_tenant_date (tenant_id, work_date),
  KEY idx_status      (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shift_days (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT NOT NULL DEFAULT 1,
  staff_id       INT NOT NULL,
  work_date      DATE NOT NULL,
  start_time     TIME NOT NULL,
  end_time       TIME NOT NULL,
  class_minutes  INT NOT NULL DEFAULT 0,   -- 授業・面談の分（管理者入力）
  note           VARCHAR(255) NOT NULL DEFAULT '',
  application_id INT NULL,                 -- 元になった申請（任意）
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_staff_date  (staff_id, work_date),
  KEY idx_tenant_date (tenant_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
