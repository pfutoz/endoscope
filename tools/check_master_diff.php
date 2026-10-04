<?php
/**
 * tools/check_master_diff.php
 * 生成日時: 2026-10-02 13:02
 * 修正履歴:
 *  - 2026-10-02: m_exam_type_group, m_input_master などのマスタ差分詳細確認スクリプト作成
 */

$environments = [
    'local' => ['dsn' => 'pgsql:host=localhost;port=5432;dbname=endoscope;', 'user' => 'postgres', 'pass' => 'postgres'],
    'prod'  => ['dsn' => 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'user' => 'postgres', 'pass' => 'postgres']
];

$pdoL = new PDO($environments['local']['dsn'], $environments['local']['user'], $environments['local']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdoP = new PDO($environments['prod']['dsn'], $environments['prod']['user'], $environments['prod']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

echo "=== m_exam_type_group の差分 ===" . PHP_EOL;
$lRows = $pdoL->query("SELECT * FROM m_exam_type_group ORDER BY id")->fetchAll();
$pRows = $pdoP->query("SELECT * FROM m_exam_type_group ORDER BY id")->fetchAll();

$lKeys = array_column($lRows, null, 'id');
$pKeys = array_column($pRows, null, 'id');

foreach ($lKeys as $id => $row) {
    if (!isset($pKeys[$id])) {
        echo "  [開発のみ] ID: {$id} - " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
foreach ($pKeys as $id => $row) {
    if (!isset($lKeys[$id])) {
        echo "  [本番のみ] ID: {$id} - " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

echo PHP_EOL . "=== m_input_master のスキーマと差分 ===" . PHP_EOL;
$cols = $pdoL->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'm_input_master'")->fetchAll(PDO::FETCH_COLUMN);
$pk = $cols[0]; // first column as identifier
$lRows = $pdoL->query("SELECT * FROM m_input_master")->fetchAll();
$pRows = $pdoP->query("SELECT * FROM m_input_master")->fetchAll();

$lKeys = array_column($lRows, null, $pk);
$pKeys = array_column($pRows, null, $pk);

foreach ($lKeys as $k => $row) {
    if (!isset($pKeys[$k])) {
        echo "  [開発のみ] {$pk}={$k} - " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
foreach ($pKeys as $k => $row) {
    if (!isset($lKeys[$k])) {
        echo "  [本番のみ] {$pk}={$k} - " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

