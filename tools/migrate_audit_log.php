<?php
/**
 * migrate_audit_log.php
 * t_schedule_audit_log テーブル作成スクリプト
 */
require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo "DB接続失敗\n";
    exit(1);
}

try {
    $sql = "
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
    ";

    $pdo->exec($sql);
    echo "t_schedule_audit_log テーブルの作成・確認に成功しました。\n";
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
