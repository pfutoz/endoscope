<?php
require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo "DB接続失敗\n";
    exit(1);
}

try {
    $sql = "
        ALTER TABLE t_patient_medical_alert 
        ADD COLUMN IF NOT EXISTS has_diabetes BOOLEAN NOT NULL DEFAULT FALSE,
        ADD COLUMN IF NOT EXISTS has_psychotropic BOOLEAN NOT NULL DEFAULT FALSE,
        ADD COLUMN IF NOT EXISTS has_nsaids BOOLEAN NOT NULL DEFAULT FALSE;
    ";
    $pdo->exec($sql);
    echo "t_patient_medical_alert テーブルへのカラム追加に成功しました (has_diabetes, has_psychotropic, has_nsaids).\n";
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
