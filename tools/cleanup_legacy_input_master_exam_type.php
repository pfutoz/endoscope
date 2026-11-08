<?php
/**
 * tools/cleanup_legacy_input_master_exam_type.php
 * m_input_master の exam_type レコードをバックアップテーブルに退避し、
 * m_input_master から削除して一本化を完了するスクリプト（ローカル専用）
 */

$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== 1. バックアップテーブルの作成と退避 ===" . PHP_EOL;
$pdo->exec("CREATE TABLE IF NOT EXISTS bak_m_input_master_exam_type AS SELECT * FROM m_input_master WHERE category = 'exam_type'");
$bak_count = $pdo->query("SELECT count(*) FROM bak_m_input_master_exam_type")->fetchColumn();
echo "  ✓ 退避完了（件数: {$bak_count} 件）" . PHP_EOL;

echo PHP_EOL . "=== 2. m_input_master から category='exam_type' の削除 ===" . PHP_EOL;
$del = $pdo->exec("DELETE FROM m_input_master WHERE category = 'exam_type'");
echo "  ✓ 削除完了（削除件数: {$del} 件）" . PHP_EOL;

$remaining = $pdo->query("SELECT count(*) FROM m_input_master WHERE category = 'exam_type'")->fetchColumn();
echo "  ✓ m_input_master の exam_type 残存件数: {$remaining} 件" . PHP_EOL;
