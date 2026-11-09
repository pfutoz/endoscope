-- ============================================================================
-- 生成日時: 2026-11-08 16:22 JST
-- 設置パス: c:\Apache24\htdocs\endoscope\tools\migration_20261108_endoscopy_sheet.sql
-- 概要: 計画⑥ 内視鏡シート（電子記録票）DB永続化テーブル (t_endoscopy_sheet) の新設
-- ============================================================================

CREATE TABLE IF NOT EXISTS t_endoscopy_sheet (
    schedule_id INT PRIMARY KEY,
    patient_id VARCHAR(50),
    exam_date DATE NOT NULL,
    bp_sys SMALLINT,
    bp_dia SMALLINT,
    exam_start_time VARCHAR(10),
    exam_end_time VARCHAR(10),
    wake_time VARCHAR(10),
    explain_time VARCHAR(10),
    post_destination VARCHAR(20),
    doctor_sign VARCHAR(64),
    is_ready_accounting BOOLEAN NOT NULL DEFAULT FALSE,
    is_billing_completed BOOLEAN NOT NULL DEFAULT FALSE,
    billing_completed_at TIMESTAMP,
    billing_completed_by VARCHAR(64),
    sheet_state_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by_ip VARCHAR(64)
);

CREATE INDEX IF NOT EXISTS idx_endoscopy_sheet_date
    ON t_endoscopy_sheet(exam_date);

CREATE INDEX IF NOT EXISTS idx_endoscopy_sheet_patient
    ON t_endoscopy_sheet(patient_id);

CREATE INDEX IF NOT EXISTS idx_endoscopy_sheet_accounting
    ON t_endoscopy_sheet(exam_date, is_ready_accounting, is_billing_completed);

