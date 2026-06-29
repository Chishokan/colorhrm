-- ============================================================
-- Color HRM マイグレーション 021
-- 退勤チェックリスト（教室別）と退勤報告。
--   clockout_checklist … 退勤打刻時に表示する教室別チェック項目。
--   clockout_reports   … チェック完了して退勤打刻した記録（報告）。
--   ※ 未実施でも打刻は従来どおり可能（チェックリストが出ないだけ）。
-- phpMyAdmin の SQL タブで1回実行。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS clockout_checklist (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  classroom  VARCHAR(50)  NOT NULL,
  item       VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_room (tenant_id, classroom, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clockout_reports (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL DEFAULT 1,
  staff_id   INT NOT NULL,
  work_date  DATE NOT NULL,
  classroom  VARCHAR(50) NOT NULL DEFAULT '',
  items      TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_date  (tenant_id, work_date),
  KEY idx_staff (tenant_id, staff_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初期データ：佐々・日野（教室名は既存に合わせて RED佐々／RED日野。違う場合は画面で編集可）。
-- 既に項目があれば投入しない（再実行しても重複しない）。
INSERT INTO clockout_checklist (tenant_id, classroom, item, sort_order)
SELECT 1, v.classroom, v.item, v.sort_order FROM (
  SELECT 'RED佐々' AS classroom, '教室内エアコン２台の電源オフ' AS item, 1 AS sort_order
  UNION ALL SELECT 'RED佐々', '輪コミスペースエアコン１台の電源オフ', 2
  UNION ALL SELECT 'RED佐々', 'トイレ電気男女の電源オフ', 3
  UNION ALL SELECT 'RED日野', 'エアコンの電源オフ', 1
  UNION ALL SELECT 'RED日野', 'トイレ電気男女の電源オフ', 2
) v
WHERE NOT EXISTS (SELECT 1 FROM clockout_checklist c WHERE c.classroom IN ('RED佐々','RED日野'));
-- ============================================================
