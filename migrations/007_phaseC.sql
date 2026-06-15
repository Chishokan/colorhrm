-- ============================================================
-- Color HRM マイグレーション 007
-- フェーズC：研修コンテンツ(Lessons) ＋ テスト証跡提出 ＋ 研修項目の種別/モジュール
-- phpMyAdmin の SQL タブで1回実行。
--   ※ training_items / training_progress への ADD COLUMN は004で未適用だった分。
--     もし「Duplicate column」が出た列は適用済みなので、その行を外して再実行してください。
-- ============================================================
SET NAMES utf8mb4;

-- 研修項目：種別（研究/テスト）と 研修動画モジュールキー
ALTER TABLE training_items
  ADD COLUMN type       VARCHAR(20) DEFAULT '' AFTER is_required,
  ADD COLUMN module_key VARCHAR(20) DEFAULT '' AFTER type;

-- 研修進捗：テスト証跡ファイル・提出者
ALTER TABLE training_progress
  ADD COLUMN evidence_file VARCHAR(255) DEFAULT '' AFTER memo,
  ADD COLUMN submitted_by  VARCHAR(100) DEFAULT '' AFTER evidence_file;

-- 研修コンテンツ（動画/資料）。module_key で training_items と疎結合。
CREATE TABLE IF NOT EXISTS lessons (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT NOT NULL DEFAULT 1,
  module_key     VARCHAR(20)  NOT NULL DEFAULT '',
  sort_order     INT NOT NULL DEFAULT 0,
  title          VARCHAR(200) NOT NULL DEFAULT '',
  material       VARCHAR(500) DEFAULT '',   -- 資料URL/名
  video_url      VARCHAR(500) DEFAULT '',
  video_duration VARCHAR(50)  DEFAULT '',
  note           VARCHAR(500) DEFAULT '',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
