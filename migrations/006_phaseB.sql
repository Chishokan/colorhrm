-- ============================================================
-- Color HRM マイグレーション 006
-- フェーズB：面談(1on1) ＋ 質問/回答
-- phpMyAdmin で1回実行。CREATE TABLE IF NOT EXISTS なので再実行も安全。
-- ============================================================
SET NAMES utf8mb4;

-- 1on1 面談記録（講師 × 面談日）
CREATE TABLE IF NOT EXISTS meetings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL DEFAULT 1,
  staff_id     INT NOT NULL,
  meeting_date DATE NULL,
  mentor_name  VARCHAR(100) DEFAULT '',
  content      TEXT,
  next_date    DATE NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 管理者→講師への質問（target_staff_id が NULL/0 なら全講師向け）
CREATE TABLE IF NOT EXISTS questions (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL DEFAULT 1,
  text            VARCHAR(500) NOT NULL,
  target_staff_id INT NULL,
  is_active       TINYINT NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_target (target_staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 回答（質問 × 講師）
CREATE TABLE IF NOT EXISTS answers (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL DEFAULT 1,
  question_id INT NOT NULL,
  staff_id    INT NOT NULL,
  answer      TEXT,
  answered_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_question (question_id),
  KEY idx_staff (staff_id),
  UNIQUE KEY uq_q_staff (question_id, staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
