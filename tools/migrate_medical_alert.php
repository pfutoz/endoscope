<?php
/**
 * tools/migrate_medical_alert.php
 * t_patient_medical_alert テーブル作成マイグレーション
 */
require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo "DB接続失敗\n";
    exit(1);
}

try {
    $sql = "
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
    ";

    $pdo->exec($sql);
    echo "t_patient_medical_alert テーブル作成・確認に成功しました。\n";
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
