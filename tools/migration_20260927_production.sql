-- ============================================================
-- 内視鏡予約管理システム 本番DB更新マイグレーション
-- 適用日: 2026-09-27
-- 対象: 予約変更履歴(Audit Log) ＆ 医療安全アラート テーブル追加
-- ============================================================

BEGIN;

-- 1. 予約変更・誤削除監査ログテーブル (t_schedule_audit_log)
CREATE TABLE IF NOT EXISTS t_schedule_audit_log (
    log_id BIGSERIAL PRIMARY KEY,
    schedule_id INTEGER NOT NULL,
    action_type VARCHAR(30) NOT NULL, -- 'move', 'delete', 'update', 'insert', 'restore', 'undo'
    department_code VARCHAR(20),
    patient_id VARCHAR(50),
    patient_name VARCHAR(100),
    patient_kana VARCHAR(100),
    old_exam_date DATE,
    old_scheduled_time VARCHAR(50),
    old_waku_no INTEGER,
    new_exam_date DATE,
    new_scheduled_time VARCHAR(50),
    new_waku_no INTEGER,
    old_data JSONB,
    new_data JSONB,
    diff_summary TEXT,
    client_ip VARCHAR(50),
    client_ua TEXT,
    operator_name VARCHAR(100),
    description TEXT,
    is_undone BOOLEAN DEFAULT FALSE,
    undone_at TIMESTAMP WITHOUT TIME ZONE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_log_schedule_id ON t_schedule_audit_log(schedule_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON t_schedule_audit_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_log_patient_id ON t_schedule_audit_log(patient_id);

-- 2. 患者医療安全アラート管理テーブル (t_patient_medical_alert)
CREATE TABLE IF NOT EXISTS t_patient_medical_alert (
    alert_id BIGSERIAL PRIMARY KEY,
    schedule_id INTEGER NOT NULL UNIQUE,
    patient_id VARCHAR(50),
    patient_name VARCHAR(100),
    exam_date DATE NOT NULL,
    department_code VARCHAR(20),
    has_antithrombotic BOOLEAN NOT NULL DEFAULT FALSE, -- 💊 抗血栓薬（休薬確認要）
    has_allergy BOOLEAN NOT NULL DEFAULT FALSE,        -- ⚠️ アレルギー注意（局麻・薬剤）
    has_infection BOOLEAN NOT NULL DEFAULT FALSE,      -- 🔴 感染症注意（肝炎・梅毒等）
    has_cold_fever BOOLEAN NOT NULL DEFAULT FALSE,     -- 😷 風邪症状・発熱（有症状）
    antithrombotic_verified BOOLEAN NOT NULL DEFAULT FALSE, -- 当日休薬確認済フラグ
    detection_sources JSONB,                            -- 検出根拠（文言・メモ種別・タグなど）
    manual_overridden BOOLEAN NOT NULL DEFAULT FALSE,  -- スタッフによる手動変更フラグ
    verified_by VARCHAR(100),                          -- 確認・決定スタッフ名
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_medical_alert_sched ON t_patient_medical_alert(schedule_id);
CREATE INDEX IF NOT EXISTS idx_medical_alert_pid ON t_patient_medical_alert(patient_id);
CREATE INDEX IF NOT EXISTS idx_medical_alert_date ON t_patient_medical_alert(exam_date);

COMMIT;
