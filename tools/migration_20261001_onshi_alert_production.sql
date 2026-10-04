-- tools/migration_20261001_onshi_alert_production.sql
-- 本番環境 (192.168.1.16:5432/endoscope) 適用用 マイグレーションスクリプト
-- 実行内容: オンライン資格確認（薬剤情報・リスク患者判定API）連携に伴う3大リスクカラムの追加

BEGIN;

-- 1. t_patient_medical_alert に新設3カラムを追加 (IF NOT EXISTS で安全に追加)
ALTER TABLE t_patient_medical_alert 
    ADD COLUMN IF NOT EXISTS has_diabetes BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS has_psychotropic BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS has_nsaids BOOLEAN NOT NULL DEFAULT FALSE;

-- 2. カラムコメントの付与
COMMENT ON COLUMN t_patient_medical_alert.has_diabetes IS '💉 糖尿病薬（低血糖・絶食時注意）';
COMMENT ON COLUMN t_patient_medical_alert.has_psychotropic IS '🧠 向精神薬・睡眠薬（鎮静剤相互作用・呼吸抑制注意）';
COMMENT ON COLUMN t_patient_medical_alert.has_nsaids IS '💊 NSAIDs消炎鎮痛薬（潰瘍・出血リスク注意）';

COMMIT;

-- 検証用クエリ: カラムが正常に追加されたか確認
SELECT column_name, data_type, column_default, is_nullable
FROM information_schema.columns
WHERE table_name = 't_patient_medical_alert'
  AND column_name IN ('has_antithrombotic', 'has_diabetes', 'has_psychotropic', 'has_nsaids', 'has_allergy', 'has_infection', 'has_cold_fever')
ORDER BY ordinal_position;
