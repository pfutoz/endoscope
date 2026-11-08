<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== m_input_master (category='exam_type') ===" . PHP_EOL;
$stmt = $pdo->query("SELECT * FROM m_input_master WHERE category = 'exam_type' ORDER BY display_order, master_id");
$input_items = $stmt->fetchAll();
foreach ($input_items as $r) {
    echo sprintf("ID:%-4d | Item: %-14s | Order: %-4d\n", $r['master_id'], $r['item_value'], $r['display_order']);
}

echo PHP_EOL . "=== m_exam_type_group ===" . PHP_EOL;
$stmt = $pdo->query("SELECT * FROM m_exam_type_group ORDER BY id");
$group_items = $stmt->fetchAll();
foreach ($group_items as $r) {
    $disp = isset($r['display_order']) ? $r['display_order'] : 'NULL';
    echo sprintf("ID:%-4d | ExamType: %-14s | SlotGroup: %-6s | Active: %-5s | Order: %s\n", 
        $r['id'], $r['exam_type'], $r['slot_group'], ($r['is_active'] ? 'true' : 'false'), $disp);
}

echo PHP_EOL . "=== t_endoscopy_schedule で過去1年間に使われた exam_type 一覧 ===" . PHP_EOL;
$stmt = $pdo->query("
    SELECT exam_type, count(*) as cnt, min(exam_date) as min_date, max(exam_date) as max_date 
    FROM t_endoscopy_schedule 
    WHERE exam_type IS NOT NULL AND exam_type != '' 
    GROUP BY exam_type 
    ORDER BY cnt DESC
");
foreach ($stmt->fetchAll() as $r) {
    echo sprintf("ExamType: %-14s | Count: %-5d | Period: %s ~ %s\n", $r['exam_type'], $r['cnt'], $r['min_date'], $r['max_date']);
}
