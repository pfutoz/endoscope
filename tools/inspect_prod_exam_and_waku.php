<?php
/**
 * tools/inspect_prod_exam_and_waku.php
 */
$pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== 本番 m_exam_type_group 現状 ===" . PHP_EOL;
$stmt = $pdoProd->query("SELECT * FROM m_exam_type_group ORDER BY id ASC");
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo PHP_EOL . "=== ローカル m_exam_type_group 現状 ===" . PHP_EOL;
$pdoLocal = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$stmtLocal = $pdoLocal->query("SELECT * FROM m_exam_type_group ORDER BY is_selectable DESC, display_order ASC, id ASC");
foreach ($stmtLocal->fetchAll() as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

