-- ============================================================
-- Color HRM マイグレーション 004
-- GAS版 ColorHRM_DB 取り込み用：既存テーブルへ列を追加
--   - source_uid : GAS側のUUIDを保持（再取り込み・トレース用）
--   - GAS版にあってXサーバー版に無かった列を補完
-- ※ MySQL は ADD COLUMN IF NOT EXISTS 非対応。1回だけ実行（再実行時の
--   「Duplicate column」は適用済みとして無視可）。
-- ============================================================
SET NAMES utf8mb4;

-- candidates：GAS UUID 保持
ALTER TABLE candidates
  ADD COLUMN source_uid VARCHAR(40) DEFAULT '' AFTER id,
  ADD KEY idx_cand_source (source_uid);

-- staff：GAS UUID ＋ 追加列（email / 目標期限 / 退職日 / 給与対象 / 顔写真）
ALTER TABLE staff
  ADD COLUMN source_uid  VARCHAR(40)  DEFAULT '' AFTER id,
  ADD COLUMN email       VARCHAR(150) DEFAULT '' AFTER referrer,
  ADD COLUMN photo_file  VARCHAR(255) DEFAULT '' AFTER email,
  ADD COLUMN target_date DATE NULL    AFTER target_rank,
  ADD COLUMN resign_date DATE NULL    AFTER is_active,
  ADD COLUMN use_payroll TINYINT NOT NULL DEFAULT 1 AFTER resign_date,
  ADD KEY idx_staff_source (source_uid);

-- training_items：GAS UUID ＋ 種別 / 研修動画モジュールキー
ALTER TABLE training_items
  ADD COLUMN source_uid VARCHAR(40) DEFAULT '' AFTER id,
  ADD COLUMN type       VARCHAR(20) DEFAULT '' AFTER is_required, -- 研修 / テスト 等
  ADD COLUMN module_key VARCHAR(20) DEFAULT '' AFTER type,        -- Lesson(研修動画)との対応キー
  ADD KEY idx_ti_source (source_uid);

-- training_progress：GAS UUID ＋ エビデンス / 申告者
ALTER TABLE training_progress
  ADD COLUMN source_uid    VARCHAR(40)  DEFAULT '' AFTER id,
  ADD COLUMN evidence_file VARCHAR(255) DEFAULT '' AFTER memo,
  ADD COLUMN submitted_by  VARCHAR(100) DEFAULT '' AFTER evidence_file,
  ADD KEY idx_tp_source (source_uid);
-- ============================================================
