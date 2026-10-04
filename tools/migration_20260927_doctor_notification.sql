-- ============================================================
-- 内視鏡予約管理システム 本番DB更新マイグレーション
-- 適用日: 2026-09-27
-- 対象: 医師向け予約変更通知 ＆ 消込管理テーブル
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS t_doctor_notification_read (
    read_id BIGSERIAL PRIMARY KEY,
    log_id BIGINT NOT NULL,
    doctor_name VARCHAR(100) NOT NULL,
    read_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_doctor_notif_read UNIQUE (log_id, doctor_name)
);

CREATE INDEX IF NOT EXISTS idx_doc_notif_read_lookup ON t_doctor_notification_read(doctor_name, log_id);
CREATE INDEX IF NOT EXISTS idx_doc_notif_read_at ON t_doctor_notification_read(read_at);

CREATE TABLE IF NOT EXISTS t_doctor_notification_status (
    doctor_name VARCHAR(100) PRIMARY KEY,
    last_cleared_log_id BIGINT DEFAULT 0,
    last_cleared_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
