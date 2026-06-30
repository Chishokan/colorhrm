-- ============================================================
-- Color HRM マイグレーション 023
-- シフト確定（シフト申請・確定）で使う時間テンプレート（スタッフ個人ごと）。
--   よく使う確定時間（例 17:00〜21:30）を登録し、各行で選んで入力できる。
--   ※ 未実施でも確定作業は従来どおり（テンプレ欄が出ないだけ）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS confirm_templates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  user_id    INT NOT NULL,
  label      VARCHAR(50) NOT NULL DEFAULT '',
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
