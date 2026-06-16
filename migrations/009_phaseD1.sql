-- ============================================================
-- Color HRM マイグレーション 009
-- フェーズD-1：時給表（WageRates）。カラー×部門ごとの授業時給/運営時給。
--   GAS版 WageRates（智翔館/RED/ネクスタ/東進/プリンス × 5カラー）の実値を投入。
--   ※ pay_rates が空のときだけ投入（再実行安全）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pay_rates (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL DEFAULT 1,
  color       VARCHAR(10)  NOT NULL,
  department  VARCHAR(50)  NOT NULL,
  class_rate  INT NOT NULL DEFAULT 1031,   -- 授業・面談時給
  ops_rate    INT NOT NULL DEFAULT 1031,   -- 運営（その他）時給
  KEY idx_tenant (tenant_id),
  UNIQUE KEY uq_color_dept (tenant_id, color, department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pay_rates (tenant_id, color, department, class_rate, ops_rate)
SELECT * FROM (
  SELECT 1 AS tenant_id, 'WHITE' AS color, '智翔館' AS department, 1031 AS class_rate, 1031 AS ops_rate
  UNION ALL SELECT 1,'GREEN','智翔館',1050,1031
  UNION ALL SELECT 1,'BLUE','智翔館',1100,1031
  UNION ALL SELECT 1,'YELLOW','智翔館',1500,1031
  UNION ALL SELECT 1,'RED','智翔館',2000,1031
  UNION ALL SELECT 1,'WHITE','RED',1031,1031
  UNION ALL SELECT 1,'GREEN','RED',1050,1031
  UNION ALL SELECT 1,'BLUE','RED',1100,1031
  UNION ALL SELECT 1,'YELLOW','RED',1200,1031
  UNION ALL SELECT 1,'RED','RED',1500,1031
  UNION ALL SELECT 1,'WHITE','ネクスタ',1031,1031
  UNION ALL SELECT 1,'GREEN','ネクスタ',1050,1031
  UNION ALL SELECT 1,'BLUE','ネクスタ',1100,1031
  UNION ALL SELECT 1,'YELLOW','ネクスタ',1200,1031
  UNION ALL SELECT 1,'RED','ネクスタ',1500,1031
  UNION ALL SELECT 1,'WHITE','東進',1031,1031
  UNION ALL SELECT 1,'GREEN','東進',1050,1031
  UNION ALL SELECT 1,'BLUE','東進',1100,1031
  UNION ALL SELECT 1,'YELLOW','東進',1200,1031
  UNION ALL SELECT 1,'RED','東進',1500,1031
  UNION ALL SELECT 1,'WHITE','プリンス',1031,1031
  UNION ALL SELECT 1,'GREEN','プリンス',1050,1031
  UNION ALL SELECT 1,'BLUE','プリンス',1100,1031
  UNION ALL SELECT 1,'YELLOW','プリンス',1200,1031
  UNION ALL SELECT 1,'RED','プリンス',1500,1031
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM pay_rates WHERE tenant_id = 1);
-- ============================================================
