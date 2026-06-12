-- ============================================================
-- Color HRM (Xサーバー版) マイグレーション 003【ドラフト・未適用】
-- フェーズ4：採用（candidates）
-- ※ 設計レビュー前提のたたき台。確定後に phpMyAdmin で1回だけ実行する。
-- ※ GAS版 設計仕様書 Candidates（28列）に対応。列名は snake_case に統一。
--   GAS は UUID 文字列だが、本PoCは INT 主キー運用のため id は INT AUTO_INCREMENT。
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS candidates (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id               INT NOT NULL DEFAULT 1,
  no                      INT NOT NULL DEFAULT 0,            -- 通し番号（既存「NO」列）
  applied_month           INT NULL,                          -- 応募月
  applied_day             INT NULL,                          -- 応募日
  name                    VARCHAR(100) NOT NULL DEFAULT '',
  age                     INT NULL,
  note                    VARCHAR(255) DEFAULT '',           -- 備考（学校名など）
  assignee                VARCHAR(50)  DEFAULT '',           -- 担当者名
  employment_type         VARCHAR(20)  DEFAULT '',           -- アルバイト/社員:新卒/社員:中途
  department              VARCHAR(50)  DEFAULT '',           -- RED/智翔館/ネクスタ/東進/CH/LEC/プリンス 等
  school                  VARCHAR(100) DEFAULT '',           -- 校舎
  job_type                VARCHAR(50)  DEFAULT '',           -- 職種
  recruiting_media        VARCHAR(50)  DEFAULT '',           -- 求人媒体
  referrer                VARCHAR(50)  DEFAULT '',           -- 紹介者
  referral_reward_paid    TINYINT NOT NULL DEFAULT 0,        -- 紹介謝礼配布済み
  special_recruiting      TINYINT NOT NULL DEFAULT 0,        -- 企画求人
  interview_date          DATE NULL,                         -- 面接日
  selection_result        VARCHAR(20)  DEFAULT '',           -- 採用/不採用(書類)/不採用(面接後)/辞退(面接前)/辞退(面接後)/お断り/音信不通/その他
  hire_date               DATE NULL,                         -- 入社日
  three_month_check_date  DATE NULL,                         -- 3か月継続判断日
  continued               VARCHAR(4)   DEFAULT '',           -- 継続（〇/✕）
  continuation_reward_paid TINYINT NOT NULL DEFAULT 0,       -- 継続謝礼配布済み
  initial_response        TINYINT NOT NULL DEFAULT 0,        -- 初期対応済み
  resume_file             VARCHAR(255) DEFAULT '',           -- 履歴書画像の保存パス（GAS の resumeFileId 相当）
  ocr_extracted           TINYINT NOT NULL DEFAULT 0,        -- OCR処理済み
  converted_to_staff      TINYINT NOT NULL DEFAULT 0,        -- 講師登録済み
  created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_dept (department),
  KEY idx_result (selection_result),
  KEY idx_assignee (assignee),
  KEY idx_no (no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 採用決定 → 講師化（convert_to_staff）は staff 側の既存列を再利用：
--   staff.candidate_id / employment_type / hire_date / recruiting_media / referrer
--   は フェーズ1（001）で追加済み。新規ALTERは不要。
-- ============================================================
