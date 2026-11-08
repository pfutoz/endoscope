<?php
$_GET = ['dept' => 'GASTRO', 'date' => '2026-10-26'];
ob_start();
include __DIR__ . '/../print_schedule_daily.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;

if (strpos($html, 'badge-exam') !== false || strpos($html, 'class="exam') !== false || strpos($html, 'gif') !== false) {
    echo "✓ Exam badges rendered!" . PHP_EOL;
}
