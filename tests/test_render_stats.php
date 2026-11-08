<?php
$_GET = ['dept' => 'GASTRO', 'start_date' => '2026-10-01', 'end_date' => '2026-10-26'];
ob_start();
include __DIR__ . '/../stats.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;
if (strpos($html, '集計') !== false) {
    echo "✓ stats.php rendered successfully!" . PHP_EOL;
}
