<?php
$_GET = ['dept' => 'GASTRO', 'start_date' => '2026-10-26', 'end_date' => '2026-10-30'];
ob_start();
include __DIR__ . '/../slot_search.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;
if (strpos($html, '空き枠') !== false) {
    echo "✓ Slot search rendered successfully!" . PHP_EOL;
}
