<?php
/**
 * tools/migrate_doctor_notification.php
 * 医師向け通知・消込管理テーブル作成マイグレーション
 */
require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo "DB接続失敗\n";
    exit(1);
}

try {
    $sql = "
    -- 1. 医師別通知個別消込テーブル
    CREATE TABLE IF NOT EXISTS t_doctor_notification_read (
        read_id BIGSERIAL PRIMARY KEY,
        log_id BIGINT NOT NULL,
        doctor_name VARCHAR(100) NOT NULL,
        read_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT uq_doctor_notif_read UNIQUE (log_id, doctor_name)
    );

    CREATE INDEX IF NOT EXISTS idx_doc_notif_read_lookup ON t_doctor_notification_read(doctor_name, log_id);
    CREATE INDEX IF NOT EXISTS idx_doc_notif_read_at ON t_doctor_notification_read(read_at);

    -- 2. 医師別一括消込ステータステーブル（カーソル方式：1医師1レコード）
    CREATE TABLE IF NOT EXISTS t_doctor_notification_status (
        doctor_name VARCHAR(100) PRIMARY KEY,
        last_cleared_log_id BIGINT DEFAULT 0,
        last_cleared_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
    );
    ";

    $pdo->exec($sql);
    echo "✓ 医師向け通知テーブル (t_doctor_notification_read, t_doctor_notification_status) の作成・確認に成功しました。\n";
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
