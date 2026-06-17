-- ============================================================
-- Color HRM マイグレーション 011
-- フェーズD-3+：給与明細（payslips）。発行時点の金額をスナップショット保存する。
--   ＝確定シフトを後から編集しても、発行済み明細の金額は変わらない。
--   PDF は payslip_pdf.php がこの行から生成（バイナリは保存しない）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payslips (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL DEFAULT 1,
  staff_id    INT NOT NULL,
  month       VARCHAR(7)  NOT NULL,          -- YYYY-MM
  days        INT NOT NULL DEFAULT 0,
  class_min   INT NOT NULL DEFAULT 0,
  ops_min     INT NOT NULL DEFAULT 0,
  class_rate  INT NOT NULL DEFAULT 0,
  ops_rate    INT NOT NULL DEFAULT 0,
  class_pay   INT NOT NULL DEFAULT 0,
  ops_pay     INT NOT NULL DEFAULT 0,
  transport   INT NOT NULL DEFAULT 0,
  total       INT NOT NULL DEFAULT 0,
  issued_at   DATETIME NOT NULL,
  issued_by   INT NULL,
  notified_at DATETIME NULL,
  UNIQUE KEY uq_staff_month (tenant_id, staff_id, month),
  KEY idx_month (tenant_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
