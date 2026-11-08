<?php
$_GET = ['dept' => 'GASTRO', 'date' => '2026-10-26', 'waku' => '10:00(GIF)'];
ob_start();
include __DIR__ . '/../modal_edit.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;
if (strpos($html, 'modal-header') !== false || strpos($html, 'main-card') !== false) {
    echo "✓ modal_edit.php rendered successfully!" . PHP_EOL;
}
