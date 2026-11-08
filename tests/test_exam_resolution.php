<?php
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== Testing getExamTypeGroupMap() ===" . PHP_EOL;
$map = getExamTypeGroupMap($pdo);
print_r($map);

echo PHP_EOL . "=== Testing resolveExamGroup() ===" . PHP_EOL;
$test_cases = [
    'GIF' => 'GIF',
    '◆胃がん検診◆' => 'GIF',
    '胃がん検診' => 'GIF',
    'TCF' => 'TCF',
    'W' => 'TCF',
    'ポリペク' => 'TCF',
    '浣腸CF' => 'TCF',
    '浣腸F' => 'TCF',
    'エコー' => 'ECHO',
    'ECHO' => 'ECHO',
    '腹部エコー' => 'ECHO',
    '大腸ポリープ' => 'TCF',
    '経鼻GIF' => 'GIF',
    '心電図' => 'OTHER',
    '' => 'OTHER',
    null => 'OTHER',
];

$all_ok = true;
foreach ($test_cases as $input => $expected) {
    $actual = resolveExamGroup($input, $pdo);
    $badge = getExamTypeBadgeInfo($input, $pdo);
    $status = ($actual === $expected) ? 'PASS' : 'FAIL';
    if ($actual !== $expected) $all_ok = false;
    echo sprintf("[%s] Input: %-12s => Expected: %-6s, Actual: %-6s, Cls: %-6s\n", $status, (string)$input, $expected, $actual, $badge['cls']);
}

if ($all_ok) {
    echo PHP_EOL . "🎉 ALL TESTS PASSED!" . PHP_EOL;
} else {
    echo PHP_EOL . "❌ SOME TESTS FAILED!" . PHP_EOL;
    exit(1);
}
