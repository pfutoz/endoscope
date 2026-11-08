<?php
$_GET = ['dept' => 'GASTRO', 'date' => '2026-10-26'];
ob_start();
include __DIR__ . '/../get_progress_data.php';
$json = ob_get_clean();

$data = json_decode($json, true);
if (json_last_error() === JSON_ERROR_NONE && ($data['success'] ?? false) === true) {
    echo "✓ get_progress_data.php returned valid JSON!" . PHP_EOL;
    echo "  Total patients: " . count($data['patients'] ?? []) . PHP_EOL;
    echo "  Summary: " . json_encode($data['summary'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo "✗ get_progress_data.php FAILED: " . substr($json, 0, 300) . PHP_EOL;
}
