-- ============================================================
-- Color HRM マイグレーション 012
-- 配属教室（校舎）の概念。教室マスター＋講師の配属教室＋staffの担当教室。
--   教室＝既存 staff.school（校舎）と同じ概念。複数設定できるようにする。
--   ※ ADD COLUMN で「Duplicate column」が出たら、その行を外して再実行。
-- phpMyAdmin の SQL タブで実行。
-- ============================================================
SET NAMES utf8mb4;

-- 教室マスター
CREATE TABLE IF NOT EXISTS classrooms (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  name       VARCHAR(50) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 講師の配属教室（カンマ区切り・複数）
ALTER TABLE staff ADD COLUMN classrooms VARCHAR(255) NOT NULL DEFAULT '' AFTER school;

-- staff（運営スタッフ）の担当教室（カンマ区切り・複数）
ALTER TABLE users ADD COLUMN classrooms VARCHAR(255) NOT NULL DEFAULT '' AFTER staff_id;

-- 既存の校舎名を教室マスターへ取り込み
INSERT IGNORE INTO classrooms (tenant_id, name)
  SELECT DISTINCT 1, school FROM staff WHERE school IS NOT NULL AND school <> '';

-- 講師の配属教室を、未設定なら既存の校舎で初期化
UPDATE staff SET classrooms = school WHERE classrooms = '' AND school IS NOT NULL AND school <> '';
-- ============================================================
