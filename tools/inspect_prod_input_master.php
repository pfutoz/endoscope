<?php
/**
 * tools/inspect_prod_input_master.php
 */
$pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== 本番 m_input_master 内の category 分布 ===" . PHP_EOL;
$stmt = $pdoProd->query("
    SELECT category, count(*) as cnt 
    FROM m_input_master 
    GROUP BY category 
    ORDER BY category ASC
");
foreach ($stmt->fetchAll() as $r) {
    echo "  - category: '{$r['category']}' ({$r['cnt']} 件)" . PHP_EOL;
}
echo PHP_EOL . "=== ローカル m_input_master 内の category 分布 ===" . PHP_EOL;
$pdoLocal = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$stmtLocal = $pdoLocal->query("
    SELECT category, count(*) as cnt 
    FROM m_input_master 
    GROUP BY category 
    ORDER BY category ASC
");
echo PHP_EOL . "=== ローカル system_config / waku_suspend_reason ===" . PHP_EOL;
$rows = $pdoLocal->query("SELECT * FROM m_input_master WHERE category IN ('system_config', 'waku_suspend_reason') ORDER BY category, display_order")->fetchAll();
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

