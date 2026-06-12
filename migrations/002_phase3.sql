-- ============================================================
-- Color HRM (Xサーバー版) マイグレーション 002
-- フェーズ3：研修の自己申告 ＋ 承認フロー
-- phpMyAdmin で対象DBを選び「SQL」タブに貼り付けて1回実行してください。
-- ※ MySQL 5.7 は ADD COLUMN IF NOT EXISTS 非対応のため、再実行時に
--   「Duplicate column」が出た行はスキップ済みとして無視して構いません。
-- 前提：001_phase1-2.sql 適用済み（training_progress が存在すること）。
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- フェーズ3：申告承認
-- training_progress に自己申告（講師本人）と承認（管理者）の列を追加。
-- status は VARCHAR のまま「申告中」「差戻し」を運用上の値として追加で扱う。
--   未着手 / 申告中 / 受講済 / 合格 / 不合格 / 差戻し / 対象外
-- フロー例：
--   講師が申告 → status='申告中', declared_by/at をセット
--   管理者が承認 → status='合格'(等), approved_by/at をセット
--   管理者が差戻し → status='差戻し', approved_by/at をセット（再申告で上書き）
-- ------------------------------------------------------------
ALTER TABLE training_progress
  ADD COLUMN declared_by INT NULL      AFTER memo,        -- 申告した講師（users.id or staff.id 運用に合わせる）
  ADD COLUMN declared_at DATETIME NULL AFTER declared_by, -- 申告日時
  ADD COLUMN approved_by INT NULL      AFTER declared_at, -- 承認/差戻しした管理者（users.id）
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by, -- 承認/差戻し日時
  ADD KEY idx_declared_by (declared_by),
  ADD KEY idx_approved_by (approved_by);

-- ============================================================
-- メモ（次フェーズ）
--   フェーズ4：採用（candidates テーブル＋OCR代替）
--   フェーズ5：給与連携（payroll-app）
--   フェーズ6：データ移行（ColorHRM_DB 5シート → MySQL）
-- ============================================================
