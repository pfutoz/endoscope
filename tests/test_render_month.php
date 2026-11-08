<?php
$_GET = ['dept' => 'GASTRO', 'year' => '2026', 'month' => '10', 'date' => '2026-10-26'];
ob_start();
include __DIR__ . '/../month_schedule.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;

if (strpos($html, 'exam-badge') !== false) {
    echo "✓ exam-badge found in month_schedule.php!" . PHP_EOL;
}
if (strpos($html, '＊＊＊🚫枠なし') !== false) {
    echo "✓ No-waku badge found in month_schedule.php!" . PHP_EOL;
}
