<?php
require_once __DIR__ . '/../lib/db.php';
$pdoP = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

echo "=== 本番 m_exam_type_group のカラム ===\n";
$cols = $pdoP->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'm_exam_type_group'")->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);

echo "\n=== 本番 m_exam_type_group のデータ ===\n";
$rows = $pdoP->query("SELECT * FROM m_exam_type_group")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  - " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== 本番 m_input_master の exam_type ===\n";
$ex = $pdoP->query("SELECT * FROM m_input_master WHERE category = 'exam_type'")->fetchAll(PDO::FETCH_ASSOC);
echo "  件数: " . count($ex) . " 件\n";

echo "\n=== 本番 m_input_master の doctor ===\n";
$doc = $pdoP->query("SELECT * FROM m_input_master WHERE category = 'doctor'")->fetchAll(PDO::FETCH_ASSOC);
echo "  件数: " . count($doc) . " 件\n";
