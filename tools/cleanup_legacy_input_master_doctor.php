<?php
/**
 * m_input_master の doctor レコードをバックアップテーブルに退避し、
 * m_input_master から削除して m2_doctor への一本化を完了するスクリプト（ローカル専用）
 */
require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("DB接続失敗\n");
}

echo "=== 1. バックアップテーブル bak_m_input_master_doctor の作成と退避 ===\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS bak_m_input_master_doctor AS SELECT * FROM m_input_master WHERE category = 'doctor'");
$bak_count = $pdo->query("SELECT count(*) FROM bak_m_input_master_doctor")->fetchColumn();
echo "  ✓ バックアップテーブル件数: {$bak_count} 件\n";

$rows = $pdo->query("SELECT * FROM bak_m_input_master_doctor ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "    - [ID: {$r['master_id']}] {$r['item_value']} (順: {$r['display_order']})\n";
}

echo "\n=== 2. m_input_master から category='doctor' の削除 ===\n";
$del = $pdo->exec("DELETE FROM m_input_master WHERE category = 'doctor'");
echo "  ✓ 削除実行件数: {$del} 件\n";

$remaining = $pdo->query("SELECT count(*) FROM m_input_master WHERE category = 'doctor'")->fetchColumn();
echo "  ✓ m_input_master の doctor 残存件数: {$remaining} 件\n";

echo "\n=== 3. 現行 m2_doctor の確認 ===\n";
$m2_rows = $pdo->query("SELECT doctor_name, department_code, display_order, is_active FROM m2_doctor ORDER BY department_code, display_order")->fetchAll(PDO::FETCH_ASSOC);
echo "  ✓ m2_doctor 登録医師数: " . count($m2_rows) . " 名\n";
foreach ($m2_rows as $m) {
    echo "    - {$m['doctor_name']} ({$m['department_code']}, 順: {$m['display_order']}, 有効: " . ($m['is_active'] ? 'YES' : 'NO') . ")\n";
}

echo "\n🎉 医師マスタの一本化（m_input_master 削除）が安全に完了しました。\n";
