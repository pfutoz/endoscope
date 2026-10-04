-- ==============================================================================
-- 本番環境 (192.168.1.16:5432/endoscope) 適用用 マイグレーションスクリプト
-- 実行日時: 2026-10-04
-- 対象:
--   1. t_endoscopy_schedule に is_admission カラム及びインデックス追加
--   2. 旧レガシーテーブル（m_doctor, m_doctor_attendance, m_doctor_slot_pattern, m_waku_master）の削除
-- ==============================================================================

BEGIN;

-- 1. 病棟管理システム連動用 入院予定フラグ (is_admission) カラム追加
ALTER TABLE t_endoscopy_schedule ADD COLUMN IF NOT EXISTS is_admission BOOLEAN DEFAULT NULL;

-- 2. 検索高速化インデックス作成
CREATE INDEX IF NOT EXISTS idx_endo_is_admission ON t_endoscopy_schedule(exam_date, is_admission);

-- 3. 旧レガシーテーブルの削除（2026-09-28のm2系マスタ移行により不要化済みの4テーブル）
DROP TABLE IF EXISTS m_doctor_slot_pattern CASCADE;
DROP TABLE IF EXISTS m_doctor_attendance CASCADE;
DROP TABLE IF EXISTS m_doctor CASCADE;
DROP TABLE IF EXISTS m_waku_master CASCADE;

COMMIT;
