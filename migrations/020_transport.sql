-- ============================================================
-- Color HRM マイグレーション 020
-- 交通費の区分・実費・送迎(交通費なし)日。
--   staff.transport_mode  … 'car'(車・バイク=現行ルール) / 'transit'(公共交通) / 'none'(徒歩・定期圏内)
--   staff.transport_daily … 公共交通の1日あたり実費（円）
--   shift_days.no_transport … その日は交通費なし（教室長が送迎した日 等）
--   交通費の計算：
--     none    … 0円
--     transit … 1日額 × 対象日数（送迎日を除く）。月勤務8日以下は半額・9日以上で全額。
--     car     … 現行ルール（対象日数 ≤5日:日数×200／超過:切上げ(日数/5)×1000）
--   ※ 未実施でも動作する（区分は car 既定、送迎フラグなしで従来どおり）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE staff ADD COLUMN transport_mode  VARCHAR(10) NOT NULL DEFAULT 'car';
ALTER TABLE staff ADD COLUMN transport_daily INT NOT NULL DEFAULT 0;
ALTER TABLE shift_days ADD COLUMN no_transport TINYINT(1) NOT NULL DEFAULT 0;
-- ============================================================
