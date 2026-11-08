<?php
$_GET = ['dept' => 'GASTRO', 'date' => '2026-10-26'];
ob_start();
include __DIR__ . '/../schedule_note.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;

if (strpos($html, 'exam-badge') !== false) {
    echo "✓ exam-badge found!" . PHP_EOL;
} else {
    echo "✗ exam-badge NOT found!" . PHP_EOL;
}

if (strpos($html, '＊＊＊🚫枠なし') !== false) {
    echo "✓ No-waku badge found!" . PHP_EOL;
}

// バッジクラスの抽出
preg_match_all('/<span class="exam-badge\s+([^"]+)">([^<]+)<\/span>/', $html, $matches, PREG_SET_ORDER);
echo "Found " . count($matches) . " exam-badges:" . PHP_EOL;
$counts = [];
foreach ($matches as $m) {
    $key = "class='{$m[1]}' text='{$m[2]}'";
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}
foreach ($counts as $k => $c) {
    echo "  - {$k}: {$c} 件" . PHP_EOL;
}
