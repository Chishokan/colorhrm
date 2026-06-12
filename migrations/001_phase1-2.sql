-- ============================================================
-- Color HRM (Xサーバー版) マイグレーション 001
-- フェーズ1（権限・マイページ）＋ フェーズ2（研修）
-- phpMyAdmin で対象DBを選び「SQL」タブに貼り付けて1回実行してください。
-- ※ MySQL は ADD COLUMN IF NOT EXISTS 非対応のため、再実行時に
--   「Duplicate column」が出た行はスキップ済みとして無視して構いません。
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- フェーズ1：権限・マイページ
-- ------------------------------------------------------------

-- users にログイン↔講師の紐付けを追加（teacher がマイページで自分の情報を見るため）
ALTER TABLE users
  ADD COLUMN staff_id INT NULL AFTER role,          -- 紐づく staff.id（admin/staff は NULL 可）
  ADD KEY idx_staff_id (staff_id);

-- staff を GAS版 Staff（16列）に寄せて不足列を追加
ALTER TABLE staff
  ADD COLUMN candidate_id        INT NULL        AFTER id,            -- 元の応募者ID（採用パイプライン由来）
  ADD COLUMN employment_type     VARCHAR(20)  DEFAULT '' AFTER school,-- アルバイト/社員:新卒/社員:中途
  ADD COLUMN hire_date           DATE NULL       AFTER employment_type,
  ADD COLUMN color_certified_date DATE NULL      AFTER color_rank,    -- 現カラーの認定日
  ADD COLUMN recruiting_media    VARCHAR(50)  DEFAULT '' AFTER mentor,-- 応募媒体（Candidatesから引継ぎ）
  ADD COLUMN referrer            VARCHAR(50)  DEFAULT '' AFTER recruiting_media,
  ADD COLUMN created_at          DATETIME DEFAULT CURRENT_TIMESTAMP AFTER is_active;

-- ------------------------------------------------------------
-- フェーズ2：研修
-- ------------------------------------------------------------

-- 研修マスター（部門 × 対象カラーごとの研修項目）
CREATE TABLE IF NOT EXISTS training_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL DEFAULT 1,
  department   VARCHAR(50)  NOT NULL DEFAULT '',     -- RED / 智翔館 / ネクスタ / 東進 等
  target_color VARCHAR(10)  NOT NULL DEFAULT 'GREEN',-- GREEN / BLUE / YELLOW / RED
  item_name    VARCHAR(150) NOT NULL,
  sort_order   INT NOT NULL DEFAULT 0,               -- 表示順（order は予約語のため sort_order）
  is_required  TINYINT NOT NULL DEFAULT 1,           -- 必須項目か（対象外設定が可能）
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_dept_color (department, target_color)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 研修進捗（講師 × 研修項目）
CREATE TABLE IF NOT EXISTS training_progress (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT NOT NULL DEFAULT 1,
  staff_id         INT NOT NULL,
  training_item_id INT NOT NULL,
  status           VARCHAR(20) NOT NULL DEFAULT '未着手', -- 未着手/受講済/合格/不合格/対象外
  completed_date   DATE NULL,
  memo             VARCHAR(255) DEFAULT '',              -- 点数・実施日など
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_staff (staff_id),
  KEY idx_item (training_item_id),
  UNIQUE KEY uq_staff_item (staff_id, training_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- メモ（フェーズ3：申告承認）
-- 自己申告＋承認フローは training_progress に下記を足して実装予定：
--   declared_by INT NULL / declared_at DATETIME NULL
--   approved_by INT NULL / approved_at DATETIME NULL
--   status に「申告中」「差戻し」を追加
-- ============================================================
