-- ============================================================
-- Color HRM マイグレーション 019
-- カラー変更の「適用日」履歴と、給与明細のカラー別内訳。
--   staff_color_history … 講師のカラーを適用日付きで記録。給与計算は各勤務日の
--     カラー（履歴）で時給を判定する（月の途中で切替＝その日から新単価）。
--   payslips.breakdown … 発行時点のカラー別内訳（JSON）を保存し、明細PDFに表示。
--   ※ 未実施でも動作する（履歴が無ければ現カラーで従来どおり計算）。
-- phpMyAdmin の SQL タブで1回実行（017・018 を先に実行済みのこと）。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staff_color_history (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT NOT NULL DEFAULT 1,
  staff_id       INT NOT NULL,
  color          VARCHAR(20) NOT NULL,
  effective_date DATE NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_date (tenant_id, staff_id, effective_date),
  KEY idx_staff (tenant_id, staff_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 既存講師の現カラーをベースラインとして投入（履歴が無い講師のみ）。
INSERT INTO staff_color_history (tenant_id, staff_id, color, effective_date)
SELECT 1, s.id, s.color_rank, COALESCE(s.color_certified_date, '2000-01-01')
FROM staff s
WHERE s.color_rank <> ''
  AND NOT EXISTS (SELECT 1 FROM staff_color_history h WHERE h.staff_id = s.id);

-- 給与明細にカラー別内訳（JSON）列を追加。
ALTER TABLE payslips ADD COLUMN breakdown TEXT NULL;
-- ============================================================
