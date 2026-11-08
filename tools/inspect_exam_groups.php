<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== m_exam_type_group 列情報 ===" . PHP_EOL;
$stmt = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'm_exam_type_group' ORDER BY ordinal_position");
foreach ($stmt->fetchAll() as $col) {
    echo "{$col['column_name']} ({$col['data_type']}, nullable: {$col['is_nullable']})" . PHP_EOL;
}

echo PHP_EOL . "=== m_exam_type_group レコード ===" . PHP_EOL;
$stmt = $pdo->query("SELECT * FROM m_exam_type_group ORDER BY id");
foreach ($stmt->fetchAll() as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo PHP_EOL . "=== 過去の t_endoscopy_schedule に存在する実際の exam_type 一覧 ===" . PHP_EOL;
$stmt = $pdo->query("SELECT exam_type, count(*) as cnt FROM t_endoscopy_schedule WHERE exam_type IS NOT NULL AND exam_type != '' GROUP BY exam_type ORDER BY cnt DESC");
foreach ($stmt->fetchAll() as $row) {
    echo "exam_type: '{$row['exam_type']}' (件数: {$row['cnt']})" . PHP_EOL;
}
