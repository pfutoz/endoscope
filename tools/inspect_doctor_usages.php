<?php
require_once __DIR__ . '/../lib/db.php';
$pdo = getDbConnection();

echo "=== 1. m_input_master の category = 'doctor' ===\n";
$rows = $pdo->query("SELECT master_id, category, item_value, display_order FROM m_input_master WHERE category = 'doctor' ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo sprintf("ID: %3d | 医師名: %-10s | 順: %d\n", $r['master_id'], $r['item_value'], $r['display_order']);
}

echo "\n=== 2. m2_doctor のデータ ===\n";
$rows2 = $pdo->query("SELECT id, doctor_name, department_code, display_order, is_active FROM m2_doctor ORDER BY department_code, display_order")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) {
    echo sprintf("ID: %3d | 医師名: %-10s | 科: %-6s | 順: %d | 有効: %s\n", $r['id'], $r['doctor_name'], $r['department_code'], $r['display_order'], $r['is_active'] ? 'TRUE' : 'FALSE');
}

echo "\n=== 3. t_endoscopy_schedule に登録されている過去の doctor_name 一覧 ===\n";
$rows3 = $pdo->query("SELECT doctor_name, count(*) as cnt FROM t_endoscopy_schedule GROUP BY doctor_name ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows3 as $r) {
    echo sprintf("医師名: %-12s | 予約件数: %d 件\n", $r['doctor_name'] ?? '(NULL)', $r['cnt']);
}
