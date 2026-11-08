<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== m_input_master categories ===" . PHP_EOL;
$stmt = $pdo->query("SELECT category, count(*) as cnt FROM m_input_master GROUP BY category ORDER BY category");
while ($r = $stmt->fetch()) {
    echo "Category: {$r['category']} (count: {$r['cnt']})" . PHP_EOL;
}

echo PHP_EOL . "=== m_input_master items where category = 'exam_type' ===" . PHP_EOL;
$stmt = $pdo->query("SELECT * FROM m_input_master WHERE category = 'exam_type' ORDER BY display_order, master_id");
$rows = $stmt->fetchAll();
if (empty($rows)) {
    echo "No records found for category = 'exam_type'" . PHP_EOL;
} else {
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

echo PHP_EOL . "=== All categories with 'exam' in category name ===" . PHP_EOL;
$stmt = $pdo->query("SELECT * FROM m_input_master WHERE category LIKE '%exam%' ORDER BY category, display_order");
$rows = $stmt->fetchAll();
foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo PHP_EOL . "=== All tables in public schema ===" . PHP_EOL;
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name");
while ($r = $stmt->fetch()) {
    echo $r['table_name'] . PHP_EOL;
}
