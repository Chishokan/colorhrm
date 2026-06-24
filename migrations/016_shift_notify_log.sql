-- ============================================================
-- Color HRM マイグレーション 016
-- シフト確定待ち（申請中シフト）の日次メール通知 重複防止ログ。
--   1日1回だけ通知するためのガード。notify_date+kind を UNIQUE にして
--   INSERT IGNORE で「その日もう送ったか」を判定する。
--   ※ このテーブルが無くても画面・通知以外の機能は動作する（通知のみ無効）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_notify_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL DEFAULT 1,
  notify_date DATE NOT NULL,
  kind        VARCHAR(40) NOT NULL DEFAULT 'pending_shift',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_date_kind (notify_date, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
