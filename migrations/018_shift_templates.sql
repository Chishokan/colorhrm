-- ============================================================
-- Color HRM マイグレーション 018
-- シフト可能登録のテンプレート（講師ごと）。
--   講師が自分の「よく使う時間帯」を登録し、月間表へ一括適用できる。
--   ※ 未実施でもシフト可能登録は従来どおり動作する（テンプレ欄が出ないだけ）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_templates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  staff_id   INT NOT NULL,
  label      VARCHAR(50)  NOT NULL DEFAULT '',
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_staff (tenant_id, staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
