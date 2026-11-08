<?php
$_GET = ['dept' => 'GASTRO', 'date' => '2026-10-26', 'waku' => '10:00(GIF)'];
ob_start();
include __DIR__ . '/../modal_edit.php';
$html = ob_get_clean();

preg_match('/<select[^>]*id="exam_type"[^>]*>(.*?)<\/select>/s', $html, $m);
if (empty($m[1])) {
    echo "✗ exam_type select not found!" . PHP_EOL;
    exit(1);
}

preg_match_all('/<option\s+value="([^"]*)"([^>]*)>([^<]*)<\/option>/', $m[1], $opts, PREG_SET_ORDER);
echo "=== modal_edit.php exam_type select options (" . count($opts) . " options) ===" . PHP_EOL;
foreach ($opts as $opt) {
    $sel = (strpos($opt[2], 'selected') !== false) ? ' [SELECTED]' : '';
    echo "  - value: '{$opt[1]}', label: '{$opt[3]}'{$sel}" . PHP_EOL;
}
