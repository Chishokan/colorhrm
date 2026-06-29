-- ============================================================
-- Color HRM マイグレーション 022
-- 日報（報告フォーム）。Googleフォーム「報告フォーム」の代替。
--   講師が1日の業務終わりに提出。退勤チェック（clockout_*）とは別機能。
--   ※ 未実施でも他機能は動作する（報告ページのみ無効）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS daily_reports (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT NOT NULL DEFAULT 1,
  staff_id         INT NOT NULL,
  report_type      VARCHAR(10) NOT NULL DEFAULT '通常',   -- 通常 / 講習
  work_date        DATE NOT NULL,
  new_trial        TEXT NULL,    -- 新規体験生の状況共有
  irregular        TEXT NULL,    -- イレギュラー報告
  no_improve       TEXT NULL,    -- 指導したが改善が見られない生徒
  parent_share     TEXT NULL,    -- 保護者共有したいこと
  break_time       VARCHAR(100) NULL,  -- 休憩時間（例 12:00~13:00 / なし）
  shift_over       VARCHAR(20)  NULL,  -- シフト超過 / シフト超過なし
  shift_over_detail TEXT NULL,         -- シフト超過の詳細
  work_end         VARCHAR(20)  NULL,  -- 業務が終了した時間
  work_content     TEXT NULL,          -- 業務内容
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_date  (tenant_id, work_date),
  KEY idx_staff (tenant_id, staff_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
