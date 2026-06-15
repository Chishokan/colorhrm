-- ============================================================
-- Color HRM 研修マスター 初期データ（たたき台）
-- training_items に「対象カラー別の標準研修項目（共通：部門＝空）」を投入。
--
-- 使い方：phpMyAdmin で対象DBを選び、このSQLを1回実行。
--   ※ 既に training_items に tenant_id=1 のデータがある場合は **何も挿入しません**
--     （重複防止のガード付き。再実行しても安全）。
--   ※ 投入後は「研修マスター」画面で自由に編集・部門別項目の追加が可能です。
--
-- カラー体系：WHITE導入 → GREEN講師入門 → BLUE初級 → YELLOW中級 → RED上級
-- ============================================================
SET NAMES utf8mb4;

INSERT INTO training_items (tenant_id, department, target_color, item_name, sort_order, is_required)
SELECT t.* FROM (
  -- GREEN（講師入門・基礎研修）
  SELECT 1 AS tenant_id, '' AS department, 'GREEN' AS target_color, '企業理念・コンプライアンス研修' AS item_name, 1 AS sort_order, 1 AS is_required
  UNION ALL SELECT 1,'','GREEN','就業ルール・勤怠・報連相',2,1
  UNION ALL SELECT 1,'','GREEN','教室マナー・生徒対応の基本',3,1
  UNION ALL SELECT 1,'','GREEN','授業サポート実務（巡回・見守り）',4,1
  UNION ALL SELECT 1,'','GREEN','安全衛生・緊急時対応',5,1
  -- BLUE（初級認定・演習型授業）
  UNION ALL SELECT 1,'','BLUE','演習型授業の進め方',1,1
  UNION ALL SELECT 1,'','BLUE','採点・添削の基準',2,1
  UNION ALL SELECT 1,'','BLUE','生徒の質問対応スキル',3,1
  UNION ALL SELECT 1,'','BLUE','学習進捗の記録・管理',4,1
  UNION ALL SELECT 1,'','BLUE','保護者連絡の基本',5,1
  -- YELLOW（中級認定・講義型授業／面談）
  UNION ALL SELECT 1,'','YELLOW','講義型授業の設計・板書',1,1
  UNION ALL SELECT 1,'','YELLOW','学習面談の進め方',2,1
  UNION ALL SELECT 1,'','YELLOW','成績分析と指導計画',3,1
  UNION ALL SELECT 1,'','YELLOW','クレーム一次対応',4,1
  UNION ALL SELECT 1,'','YELLOW','教材研究・小テスト作成',5,1
  -- RED（上級認定・上位クラス／後輩育成）
  UNION ALL SELECT 1,'','RED','上位クラスの指導法',1,1
  UNION ALL SELECT 1,'','RED','後輩講師のメンタリング',2,1
  UNION ALL SELECT 1,'','RED','研修の実施・評価',3,1
  UNION ALL SELECT 1,'','RED','教室運営の補佐',4,1
  UNION ALL SELECT 1,'','RED','保護者面談（進路・受験）',5,1
) AS t
WHERE NOT EXISTS (SELECT 1 FROM training_items WHERE tenant_id = 1);
-- ============================================================
